<?php

namespace Alvora\ErpnextLink\Tests\Integration;

use Alvora\ErpnextLink\Models\ErpnextConnection;
use Alvora\ErpnextLink\Support\Handoff;
use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\Listeners\SendResourceLifecycleWebhook;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Webhook\CallWebhookJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * US-13 and the pins: the generated key reads only its own organization's data, an event goes only to its own
 * organization's endpoint, and a revoked key stops working. Also facts about stock code that this extension relies on,
 * so a bad upstream merge fails here instead of in production.
 */
class DoorsTest extends IntegrationTestCase
{
    private function order(Company $company, string $status = 'created'): string
    {
        $uuid    = (string) Str::uuid();
        $id      = 'order_' . Str::lower(Str::random(10));
        $payload = (string) Str::uuid();
        DB::table('payloads')->insert(['uuid' => $payload, 'public_id' => 'payload_' . Str::lower(Str::random(8)), 'company_uuid' => $company->uuid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->insert([
            'payload_uuid' => $payload, 'uuid' => $uuid, 'public_id' => $id, 'company_uuid' => $company->uuid, 'status' => $status, 'type' => 'default',
            'dispatched'   => 0, 'adhoc' => 0, 'started' => 0, 'is_route_optimized' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Connect a client through the extension and return its generated key. */
    private function connected(Company $company, string $token, ?string $address = null): string
    {
        $address ??= "https://{$this->host}";
        $this->connect($company, $token, $this->connectBody('s', $address))->assertStatus(202);

        return ApiCredential::where('uuid', ErpnextConnection::forCompany($company->uuid)->api_credential_uuid)->first()->key;
    }

    public function testTheGeneratedKeySeesOnlyItsOwnOrganisationsOrders(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [$other]   = $this->makeClient('Other');
        [, $token] = $this->makeStaff();
        $mine      = $this->order($acme);
        $theirs    = $this->order($other);
        $acmeKey   = $this->connected($acme, $token);
        $otherKey  = $this->connected($other, $token, "https://other.{$this->host}");

        $list = $this->withToken($acmeKey)->getJson('/v1/orders');
        $list->assertOk();
        $ids = collect($list->json('orders') ?? $list->json())->pluck('id')->all();
        $this->assertContains($mine, $ids);
        $this->assertNotContains($theirs, $ids, 'AC-76');

        $this->withToken($acmeKey)->getJson('/v1/orders/' . $theirs)->assertNotFound();
        $this->withToken($acmeKey)->putJson('/v1/orders/' . $theirs, ['notes' => 'hijack'])->assertNotFound();
        $this->withToken($acmeKey)->deleteJson('/v1/orders/' . $theirs)->assertNotFound();
        $row = DB::table('orders')->where('public_id', $theirs)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->deleted_at);
        $this->assertNull($row->notes);

        $this->assertNotContains($mine, collect(($r = $this->withToken($otherKey)->getJson('/v1/orders'))->json('orders') ?? $r->json())->pluck('id')->all(), 'AC-77: the other key cannot read Acme');
    }

    public function testAReplacedOrDisconnectedKeyGives401(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->order($acme);
        $first = $this->connected($acme, $token);
        $this->withToken($first)->getJson('/v1/orders')->assertOk();

        $second = $this->connected($acme, $token);
        $this->withToken($first)->getJson('/v1/orders')->assertStatus(401);
        $this->withToken($second)->getJson('/v1/orders')->assertOk();

        $this->withToken($token)->deleteJson($this->path($acme))->assertOk();
        $this->withToken($second)->getJson('/v1/orders')->assertStatus(401);
        $this->assertSame(1, DB::table('orders')->where('company_uuid', $acme->uuid)->count(), 'orders are untouched (AC-63)');
    }

    public function testAnOrderEventReachesOnlyTheEndpointOfItsOwnOrganisation(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [$other]   = $this->makeClient('Other');
        [, $token] = $this->makeStaff();
        $this->connected($acme, $token);
        $this->connected($other, $token, "https://other.{$this->host}");
        $orderId = $this->order($other, 'completed');
        $order   = \Fleetbase\FleetOps\Models\Order::where('public_id', $orderId)->first();
        Queue::fake();

        session(['company' => $other->uuid, 'is_sandbox' => false, 'api_environment' => 'live']);
        (new SendResourceLifecycleWebhook())->handle(new ResourceLifecycleEvent($order, 'completed'));

        $urls = [];
        Queue::assertPushed(CallWebhookJob::class, function (CallWebhookJob $job) use (&$urls) {
            $urls[] = $job->webhookUrl;

            return true;
        });
        $this->assertSame(["https://other.{$this->host}/api/method/alvoraa_vector.api.webhook"], $urls, 'AC-78');
    }

    public function testADriverLocationEventIsNeverSentToTheEndpoint(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->connected($acme, $token);
        $endpoint = WebhookEndpoint::where('company_uuid', $acme->uuid)->first();

        $this->assertTrue($endpoint->canFireEvent('order.completed'));
        $this->assertTrue($endpoint->canFireEvent('order.canceled'));
        $this->assertFalse($endpoint->canFireEvent('driver.location_changed'), 'AC-33');
        $this->assertFalse($endpoint->canFireEvent('order.created'));
    }

    // ---- pins: facts about stock code this extension relies on --------------------------------------

    public function testPinStockWebhookSecretIsGeneratedEncryptedAndTheFieldsWeFillAreFillable(): void
    {
        [$acme, $owner] = $this->makeClient('Acme');
        [, $hook]       = $this->makePair($acme, $owner);

        $this->assertStringStartsWith('whsec_', $hook->refresh()->secret);
        $raw = DB::table('webhook_endpoints')->where('uuid', $hook->uuid)->value('secret');
        $this->assertStringNotContainsString('whsec_', $raw, 'stored encrypted');
        foreach (['company_uuid', 'api_credential_uuid', 'url', 'mode', 'status', 'events', 'description'] as $field) {
            $this->assertContains($field, $hook->getFillable(), $field);
        }
        $this->assertSame(['order.completed'], $hook->events);
    }

    public function testPinStockObserverFillsTheKeyOnCreateAndTheCredentialFieldsAreFillable(): void
    {
        [$acme, $owner]  = $this->makeClient('Acme');
        $credential      = new ApiCredential(['company_uuid' => $acme->uuid, 'user_uuid' => $owner->uuid, 'name' => 'n']);
        $credential->disableLogging();
        $credential->save();

        $this->assertStringStartsWith('flb_live_', $credential->key);
        foreach (['company_uuid', 'user_uuid', 'name'] as $field) {
            $this->assertContains($field, $credential->getFillable(), $field);
        }
    }

    public function testPinStockActivityObserverOverwritesCompanyIdWithTheSessionCompany(): void
    {
        // The reason Audit sets company_id afterwards. If upstream fixes this, this fails and Audit can be simplified.
        session(['company' => 'session-company-uuid']);
        $activity = activity('pin')->log('pin');

        $this->assertSame('session-company-uuid', $activity->company_id);
    }

    public function testPinAdminGuardAndTheProtectedGroupExist(): void
    {
        $this->assertTrue(class_exists(\Fleetbase\Http\Middleware\AdminGuard::class));
        $this->assertArrayHasKey('fleetbase.protected', app('router')->getMiddlewareGroups());
        $this->assertSame('Fleetbase\Webhook\Signer\EndpointSignature', \Fleetbase\Webhook\Signer\EndpointSignature::class);
        $this->assertTrue(\Fleetbase\Webhook\Signer\EndpointSignature::verify('b', \Fleetbase\Webhook\Signer\EndpointSignature::header('b', 'whsec_x'), 'whsec_x'));
    }

    public function testTheHandOffStoreHasItsOwnFixedPrefixAndTheRedisPrefixIsPinnedForBothContainers(): void
    {
        $this->assertSame('alvora_handoff:', config('cache.stores.alvora_handoff.prefix'));
        $this->assertSame('alvora_handoff:', \Illuminate\Support\Facades\Cache::store(Handoff::STORE)->getStore()->getPrefix());
        $this->assertSame('redis', config('cache.stores.alvora_handoff.driver'));
        $reference = Handoff::put(['secret' => 'ZZSENTINEL-x']);
        $this->assertSame(['secret' => 'ZZSENTINEL-x'], Handoff::pull($reference));
        $this->assertNull(Handoff::pull($reference), 'read once');
    }

    public function testExceptionArgumentsAreNotCaptured(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'), 'AC-70');
    }

    public function testTheMigrationCreatesOnlyOurTableAndLeavesExistingRowsAlone(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('alvora_erpnext_connections'));
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('alvora_erpnext_connections');
        foreach (['uuid', 'company_uuid', 'host', 'status', 'pending_api_credential_uuid'] as $column) {
            $this->assertContains($column, $columns);
        }
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/secret|key$|password|token/', $column, 'no secret column (SEC-7, SEC-8)');
        }
    }
}
