<?php

namespace Alvora\ErpnextLink\Tests\Integration;

use Alvora\ErpnextLink\Jobs\RunErpnextConnection;
use Alvora\ErpnextLink\Models\ErpnextConnection;
use Alvora\ErpnextLink\Support\OutboundGuard;
use Illuminate\Support\Facades\Queue;

/**
 * US-11: a sentinel ERPNext secret and the generated key and signing secret are found only where they must be.
 */
class SecretsTest extends IntegrationTestCase
{
    private const SENTINEL = 'ZZSENTINEL-9f3a';

    public function testTheSecretIsNowhereAfterASuccessfulConnect(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();

        $this->connect($acme, $token, $this->connectBody(self::SENTINEL))->assertStatus(202);

        $this->assertSame('connected', ErpnextConnection::forCompany($acme->uuid)->status);
        $this->assertSecretNowhere(self::SENTINEL);
        $this->assertSecretNowhere('erp-key-1');
    }

    public function testTheSecretIsNowhereWhenErpnextGoesDownAtTheHandOver(): void
    {
        [$acme]                              = $this->makeClient('Acme');
        [, $token]                           = $this->makeStaff();
        $this->erpnext->failures['.connect'] = new \RuntimeException('boom ' . self::SENTINEL);

        $this->connect($acme, $token, $this->connectBody(self::SENTINEL))->assertStatus(202);

        $this->assertSame('handover_failed', ErpnextConnection::forCompany($acme->uuid)->error_code);
        $this->assertSecretNowhere(self::SENTINEL);
    }

    public function testTheSecretIsNowhereWhenTheWorkerIsKilledMidJob(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        Queue::fake();
        $this->connect($acme, $token, $this->connectBody(self::SENTINEL))->assertStatus(202);
        $job = Queue::pushedJobs()[RunErpnextConnection::class][0]['job'];
        $job->handle(new OutboundGuard(fn () => ['93.184.216.34'], fn () => $this->erpnext->client()));

        // The worker is killed after step 2 of a later run: Laravel calls failed() with the timeout exception.
        $row = ErpnextConnection::forCompany($acme->uuid);
        $row->update(['status' => 'connecting', 'run_uuid' => $job->runUuid, 'step' => 2]);
        $job->failed(new \Illuminate\Queue\TimeoutExceededException('has timed out'));

        $this->assertSame(['attention', 'interrupted'], [$row->refresh()->status, $row->error_code]);
        $this->assertSecretNowhere(self::SENTINEL);
        $this->assertStringNotContainsString(self::SENTINEL, serialize($job), 'AC-13: the job payload');
    }

    public function testNoResponseOfOursEverHoldsAKeyOrSigningSecret(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $responses = [
            $this->connect($acme, $token, $this->connectBody(self::SENTINEL)),
            $this->withToken($token)->getJson($this->path($acme)),
            $this->withToken($token)->postJson($this->path($acme, '/test')),
            $this->withToken($token)->deleteJson($this->path($acme)),
        ];
        foreach ($responses as $response) {
            $this->assertDoesNotMatchRegularExpression('/flb_(live|test)_|whsec_/', $response->getContent());
            $this->assertStringNotContainsString(self::SENTINEL, $response->getContent());
        }
    }

    public function testTheSecretIsNeverInAnyHeaderWeSendExceptAuthorizationAndNeverInAUrl(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connect($acme, $token, $this->connectBody(self::SENTINEL));

        foreach ($this->erpnext->requests as $request) {
            $this->assertStringNotContainsString(self::SENTINEL, $request['path']);
            foreach ($request['headers'] as $name => $values) {
                $name === 'Authorization' || $this->assertStringNotContainsString(self::SENTINEL, implode(',', $values), $name);
            }
            $this->assertStringNotContainsString(self::SENTINEL, $request['body']);
        }
    }
}
