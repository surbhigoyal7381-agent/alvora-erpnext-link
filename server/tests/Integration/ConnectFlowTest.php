<?php

namespace Alvora\ErpnextLink\Tests\Integration;

use Alvora\ErpnextLink\Jobs\RunErpnextConnection;
use Alvora\ErpnextLink\Models\ErpnextConnection;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Webhook\Signer\EndpointSignature;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;

/**
 * US-2 to US-9: the five steps, the order of replace, every failure code, the cut-over, the sweep, disconnect.
 */
class ConnectFlowTest extends IntegrationTestCase
{
    private function pairCount(Company $company): array
    {
        return [ApiCredential::withTrashed()->where('company_uuid', $company->uuid)->count(), WebhookEndpoint::withTrashed()->where('company_uuid', $company->uuid)->count()];
    }

    private function live(string $table, Company $company): int
    {
        return DB::table($table)->where('company_uuid', $company->uuid)->whereNull('deleted_at')->count();
    }

    public function testFirstConnectMakesOneLivePairForTheTargetOrganisationOnlyAndConnects(): void
    {
        [$acme, $owner]         = $this->makeClient('Acme');
        [$staff, $token, $home] = $this->makeStaff();

        $this->withHeader('Access-Console-Sandbox', 'true')->withToken($token)->postJson($this->path($acme, '/connect'), $this->connectBody())->assertStatus(202)->assertJsonPath('status', 'connecting');

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame('connected', $row->status, 'the sync queue ran all five steps');
        $this->assertSame('erp.example.com', $row->host);
        $this->assertNotNull($row->connected_at);
        $this->assertTrue($row->last_test_ok);

        // AC-29 and AC-30 and AC-32: the credential, re-read from the database.
        $credential = ApiCredential::where('uuid', $row->api_credential_uuid)->first();
        $this->assertSame($acme->uuid, $credential->company_uuid);
        $this->assertSame($owner->uuid, $credential->user_uuid);
        $this->assertFalse((bool) $credential->test_mode, 'created live even with the sandbox header on the request');
        $this->assertStringStartsWith('flb_live_', $credential->key);
        $webhook = WebhookEndpoint::where('uuid', $row->webhook_endpoint_uuid)->first();
        $this->assertSame($acme->uuid, $webhook->company_uuid);
        $this->assertSame('live', $webhook->mode);
        $this->assertSame('enabled', $webhook->status);
        $this->assertSame('https://erp.example.com/api/method/alvoraa_vector.api.webhook', $webhook->url);
        $this->assertSame(['order.completed', 'order.canceled'], $webhook->events);
        $this->assertNull($row->pending_api_credential_uuid);

        // Nothing was made under the staff member's own organisation.
        $this->assertSame([0, 0], $this->pairCount($home));
        $this->assertNull(ErpnextConnection::forCompany($home->uuid));

        // AC-30: the key acts as the client's non-admin owner.
        $this->assertFalse($credential->user->isAdmin());
    }

    public function testTheHandOverAndTheTestEventCarryExactlyWhatWasSpecified(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token)->assertStatus(202);

        $verify = $this->erpnext->requests[0];
        $this->assertStringEndsWith('/api/method/alvoraa_vector.api.verify', $verify['path']);
        $this->assertSame('token erp-key-1:ZZSENTINEL-secret', $verify['headers']['Authorization'][0]);

        $body = $this->erpnext->lastConnectBody();
        $this->assertEqualsCanonicalizing(['connection_id', 'api_key', 'webhook_secret', 'webhook_id', 'events'], array_keys($body), 'AC-37: exactly five keys');
        $this->assertSame(['order.completed', 'order.canceled'], $body['events']);
        $this->assertSame(ErpnextConnection::forCompany($acme->uuid)->uuid, $body['connection_id']);
        $this->assertStringStartsWith('flb_live_', $body['api_key']);
        $this->assertStringStartsWith('whsec_', $body['webhook_secret']);

        $test = collect($this->erpnext->requests)->last();
        $this->assertStringEndsWith('.webhook', $test['path']);
        $payload = json_decode($test['body'], true);
        $this->assertEqualsCanonicalizing(['event', 'connection_id', 'sent_at'], array_keys($payload));
        $this->assertSame('alvora.test', $payload['event']);
        $this->assertTrue(EndpointSignature::verify($test['body'], $test['headers'][EndpointSignature::HEADER][0], $body['webhook_secret']));
    }

    public static function failures(): array
    {
        return [
            'wrong key'             => ['.verify', 401, 'key_refused', 2],
            'forbidden'             => ['.verify', 403, 'key_refused', 2],
            'connector missing'     => ['.verify', 404, 'connector_missing', 2],
            'connector missing 417' => ['.verify', 417, 'connector_missing', 2],
            'site error'            => ['.verify', 500, 'site_error', 2],
        ];
    }

    #[DataProvider('failures')]
    public function testAFailureAtStepTwoLeavesNothingBehindAndSaysWhichStep(string $suffix, int $status, string $code, int $step): void
    {
        [$acme]                           = $this->makeClient('Acme');
        [, $token]                        = $this->makeStaff();
        $this->erpnext->failures[$suffix] = ['status' => $status, 'body' => '<html><script>alert("remote text")</script></html>'];

        $this->connect($acme, $token)->assertStatus(202);

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame('attention', $row->status);
        $this->assertSame([$code, $step], [$row->error_code, $row->step]);
        $this->assertSame([0, 0], $this->pairCount($acme), 'AC-43: nothing created');
        $response = $this->withToken($token)->getJson($this->path($acme));
        $this->assertStringNotContainsString('remote text', $response->getContent());
        $this->assertStringNotContainsString('remote text', json_encode(Activity::where('company_id', $acme->uuid)->get()));
        $this->assertStringNotContainsString('remote text', json_encode($this->logs->getRecords()));
    }

    public function testASilentSiteIsATimeoutAtStepTwo(): void
    {
        [$acme]                             = $this->makeClient('Acme');
        [, $token]                          = $this->makeStaff();
        $this->erpnext->failures['.verify'] = new ConnectException('timed out', new Request('POST', 'https://erp.example.com'), null, ['errno' => CURLE_OPERATION_TIMEDOUT]);

        $this->connect($acme, $token);

        $this->assertSame('site_timeout', ErpnextConnection::forCompany($acme->uuid)->error_code);
        $this->assertSame([0, 0], $this->pairCount($acme));
    }

    public function testABlockedAddressFailsAtStepOneWithoutAnyConnection(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();

        foreach (['https://127.0.0.1', 'http://erp.example.com', 'https://user:pw@erp.example.com', 'https://erp.example.com:8443', 'https://localhost'] as $address) {
            $this->connect($acme, $token, $this->connectBody('s', $address))->assertStatus($address === 'http://erp.example.com' ? 422 : 202);
            if ($address !== 'http://erp.example.com') {
                $row = ErpnextConnection::forCompany($acme->uuid);
                $this->assertSame(['attention', 'address_blocked', 1], [$row->status, $row->error_code, $row->step], $address);
            }
        }
        $this->assertSame(0, count($this->erpnext->requests), 'the HTTP client never connected');
    }

    public function testANameThatResolvesToAPrivateAddressIsBlockedAtStepOne(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->app->instance(\Alvora\ErpnextLink\Support\OutboundGuard::class, new \Alvora\ErpnextLink\Support\OutboundGuard(fn () => ['93.184.216.34', '169.254.169.254'], fn () => $this->erpnext->client()));

        $this->connect($acme, $token);

        $this->assertSame('address_blocked', ErpnextConnection::forCompany($acme->uuid)->error_code);
        $this->assertCount(0, $this->erpnext->requests);
    }

    public function testTheOwnerMustBeTheClientsOwnPerson(): void
    {
        [$acme, $owner]  = $this->makeClient('Acme');
        [, $token]       = $this->makeStaff();

        // The owner is Alvora staff.
        DB::table('users')->where('uuid', $owner->uuid)->update(['type' => 'admin']);
        $this->connect($acme, $token);
        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame(['owner_not_client', 3], [$row->error_code, $row->step]);
        $this->assertSame([0, 0], $this->pairCount($acme));
        $this->assertSame(0, $this->erpnext->count('.connect'));

        // The owner is not a member of the organisation.
        DB::table('users')->where('uuid', $owner->uuid)->update(['type' => 'user']);
        DB::table('company_users')->where('user_uuid', $owner->uuid)->delete();
        $this->connect($acme, $token);
        $this->assertSame('owner_not_client', ErpnextConnection::forCompany($acme->uuid)->error_code);
        $this->assertSame([0, 0], $this->pairCount($acme));
    }

    public function testACreationFailureRollsBackEverythingAndSaysKeyCreateFailed(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->app['events']->listen('eloquent.creating: ' . WebhookEndpoint::class, function () {
            throw new \RuntimeException('database refused ZZSENTINEL-secret');
        });

        $this->connect($acme, $token);

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame(['attention', 'key_create_failed', 3], [$row->status, $row->error_code, $row->step]);
        $this->assertSame([0, 0], $this->pairCount($acme), 'the credential made before the failure was rolled back');
        $this->assertNull($row->pending_api_credential_uuid);
        $this->assertSecretNowhere('ZZSENTINEL-secret');
    }

    public function testAFailedHandOverRevokesTheNewPairAndKeepsTheOldOneWorking(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $old                          = ErpnextConnection::forCompany($acme->uuid);
        [$oldCredential, $oldWebhook] = [$old->api_credential_uuid, $old->webhook_endpoint_uuid];

        $this->erpnext->failures['.connect'] = 500;
        $this->connect($acme, $token);

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame(['attention', 'handover_failed', 4], [$row->status, $row->error_code, $row->step]);
        $this->assertTrue($row->has_previous ?? $row->hasActivePair());
        $this->assertSame([$oldCredential, $oldWebhook], [$row->api_credential_uuid, $row->webhook_endpoint_uuid], 'AC-55: the old pair is untouched');
        $this->assertNotNull(ApiCredential::where('uuid', $oldCredential)->first(), 'the old key still works');
        $this->assertNotNull(WebhookEndpoint::where('uuid', $oldWebhook)->first());
        $this->assertNull($row->pending_api_credential_uuid);
        $this->assertSame(1, $this->live('api_credentials', $acme), 'only the old key is live');
        $this->assertSame(1, $this->live('webhook_endpoints', $acme));
    }

    public function testAReplaceCutsOverAtStepFourAndNeverLeavesTwoLiveKeys(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $first             = ErpnextConnection::forCompany($acme->uuid);
        $oldCredentialUuid = $first->api_credential_uuid;

        $this->connect($acme, $token);

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame('connected', $row->status);
        $this->assertNotSame($oldCredentialUuid, $row->api_credential_uuid);
        $this->assertNull(ApiCredential::where('uuid', $oldCredentialUuid)->first(), 'the old key now gives 401 (soft-deleted)');
        $this->assertNotNull(ApiCredential::withTrashed()->where('uuid', $oldCredentialUuid)->first());
        $this->assertSame(1, $this->live('api_credentials', $acme));
        $this->assertSame(1, $this->live('webhook_endpoints', $acme));
        $this->assertNull($row->pending_api_credential_uuid);
        $this->assertSame(4, $this->erpnext->count('.verify') + $this->erpnext->count('.connect'), 'verify and connect, twice');
    }

    public function testIfTheTestFailsAfterTheCutOverTheNewPairStaysActive(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $old = ErpnextConnection::forCompany($acme->uuid)->api_credential_uuid;

        $this->erpnext->failures['.webhook'] = 500;
        $this->connect($acme, $token);

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame(['attention', 'test_failed', 5], [$row->status, $row->error_code, $row->step]);
        $this->assertNotSame($old, $row->api_credential_uuid, 'AC-56: the new pair is the active one');
        $this->assertNull(ApiCredential::where('uuid', $old)->first());
        $this->assertNotNull(ApiCredential::where('uuid', $row->api_credential_uuid)->first());
        $this->assertSame(1, $this->live('api_credentials', $acme));
    }

    public function testALeftoverPendingPairIsRevokedBeforeANewOneIsMade(): void
    {
        [$acme, $owner]         = $this->makeClient('Acme');
        [, $token]              = $this->makeStaff();
        [$credential, $webhook] = $this->makePair($acme, $owner);
        ErpnextConnection::create(['company_uuid' => $acme->uuid, 'host' => 'erp.example.com', 'status' => 'attention', 'error_code' => 'interrupted', 'step' => 3, 'pending_api_credential_uuid' => $credential->uuid, 'pending_webhook_endpoint_uuid' => $webhook->uuid]);

        $this->connect($acme, $token);

        $this->assertNull(ApiCredential::where('uuid', $credential->uuid)->first(), 'AC-58');
        $this->assertNull(WebhookEndpoint::where('uuid', $webhook->uuid)->first());
        $this->assertSame(1, $this->live('api_credentials', $acme));
    }

    public function testAKilledRunBeforeTheCutOverIsSweptOnReadAndRevokesOnlyThePendingPair(): void
    {
        [$acme, $owner]          = $this->makeClient('Acme');
        [, $token]               = $this->makeStaff();
        [$active, $activeHook]   = $this->makePair($acme, $owner);
        [$pending, $pendingHook] = $this->makePair($acme, $owner);
        $row                     = ErpnextConnection::create(['company_uuid' => $acme->uuid, 'host' => 'erp.example.com', 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 4,
            'api_credential_uuid'                                            => $active->uuid, 'webhook_endpoint_uuid' => $activeHook->uuid, 'pending_api_credential_uuid' => $pending->uuid, 'pending_webhook_endpoint_uuid' => $pendingHook->uuid]);

        // Four minutes old: still running, not touched.
        DB::table('alvora_erpnext_connections')->where('uuid', $row->uuid)->update(['updated_at' => now()->subMinutes(4)]);
        $this->withToken($token)->getJson($this->path($acme))->assertJsonPath('status', 'connecting');

        DB::table('alvora_erpnext_connections')->where('uuid', $row->uuid)->update(['updated_at' => now()->subMinutes(6)]);
        $this->withToken($token)->getJson($this->path($acme))->assertJsonPath('status', 'attention')->assertJsonPath('error_code', 'interrupted')->assertJsonPath('step', 4);

        $this->assertNull(ApiCredential::where('uuid', $pending->uuid)->first());
        $this->assertNull(WebhookEndpoint::where('uuid', $pendingHook->uuid)->first());
        $this->assertNotNull(ApiCredential::where('uuid', $active->uuid)->first(), 'the old pair is untouched');
        $this->assertNull($row->refresh()->pending_api_credential_uuid);
    }

    public function testAKilledRunAtStepFiveRevokesNothing(): void
    {
        [$acme, $owner]        = $this->makeClient('Acme');
        [, $token]             = $this->makeStaff();
        [$active, $activeHook] = $this->makePair($acme, $owner);
        $row                   = ErpnextConnection::create(['company_uuid' => $acme->uuid, 'host' => 'erp.example.com', 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 5, 'api_credential_uuid' => $active->uuid, 'webhook_endpoint_uuid' => $activeHook->uuid]);
        DB::table('alvora_erpnext_connections')->where('uuid', $row->uuid)->update(['updated_at' => now()->subMinutes(6)]);

        $this->withToken($token)->getJson($this->path($acme))->assertJsonPath('status', 'attention')->assertJsonPath('step', 5);

        $this->assertNotNull(ApiCredential::where('uuid', $active->uuid)->first());
    }

    public function testASecondPressWhileARunIsGoingIsAConflictAndQueuesNothing(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        ErpnextConnection::create(['company_uuid' => $acme->uuid, 'host' => 'erp.example.com', 'status' => 'connecting', 'run_kind' => 'connect', 'step' => 2, 'api_credential_uuid' => (string) \Illuminate\Support\Str::uuid()]);
        Queue::fake();

        foreach ([['postJson', '/connect', $this->connectBody()], ['postJson', '/test', []], ['deleteJson', '', []]] as [$verb, $suffix, $body]) {
            $this->withToken($token)->{$verb}($this->path($acme, $suffix), $body)->assertStatus(409)->assertJson(['error' => 'A connection is already being set up.']);
        }
        Queue::assertNothingPushed();
    }

    public function testTwoSimultaneousStartsHaveExactlyOneWinner(): void
    {
        [$acme] = $this->makeClient('Acme');

        $first  = ErpnextConnection::begin($acme->uuid, 'connect', 'run-1', 'erp.example.com');
        $second = ErpnextConnection::begin($acme->uuid, 'connect', 'run-2', 'erp.example.com');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame('run-1', ErpnextConnection::forCompany($acme->uuid)->run_uuid);
    }

    public function testTheSecretIsHandedOverEncryptedForAtMost120SecondsAndNotInTheJobPayload(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        Queue::fake();

        $this->connect($acme, $token)->assertStatus(202);

        Queue::assertPushed(RunErpnextConnection::class, function (RunErpnextConnection $job) {
            $this->assertStringNotContainsString('ZZSENTINEL', serialize($job));
            $this->assertGreaterThanOrEqual(40, strlen($job->handoff));
            $store = Cache::store('alvora_handoff');
            $raw   = $store->get($job->handoff);
            $this->assertIsString($raw);
            $this->assertStringNotContainsString('ZZSENTINEL', $raw, 'the cache holds ciphertext');
            $ttl = $store->getStore()->connection()->ttl($store->getStore()->getPrefix() . $job->handoff);
            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(120, $ttl);
            $store->forget($job->handoff);

            return true;
        });
        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame(['connecting', 1], [$row->status, $row->step]);
    }

    public function testTheHandOffIsReadOnceAndGoneWhenTheJobStarts(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        Queue::fake();
        $this->connect($acme, $token);
        $job = Queue::pushedJobs()[RunErpnextConnection::class][0]['job'];

        $this->assertNotNull(Cache::store('alvora_handoff')->get($job->handoff));
        $job->handle(app(\Alvora\ErpnextLink\Support\OutboundGuard::class));

        $this->assertNull(Cache::store('alvora_handoff')->get($job->handoff), 'AC-15');
        $this->assertSame('connected', ErpnextConnection::forCompany($acme->uuid)->status);
    }

    public function testAnExpiredHandOffFailsAtStepOneAndCreatesNothing(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        Queue::fake();
        $this->connect($acme, $token);
        $job = Queue::pushedJobs()[RunErpnextConnection::class][0]['job'];
        Cache::store('alvora_handoff')->forget($job->handoff);

        $job->handle(app(\Alvora\ErpnextLink\Support\OutboundGuard::class));

        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame(['attention', 'secret_expired', 1], [$row->status, $row->error_code, $row->step]);
        $this->assertSame([0, 0], $this->pairCount($acme));
        $this->assertCount(0, $this->erpnext->requests);
    }

    public function testWhenTheHandOffStoreIsUnavailableTheAnswerIs500AndNothingIsLeftRunning(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        config(['cache.stores.alvora_handoff.driver' => 'unavailable-driver']);
        Queue::fake();

        $this->connect($acme, $token)->assertStatus(500);

        $this->assertNull(ErpnextConnection::forCompany($acme->uuid), 'AC-14: no row left connecting');
        Queue::assertNothingPushed();
    }

    public function testAnotherClientCannotUseTheSameErpnextSite(): void
    {
        [$a]       = $this->makeClient('A');
        [$b]       = $this->makeClient('B');
        [, $token] = $this->makeStaff();
        $this->connect($a, $token);

        $this->connect($b, $token, $this->connectBody('s', 'https://ERP.example.com'));

        $row = ErpnextConnection::forCompany($b->uuid);
        $this->assertSame(['attention', 'host_in_use', 1], [$row->status, $row->error_code, $row->step]);
        $this->assertSame([0, 0], $this->pairCount($b));
        $this->assertStringNotContainsString($a->name, json_encode($this->withToken($token)->getJson($this->path($b))->json()));

        // Once the first is disconnected the site is free.
        $this->withToken($token)->deleteJson($this->path($a))->assertOk();
        $this->connect($b, $token);
        $this->assertSame('connected', ErpnextConnection::forCompany($b->uuid)->status);
    }

    public function testSendTestEventAgainstTheConnectedPairAndItsFailureModes(): void
    {
        [$acme, $owner]  = $this->makeClient('Acme');
        [, $token]       = $this->makeStaff();
        $this->connect($acme, $token);
        $before = count($this->erpnext->requests);

        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(202);
        $row = ErpnextConnection::forCompany($acme->uuid);
        $this->assertSame('connected', $row->status);
        $this->assertSame($before + 1, count($this->erpnext->requests), 'one signed request, no verify, no connect (AC-48)');
        $this->assertSame(1, $this->erpnext->count('.connect'));

        // The site stops confirming: attention at step 5, the pair is not revoked (AC-49).
        $this->erpnext->failures['.webhook'] = 500;
        $this->withToken($token)->postJson($this->path($acme, '/test'));
        $row->refresh();
        $this->assertSame(['attention', 'test_failed', 5], [$row->status, $row->error_code, $row->step]);
        $this->assertNotNull(ApiCredential::where('uuid', $row->api_credential_uuid)->first());

        // The owner is turned into staff: the key is no longer valid and nothing is sent (AC-50).
        unset($this->erpnext->failures['.webhook']);
        DB::table('users')->where('uuid', $owner->uuid)->update(['type' => 'admin']);
        $sent = count($this->erpnext->requests);
        $this->withToken($token)->postJson($this->path($acme, '/test'));
        $this->assertSame('key_invalid', $row->refresh()->error_code);
        $this->assertSame($sent, count($this->erpnext->requests));
    }

    public function testSendTestWithoutAConnectionIsRefused(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();

        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(422);
    }

    public function testDisconnectRevokesBothPairsKeepsTheRowAndCallsNobody(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token);
        $row  = ErpnextConnection::forCompany($acme->uuid);
        $keys = [$row->api_credential_uuid, $row->webhook_endpoint_uuid];
        $sent = count($this->erpnext->requests);

        $this->withToken($token)->deleteJson($this->path($acme))->assertOk()->assertJsonPath('status', 'disconnected');

        $row->refresh();
        $this->assertSame(['disconnected', 'erp.example.com', null], [$row->status, $row->host, $row->api_credential_uuid]);
        $this->assertNull(ApiCredential::where('uuid', $keys[0])->first(), 'the key now gives 401');
        $this->assertNull(WebhookEndpoint::where('uuid', $keys[1])->first());
        $this->assertSame($sent, count($this->erpnext->requests), 'no outbound call');
        $this->assertSame(1, DB::table('companies')->where('uuid', $acme->uuid)->count(), 'nothing else was deleted');
    }

    public function testEachActionWritesOneActivityEntryUnderTheTargetOrganisationWithNoSecret(): void
    {
        [$acme]                 = $this->makeClient('Acme');
        [$staff, $token, $home] = $this->makeStaff();
        $this->connect($acme, $token);
        $this->connect($acme, $token); // replace
        $this->withToken($token)->postJson($this->path($acme, '/test'));
        $this->withToken($token)->deleteJson($this->path($acme));

        $entries = Activity::where('log_name', 'alvora_erpnext')->where('company_id', $acme->uuid)->get();
        $counts  = $entries->countBy('event')->all();
        ksort($counts);
        $this->assertSame(['connect_started' => 2, 'connected' => 2, 'disconnected' => 1, 'replaced' => 1, 'test_event' => 1], $counts);
        foreach ($entries as $entry) {
            $this->assertSame($acme->uuid, $entry->company_id, 'filed under the client, not the admin\'s own organisation');
            $this->assertNotSame($home->uuid, $entry->company_id);
            $this->assertSame($staff->uuid, $entry->causer_id);
            $this->assertSame('erp.example.com', $entry->properties['host']);
            $this->assertArrayHasKey('ip', $entry->properties->all());
        }
        $this->assertDoesNotMatchRegularExpression('/flb_(live|test)_|whsec_|api\/method|ZZSENTINEL|erp-key-1/', json_encode($entries));
    }

    public function testAFailedConnectIsOneEntryWithTheStepAndCode(): void
    {
        [$acme]                             = $this->makeClient('Acme');
        [, $token]                          = $this->makeStaff();
        $this->erpnext->failures['.verify'] = 401;

        $this->connect($acme, $token);

        $failed = Activity::where('log_name', 'alvora_erpnext')->where('company_id', $acme->uuid)->where('event', 'connect_failed')->get();
        $this->assertCount(1, $failed);
        $this->assertSame(['step' => 2, 'error_code' => 'key_refused'], $failed[0]->properties->only(['step', 'error_code'])->all());
        $this->assertSame($acme->uuid, $failed[0]->company_id);
    }

    public function testEachRunLogsOneLineWithPublicIdsAndNeverTheHostOrASecret(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();

        $this->connect($acme, $token);

        $lines = collect($this->logs->getRecords())->filter(fn ($r) => str_starts_with($r->message, 'alvora_erpnext_link'));
        $this->assertCount(1, $lines);
        $context = $lines->first()->context;
        $this->assertSame($acme->public_id, $context['organization']);
        $this->assertSame(['run', 'organization', 'kind', 'step', 'outcome', 'error_code', 'exception', 'duration_ms'], array_keys($context));
        $this->assertStringNotContainsString('erp.example.com', json_encode($this->logs->getRecords()));
        $this->assertSecretNowhere('ZZSENTINEL-secret');
    }

    public function testRateLimitsAnswer429(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson($this->path($acme, '/connect'), [])->assertStatus(422);
        }
        $this->withToken($token)->postJson($this->path($acme, '/connect'), [])->assertStatus(429);
        // Status has its own, larger limit.
        $this->withToken($token)->getJson($this->path($acme))->assertOk();
    }

    public function testValidationRefusesBadInputWithoutEchoingTheSecret(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();

        $response = $this->withToken($token)->postJson($this->path($acme, '/connect'), ['address' => 'http://x.com', 'api_key' => ['a'], 'api_secret' => 'ZZSENTINEL-echo']);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('ZZSENTINEL-echo', $response->getContent());
        $this->assertNull(ErpnextConnection::forCompany($acme->uuid));
    }
}
