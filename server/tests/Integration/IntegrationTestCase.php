<?php

namespace Alvora\ErpnextLink\Tests\Integration;

use Alvora\ErpnextLink\Support\OutboundGuard;
use Alvora\ErpnextLink\Tests\Support\FakeErpnext;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\WebhookEndpoint;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;

/**
 * Boots the real AlvoraVector api shell (set ALVORA_API_PATH) against a throwaway MySQL and Redis, with the
 * package installed. Every test runs inside a database transaction that is rolled back.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected FakeErpnext $erpnext;

    protected TestHandler $logs;

    /** A random site name per test, so rows another run committed to a shared database cannot block the host (D1). */
    protected string $host;

    public function createApplication()
    {
        $app = require rtrim(getenv('ALVORA_API_PATH'), '/') . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        if (!getenv('ALVORA_API_PATH')) {
            $this->markTestSkipped('Set ALVORA_API_PATH to run the integration tests against the api shell.');
        }

        putenv('QUEUE_CONNECTION=sync');
        parent::setUp();
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        DB::beginTransaction();

        $this->host    = 'erp-' . bin2hex(random_bytes(4)) . '.example.com';
        $this->erpnext = new FakeErpnext();
        $this->app->instance(OutboundGuard::class, new OutboundGuard(fn () => ['93.184.216.34'], fn () => $this->erpnext->client()));

        $this->logs = new TestHandler();
        $this->app['log']->driver()->getLogger()->pushHandler($this->logs);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    protected function makeUser(string $type = 'user', ?Company $company = null): User
    {
        $n    = bin2hex(random_bytes(4));
        $user = (new User())->forceFill(['name' => "T $n", 'email' => "t$n@example.test", 'phone' => '+1' . random_int(2000000000, 9999999999), 'type' => $type, 'password' => 'x-' . $n]);
        $company && $user->forceFill(['company_uuid' => $company->uuid]);
        $user->save();
        $company?->addUser($user);

        return $user;
    }

    /** A client organization whose owner is its own person (a member, not an admin). */
    protected function makeClient(string $name = 'Client'): array
    {
        $company = Company::create(['name' => $name . ' ' . bin2hex(random_bytes(3))]);
        $owner   = $this->makeUser('user', $company);
        $company->setOwner($owner)->save();

        return [$company->refresh(), $owner];
    }

    /** The platform admin (Alvora staff) in its own organization, as a bearer token. */
    protected function makeStaff(): array
    {
        $home  = Company::create(['name' => 'Alvora staff ' . bin2hex(random_bytes(3))]);
        $staff = $this->makeUser('admin', $home);

        return [$staff, $staff->createToken('t')->plainTextToken, $home];
    }

    protected function path(Company $company, string $suffix = ''): string
    {
        return '/int/v1/alvora/organizations/' . $company->public_id . '/erpnext' . $suffix;
    }

    protected function connectBody(string $secret = 'ZZSENTINEL-secret', ?string $address = null): array
    {
        return ['address' => $address ?? "https://{$this->host}", 'api_key' => 'erp-key-1', 'api_secret' => $secret];
    }

    protected function connect(Company $company, string $token, ?array $body = null)
    {
        return $this->withToken($token)->postJson($this->path($company, '/connect'), $body ?? $this->connectBody());
    }

    /** A live key and its webhook for a company, as the extension would leave them (used to set up old and pending pairs). */
    protected function makePair(Company $company, User $owner): array
    {
        $credential = new ApiCredential(['company_uuid' => $company->uuid, 'user_uuid' => $owner->uuid, 'name' => 'pair']);
        $credential->disableLogging();
        $credential->save();
        $webhook = new WebhookEndpoint(['company_uuid' => $company->uuid, 'api_credential_uuid' => $credential->uuid, 'url' => "https://{$this->host}/x", 'mode' => 'live', 'status' => 'enabled', 'events' => ['order.completed']]);
        $webhook->disableLogging();
        $webhook->save();

        return [$credential->refresh(), $webhook->refresh()];
    }

    /** Every place a secret could end up besides the places that are allowed to see it. */
    protected function assertSecretNowhere(string $secret): void
    {
        foreach (['jobs', 'failed_jobs', 'activity_log', 'alvora_erpnext_connections', 'api_request_logs', 'webhook_request_logs'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertStringNotContainsString($secret, json_encode(DB::table($table)->get()), "secret found in $table");
            }
        }
        foreach (['default', 'cache'] as $connection) {
            $client = Redis::connection($connection)->client();
            $prefix = $client->getOption(\Redis::OPT_PREFIX);
            $client->setOption(\Redis::OPT_PREFIX, '');
            try {
                foreach ($client->keys('*') as $key) {
                    $this->assertStringNotContainsString($secret, $key);
                    try {
                        $this->assertStringNotContainsString($secret, (string) @$client->get($key), "secret found in redis $connection $key");
                    } catch (\Throwable $e) {
                        if ($e instanceof \PHPUnit\Framework\ExpectationFailedException) {
                            throw $e;
                        }
                    }
                }
            } finally {
                $client->setOption(\Redis::OPT_PREFIX, $prefix);
            }
        }
        foreach ($this->logs->getRecords() as $record) {
            $this->assertStringNotContainsString($secret, json_encode($record->toArray()), 'secret found in a log line');
        }
    }
}
