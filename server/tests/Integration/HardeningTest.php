<?php

namespace Alvora\ErpnextLink\Tests\Integration;

use Alvora\ErpnextLink\Jobs\RunErpnextConnection;
use Alvora\ErpnextLink\Models\ErpnextConnection;
use Alvora\ErpnextLink\Support\Handoff;
use Alvora\ErpnextLink\Support\OutboundGuard;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\WebhookEndpoint;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fix round 1 for slice 001: the guards that deleting would break no other test (stale run, post-save check,
 * hand-off failures, step-5 paths), the host lock rule (DEC-035) and the failure-keeps-the-old-site rule.
 */
class HardeningTest extends IntegrationTestCase
{
    private function statusOf($company, $token): array
    {
        return $this->withToken($token)->getJson($this->path($company))->json();
    }

    // ---- the host lock: only a live key or a run in progress holds a site (Surbhi, 19 Nov 2026) ----

    public function testAFailedAttemptHoldsNoSiteSoTheRealOwnerCanReconnect(): void
    {
        [$a]       = $this->makeClient('A');
        [$b]       = $this->makeClient('B');
        [, $token] = $this->makeStaff();
        $this->connect($a, $token);

        $this->connect($b, $token); // B typed A's site by mistake
        $this->assertSame('host_in_use', ErpnextConnection::forCompany($b->uuid)->error_code);

        $this->connect($a, $token); // A rotates its key: B's failed row must not stand in the way
        $this->assertSame('connected', ErpnextConnection::forCompany($a->uuid)->status);
        $this->assertSame(1, DB::table('api_credentials')->where('company_uuid', $a->uuid)->whereNull('deleted_at')->count());
    }

    public function testAFailedAttemptAtASiteAnotherClientCanStillTakeIt(): void
    {
        [$b]                                = $this->makeClient('B');
        [$c]                                = $this->makeClient('C');
        [, $token]                          = $this->makeStaff();
        $this->erpnext->failures['.verify'] = 401;
        $this->connect($b, $token);
        $this->assertSame('key_refused', ErpnextConnection::forCompany($b->uuid)->error_code);

        unset($this->erpnext->failures['.verify']);
        $this->connect($c, $token);
        $this->assertSame('connected', ErpnextConnection::forCompany($c->uuid)->status);
    }

    public function testTheSiteAClientIsStillConnectedToStaysLockedWhileItReplacesIt(): void
    {
        [$a]       = $this->makeClient('A');
        [$b]       = $this->makeClient('B');
        [, $token] = $this->makeStaff();
        $this->connect($a, $token);
        $rowA = ErpnextConnection::forCompany($a->uuid);
        $rowA->update(['host' => 'new-' . $this->host, 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 2]); // A is mid-replace to another site

        $this->assertTrue(ErpnextConnection::hostInUse($this->host, $b->uuid), 'A is still delivering to its old site');
        $this->assertTrue(ErpnextConnection::hostInUse(strtoupper($this->host), $b->uuid), 'case does not matter');
        $this->assertFalse(ErpnextConnection::hostInUse($this->host, $a->uuid), 'A never blocks itself');
        $this->assertFalse(ErpnextConnection::hostInUse('x' . $this->host, $b->uuid), 'only the exact site');

        $this->connect($b, $token); // staff typed A's old site for B
        $this->assertSame('host_in_use', ErpnextConnection::forCompany($b->uuid)->error_code);
    }

    public function testARunInProgressHoldsTheSiteButAStaleOneDoesNot(): void
    {
        [$b]    = $this->makeClient('B');
        [$c]    = $this->makeClient('C');
        $row    = ErpnextConnection::create(['company_uuid' => $b->uuid, 'host' => $this->host, 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 2]);
        $this->assertTrue(ErpnextConnection::hostInUse($this->host, $c->uuid), 'a fresh run holds it');
        $this->assertFalse(ErpnextConnection::hostInUse($this->host, $b->uuid), 'a client never blocks itself');

        DB::table('alvora_erpnext_connections')->where('uuid', $row->uuid)->update(['updated_at' => now()->subSeconds(ErpnextConnection::STALE_AFTER + 5)]);
        $this->assertFalse(ErpnextConnection::hostInUse($this->host, $c->uuid), 'a run nobody has touched for five minutes holds nothing');
    }

    public function testADisconnectedClientHoldsNoSite(): void
    {
        [$a]       = $this->makeClient('A');
        [$b]       = $this->makeClient('B');
        [, $token] = $this->makeStaff();
        $this->connect($a, $token);
        $this->withToken($token)->deleteJson($this->path($a))->assertOk();

        $this->connect($b, $token);
        $this->assertSame('connected', ErpnextConnection::forCompany($b->uuid)->status);
    }

    // ---- a failed Connect again to a new site still shows the site that works ----

    public function testAFailedReplaceToANewSiteStillShowsTheOldSite(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $new = 'new-' . $this->host;

        $this->erpnext->failures['.verify'] = 401;
        $this->connect($acme, $token, $this->connectBody('s', "https://$new"));

        $status = $this->statusOf($acme, $token);
        $this->assertSame(['attention', 'key_refused', $this->host, true], [$status['status'], $status['error_code'], $status['host'], $status['has_previous']]);
        $this->assertNotNull(ApiCredential::where('uuid', ErpnextConnection::forCompany($acme->uuid)->api_credential_uuid)->first(), 'the old key still works');

        // The audit entry still names the site that was tried, not the one that was put back.
        $entry = \Spatie\Activitylog\Models\Activity::where('company_id', $acme->uuid)->where('event', 'connect_failed')->latest()->first();
        $this->assertSame($new, $entry->properties['host']);
    }

    public function testAFailedFirstConnectKeepsTheSiteThatWasTyped(): void
    {
        [$acme]                             = $this->makeClient('Acme');
        [, $token]                          = $this->makeStaff();
        $this->erpnext->failures['.verify'] = 401;
        $this->connect($acme, $token);

        $this->assertSame($this->host, $this->statusOf($acme, $token)['host']);
    }

    // ---- a kill at the cut-over ----

    public function testAKillRightAfterThePromoteLeavesOneConsistentPairAndTheSweepRevokesTheParkedOldOne(): void
    {
        [$acme, $owner]  = $this->makeClient('Acme');
        [$old, $oldHook] = $this->makePair($acme, $owner);
        [$new, $newHook] = $this->makePair($acme, $owner);
        $row             = ErpnextConnection::create([
            'company_uuid'                => $acme->uuid, 'host' => $this->host, 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 5,
            'api_credential_uuid'         => $new->uuid, 'webhook_endpoint_uuid' => $newHook->uuid,
            'pending_api_credential_uuid' => $old->uuid, 'pending_webhook_endpoint_uuid' => $oldHook->uuid,
        ]);
        DB::table('alvora_erpnext_connections')->where('uuid', $row->uuid)->update(['updated_at' => now()->subSeconds(400)]);

        $this->assertTrue($row->fresh()->sweep());

        $row->refresh();
        $this->assertSame(['attention', 'interrupted', $new->uuid, null], [$row->status, $row->error_code, $row->api_credential_uuid, $row->pending_api_credential_uuid]);
        $this->assertNull(ApiCredential::where('uuid', $old->uuid)->first(), 'the old key is revoked');
        $this->assertNotNull(ApiCredential::where('uuid', $new->uuid)->first(), 'the new key, which the connector holds, is kept');
    }

    public function testAnInterruptedReplaceAtStepFourPutsTheOldSiteBack(): void
    {
        [$acme, $owner]  = $this->makeClient('Acme');
        [$old, $oldHook] = $this->makePair($acme, $owner);
        $oldHook->forceFill(['url' => "https://{$this->host}/x"])->save();
        $row = ErpnextConnection::create([
            'company_uuid'        => $acme->uuid, 'host' => 'new-' . $this->host, 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 4,
            'api_credential_uuid' => $old->uuid, 'webhook_endpoint_uuid' => $oldHook->uuid,
        ]);

        $this->assertTrue($row->sweep(true));

        $this->assertSame($this->host, $row->fresh()->host);
    }

    // ---- the superseded-run guard and the job's own end states ----

    public function testAStaleJobDoesNothingWhenANewerRunOwnsTheRow(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $row = ErpnextConnection::forCompany($acme->uuid);
        $row->update(['status' => 'connecting', 'run_uuid' => 'newer-run', 'run_kind' => 'test', 'step' => 5]);
        $sent = count($this->erpnext->requests);

        (new RunErpnextConnection($row->uuid, 'older-run', 'test'))->handle($this->app->make(OutboundGuard::class));

        $row->refresh();
        $this->assertSame(['connecting', 'newer-run', $sent], [$row->status, $row->run_uuid, count($this->erpnext->requests)]);

        // Also a run that already ended is not run again.
        $row->update(['status' => 'connected', 'run_uuid' => 'older-run']);
        (new RunErpnextConnection($row->uuid, 'older-run', 'test'))->handle($this->app->make(OutboundGuard::class));
        $this->assertSame($sent, count($this->erpnext->requests));
    }

    public function testAJobThatTimesOutOrIsKilledLeavesTheRowInterruptedAndOnlyForItsOwnRun(): void
    {
        [$acme]    = $this->makeClient('Acme');
        $row       = ErpnextConnection::create(['company_uuid' => $acme->uuid, 'host' => $this->host, 'status' => 'connecting', 'run_uuid' => 'run-a', 'run_kind' => 'connect', 'step' => 2]);

        (new RunErpnextConnection($row->uuid, 'run-b', 'connect'))->failed(new \RuntimeException('late'));
        $this->assertSame('connecting', $row->fresh()->status, 'another run\'s failure changes nothing');

        (new RunErpnextConnection($row->uuid, 'run-a', 'connect'))->failed(new \RuntimeException('killed'));
        $this->assertSame(['attention', 'interrupted'], [$row->fresh()->status, $row->fresh()->error_code]);
    }

    public function testRowsThatAreNotAsSpecifiedAfterSavingAreRefusedAndRolledBack(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        // Something (a stock observer, a later migration) turns the stored key into a test key: the post-save check must catch it.
        ApiCredential::saved(fn ($credential) => DB::table('api_credentials')->where('uuid', $credential->uuid)->update(['key' => 'flb_test_' . Str::random(24)]));

        $this->connect($acme, $token);

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame(['attention', 'key_create_failed', 3], [$row->status, $row->error_code, $row->step]);
        $this->assertSame(0, DB::table('api_credentials')->where('company_uuid', $acme->uuid)->count(), 'AC-29: nothing was left behind');
        $this->assertSame(0, $this->erpnext->count('.connect'), 'nothing was sent to ERPNext');
    }

    public function testAnExceptionAfterTheHandOverStillEndsInADefinedState(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->app['events']->listen('eloquent.updating: ' . ErpnextConnection::class, function ($row) {
            if ($row->status === 'connected') {
                throw new \RuntimeException('database went away');
            }
        });

        $this->connect($acme, $token);

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame('attention', $row->status);
        $this->assertSame('test_failed', $row->error_code);
    }

    // ---- the test route ----

    public function testTheTestRouteAnswers500AndPutsTheRowBackWhenTheQueueIsDown(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $this->app->instance(Dispatcher::class, new class {
            public function dispatch(): void
            {
                throw new \RuntimeException('queue down');
            }
        });

        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(500)->assertJsonPath('error', 'The test could not be started.');

        $this->assertSame('connected', ErpnextConnection::forCompany($acme->uuid)->status, 'the row is as it was, not stuck connecting');
    }

    public function testTheTestRouteRefusesWhileARunIsGoing(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        ErpnextConnection::forCompany($acme->uuid)->update(['status' => 'connecting', 'run_kind' => 'connect', 'step' => 2]);

        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(409);
    }

    public function testDisconnectOfAnOrganisationThatNeverConnectedIs404AndWhileARunIsGoingIs409(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->withToken($token)->deleteJson($this->path($acme))->assertStatus(404);

        ErpnextConnection::create(['company_uuid' => $acme->uuid, 'host' => $this->host, 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 2]);
        $this->withToken($token)->deleteJson($this->path($acme))->assertStatus(409);
    }

    // ---- step 5 failure paths ----

    public function testStepFiveWithoutItsWebhookOrWithATransportFailureIsATestFailure(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $row = ErpnextConnection::forCompany($acme->uuid);

        $this->erpnext->failures['.webhook'] = new ConnectException('timed out', new Request('POST', 'https://x.example.com'), null, ['errno' => CURLE_OPERATION_TIMEDOUT]);
        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(202);
        $this->assertSame(['attention', 'test_failed'], [$row->fresh()->status, $row->fresh()->error_code], 'a transport failure');

        unset($this->erpnext->failures['.webhook']);
        WebhookEndpoint::where('uuid', $row->webhook_endpoint_uuid)->first()->delete();
        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(202);
        $this->assertSame('test_failed', $row->fresh()->error_code, 'the webhook is gone');
    }

    public function testAStepFiveAddressThatNowResolvesToAPrivateAddressIsBlocked(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $this->app->instance(OutboundGuard::class, new OutboundGuard(fn () => ['10.0.0.5'], fn () => $this->erpnext->client()));
        $sent = count($this->erpnext->requests);

        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(202);

        $this->assertSame('address_blocked', ErpnextConnection::forCompany($acme->uuid)->error_code);
        $this->assertSame($sent, count($this->erpnext->requests), 'nothing was sent');
    }

    public function testATestOnARowThatHasAKeyButIsNotConnectedOrInNeedOfAttentionIsRefusedNotRun(): void
    {
        [$acme, $owner]         = $this->makeClient('Acme');
        [, $token]              = $this->makeStaff();
        [$credential, $webhook] = $this->makePair($acme, $owner);
        ErpnextConnection::create(['company_uuid' => $acme->uuid, 'host' => $this->host, 'status' => 'disconnected', 'api_credential_uuid' => $credential->uuid, 'webhook_endpoint_uuid' => $webhook->uuid]);

        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(409);
        $this->assertSame(0, count($this->erpnext->requests));
    }

    public function testBeginRefusesATestWithNothingToTestAndLosesAGraceRaceQuietly(): void
    {
        [$acme] = $this->makeClient('Acme');
        $this->assertNull(ErpnextConnection::begin($acme->uuid, 'test', 'run-1'), 'no row, nothing to test');

        // Another press creates the row between our look and our insert: the unique company key makes us the loser.
        $this->app['events']->listen('eloquent.creating: ' . ErpnextConnection::class, function () use ($acme) {
            DB::table('alvora_erpnext_connections')->insert(['uuid' => (string) Str::uuid(), 'company_uuid' => $acme->uuid, 'host' => $this->host, 'status' => 'connecting', 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->assertNull(ErpnextConnection::begin($acme->uuid, 'connect', 'run-2', $this->host));
    }

    public function testAnUnknownOrganisationIs404OnEveryRoute(): void
    {
        [, $token] = $this->makeStaff();
        foreach ([['getJson', ''], ['postJson', '/connect'], ['postJson', '/test'], ['deleteJson', '']] as [$verb, $suffix]) {
            $this->withToken($token)->$verb('/int/v1/alvora/organizations/company_doesnotexist/erpnext' . $suffix, $suffix === '/connect' ? $this->connectBody() : [])->assertStatus(404);
        }
    }

    // ---- the hand-off ----

    public function testAHandOffThatCannotBeDecryptedOrIsNotAnObjectIsAsGoodAsExpired(): void
    {
        Cache::store(Handoff::STORE)->put('garbled-reference', 'not an encrypted value', 60);
        $this->assertNull(Handoff::pull('garbled-reference'));
        $this->assertNull(Cache::store(Handoff::STORE)->get('garbled-reference'), 'it is read once even when it fails');

        Cache::store(Handoff::STORE)->put('scalar-reference', Crypt::encryptString('"just a string"'), 60);
        $this->assertNull(Handoff::pull('scalar-reference'));
    }

    public function testAHandOffCanBeForgottenBeforeItIsRead(): void
    {
        $reference = Handoff::put(['address' => 'https://x.example.com', 'key' => 'k', 'secret' => 's']);
        Handoff::forget($reference);

        $this->assertNull(Handoff::pull($reference));
    }

    // ---- N4: no log, queue or cache ever holds the generated key or the signing secret ----

    public function testTheWholeActivityLogAndEverythingElseNeverHoldTheGeneratedKeyOrSigningSecret(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $secrets   = [];
        $remember  = function () use ($acme, &$secrets) {
            foreach (ApiCredential::withTrashed()->where('company_uuid', $acme->uuid)->get() as $credential) {
                $secrets[] = $credential->key;
            }
            foreach (WebhookEndpoint::withTrashed()->where('company_uuid', $acme->uuid)->get() as $webhook) {
                $secrets[] = $webhook->secret;
            }
        };

        $this->connect($acme, $token);
        $remember();
        $this->connect($acme, $token); // replace
        $remember();
        $this->withToken($token)->postJson($this->path($acme, '/test'));
        $this->withToken($token)->deleteJson($this->path($acme))->assertOk();
        $remember();

        $this->assertGreaterThanOrEqual(4, count($secrets));
        foreach (array_unique($secrets) as $secret) {
            $this->assertNotEmpty($secret);
            $this->assertSecretNowhere($secret);
        }
    }
}
