<?php

namespace Alvora\ErpnextLink\Tests\Integration;

use Alvora\ErpnextLink\Models\ErpnextConnection;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AC-72, AC-73, AC-75, AC-5, AC-74: only a platform admin can use any route, and the status has a fixed shape.
 */
class AdminOnlyRoutesTest extends IntegrationTestCase
{
    public static function routes(): array
    {
        return [
            'status'     => ['getJson', ''],
            'connect'    => ['postJson', '/connect'],
            'test'       => ['postJson', '/test'],
            'disconnect' => ['deleteJson', ''],
        ];
    }

    private function counts(): array
    {
        return [ErpnextConnection::count(), ApiCredential::withTrashed()->count(), WebhookEndpoint::withTrashed()->count()];
    }

    #[DataProvider('routes')]
    public function testEveryCallerThatIsNotAPlatformAdminIsRefusedAndNothingChanges(string $verb, string $suffix): void
    {
        [$acme, $owner]       = $this->makeClient('Acme');
        [$other, $otherOwner] = $this->makeClient('Other');
        $dispatcher           = $this->makeUser('user', $acme);
        $key                  = ApiCredential::create(['company_uuid' => $acme->uuid, 'user_uuid' => $owner->uuid, 'name' => 'k'])->refresh()->key;
        $before               = $this->counts();
        $body                 = $verb === 'postJson' || $verb === 'deleteJson' ? $this->connectBody() : [];

        $callers = [
            'no login'                           => null,
            'client administrator'               => $owner->createToken('t')->plainTextToken,
            'another organisation administrator' => $otherOwner->createToken('t')->plainTextToken,
            'dispatcher'                         => $dispatcher->createToken('t')->plainTextToken,
            'the generated api key as bearer'    => $key,
        ];
        foreach ($callers as $name => $token) {
            $this->app['auth']->forgetGuards(); // every caller starts clean: the guard would otherwise keep the first user it resolved
            $response = ($token ? $this->withToken($token) : $this)->{$verb}($this->path($acme, $suffix), $body);
            $this->assertContains($response->getStatusCode(), [401, 403], $name . ' got ' . $response->getStatusCode());
        }
        $this->assertSame($before, $this->counts(), 'the database is unchanged');
        $this->assertSame(0, $this->erpnext->count('.verify') + $this->erpnext->count('.connect'), 'nothing was sent to ERPNext');
    }

    public function testAnImpersonatedClientOwnerIsRefusedLikeAnyClientUser(): void
    {
        // Impersonation hands the staff member a session AS the client's user: type is that user's, not admin.
        [$acme, $owner] = $this->makeClient('Acme');

        $this->withToken($owner->createToken('impersonation')->plainTextToken)->postJson($this->path($acme, '/connect'), $this->connectBody())->assertStatus(401);
        $this->assertSame(0, ErpnextConnection::where('company_uuid', $acme->uuid)->count());
    }

    public function testAnUnknownOrganisationIsA404WithNoDetail(): void
    {
        [, $token] = $this->makeStaff();

        $response = $this->withToken($token)->getJson('/int/v1/alvora/organizations/company_doesnotexist/erpnext');

        $response->assertNotFound();
        $this->assertSame(['error' => 'Not found.'], $response->json());
    }

    public function testRemovingAdminGuardWouldLetAClientAdminIn(): void
    {
        // AC-73: the route group names AdminGuard. If a merge drops it, this fails.
        $middleware = app('router')->getRoutes()->getByName(null) ?? null;
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'int/v1/alvora/organizations')) {
                $this->assertContains(\Fleetbase\Http\Middleware\AdminGuard::class, $route->gatherMiddleware(), $route->uri());
                $this->assertContains('fleetbase.protected', $route->gatherMiddleware(), $route->uri());
                $found = ($found ?? 0) + 1;
            }
        }
        $this->assertSame(4, $found ?? 0, 'exactly the four routes exist');
    }

    public function testNoV1PublicOrSocketRouteIsAdded(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_contains($route->getActionName(), 'Alvora\\ErpnextLink')) {
                $this->assertStringStartsWith('int/v1/alvora/', $route->uri());
            }
        }
    }

    public function testTheStatusHasExactlyElevenKeysAtMostThreeQueriesAndNoInternalIdOrSecret(): void
    {
        [$acme]    = $this->makeClient('Acme');
        [, $token] = $this->makeStaff();
        $this->withToken($token)->postJson($this->path($acme, '/connect'), $this->connectBody())->assertStatus(202);
        $this->assertSame('connected', ErpnextConnection::forCompany($acme->uuid)->status);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->withToken($token)->getJson($this->path($acme));
        $queries  = array_filter(DB::getQueryLog(), fn ($q) => !str_contains($q['query'], 'personal_access_tokens') && !str_contains($q['query'], 'from `users`') && !str_contains($q['query'], 'update `personal'));

        $response->assertOk();
        $this->assertSame(['status', 'step', 'run_kind', 'host', 'error_code', 'connected_at', 'last_test_at', 'last_test_ok', 'last_test_ms', 'last_attempt_at', 'has_previous'], array_keys($response->json()));
        $this->assertLessThanOrEqual(3, count(array_filter($queries, fn ($q) => str_contains($q['query'], 'alvora_erpnext') || str_contains($q['query'], 'companies'))));
        $this->assertDoesNotMatchRegularExpression('/flb_(live|test)_|whsec_/', $response->getContent());
        $this->assertStringNotContainsString(ErpnextConnection::forCompany($acme->uuid)->uuid, $response->getContent());
        $this->assertStringNotContainsString($acme->uuid, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertLessThan(1024, strlen($response->getContent()));
    }
}
