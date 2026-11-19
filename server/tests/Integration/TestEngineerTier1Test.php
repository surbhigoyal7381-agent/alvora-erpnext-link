<?php

namespace Alvora\ErpnextLink\Tests\Integration;

use Alvora\ErpnextLink\Models\ErpnextConnection;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Slice 001 test-engineer additions for the Tier 1 must-nots: one client's key never goes to another client's
 * site, the documented rate limits really bite, and the step-4 cut-over is all or nothing.
 */
class TestEngineerTier1Test extends IntegrationTestCase
{
    private function live(string $table, Company $company): int
    {
        return DB::table($table)->where('company_uuid', $company->uuid)->whereNull('deleted_at')->count();
    }

    public function testEachStandInReceivesOnlyItsOwnClientsKeyAndSecret(): void
    {
        [$a]       = $this->makeClient('A');
        [$b]       = $this->makeClient('B');
        [, $token] = $this->makeStaff();

        $this->connect($a, $token, $this->connectBody('ZZSENTINEL-a', 'https://erp.a-example.com'))->assertStatus(202);
        $forA     = $this->erpnext->lastConnectBody();
        $mark     = count($this->erpnext->requests);
        $this->connect($b, $token, $this->connectBody('ZZSENTINEL-b', 'https://erp.b-example.com'))->assertStatus(202);
        $forB     = $this->erpnext->lastConnectBody();

        $this->assertNotSame($forA['api_key'], $forB['api_key'], 'AC-78: two clients never share a key');
        $this->assertNotSame($forA['webhook_secret'], $forB['webhook_secret']);
        $this->assertNotSame($forA['connection_id'], $forB['connection_id']);
        foreach (array_slice($this->erpnext->requests, $mark) as $request) {
            $everything = json_encode($request);
            $this->assertStringNotContainsString($forA['api_key'], $everything, 'A key reached the site of B');
            $this->assertStringNotContainsString($forA['webhook_secret'], $everything);
            $this->assertStringNotContainsString('ZZSENTINEL-a', $everything);
        }
        $this->assertSame($a->uuid, ApiCredential::where('key', $forA['api_key'])->value('company_uuid'));
        $this->assertSame($b->uuid, ApiCredential::where('key', $forB['api_key'])->value('company_uuid'));
    }

    public function testSendTestEventIsLimitedToTenAMinutePerOrganisation(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token)->assertStatus(202);

        for ($i = 0; $i < 10; $i++) {
            $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(202);
        }
        $this->withToken($token)->postJson($this->path($acme, '/test'))->assertStatus(429);
    }

    public function testConnectIsLimitedToTwentyAnHourPerOrganisationAcrossStaff(): void
    {
        [$acme]    = $this->makeClient('Acme');
        for ($i = 0; $i < 5; $i++) {
            [, $token] = $this->makeStaff();
            for ($j = 0; $j < 4; $j++) {
                $this->app['auth']->forgetGuards(); // one app instance serves every request here; a real request starts with no cached user
                $this->withToken($token)->postJson($this->path($acme, '/connect'), [])->assertStatus(422);
            }
        }
        [, $sixth] = $this->makeStaff();
        $this->app['auth']->forgetGuards();
        $this->withToken($sixth)->postJson($this->path($acme, '/connect'), [])->assertStatus(429);
    }

    public function testACutOverThatFailsHalfWayLeavesTheOldKeyWorking(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token)->assertStatus(202);
        $old = ErpnextConnection::forCompany($acme->uuid)->api_credential_uuid;

        // The old key is revoked first inside the cut-over; the row update that follows fails once.
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'update `alvora_erpnext_connections`') && in_array(5, $query->bindings, true)) {
                $armed = false;
                throw new \RuntimeException('disk full during cut-over');
            }
        });
        $this->connect($acme, $token);
        $armed = false;

        $this->assertNotNull(ApiCredential::where('uuid', $old)->first(), 'AC-56: the old key was not revoked by a cut-over that did not finish');
        $this->assertSame($old, ErpnextConnection::forCompany($acme->uuid)->api_credential_uuid);
        $this->assertSame(1, $this->live('api_credentials', $acme), 'one live key, never two and never none');
        $this->assertSecretNowhere('ZZSENTINEL-secret');
        $this->assertStringNotContainsString('disk full', json_encode($this->withToken($token)->getJson($this->path($acme))->json()), 'no exception text in the status');
    }
}
