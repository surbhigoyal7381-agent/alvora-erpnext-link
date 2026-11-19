<?php

namespace Alvora\ErpnextLink\Providers;

use Fleetbase\Providers\CoreServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// Same guard as every Fleetbase extension; it can only fire where core-api is missing, so no test can reach it.
// @codeCoverageIgnoreStart
if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Extension cannot be loaded without `fleetbase/core-api` installed!');
}
// @codeCoverageIgnoreEnd

class ErpnextLinkServiceProvider extends CoreServiceProvider
{
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);
        // The live database connection, as configured before any request can switch to the sandbox.
        $this->app->singleton('alvora.live_connection', fn ($app) => $app['config']->get('fleetbase.connection.db', 'mysql'));
        $this->app->make('alvora.live_connection');
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->mergeConfigFrom(__DIR__ . '/../../config/cache.stores.php', 'cache.stores');

        // Own named limits; the global THROTTLE_* settings and the API key budget are untouched.
        $user = fn (Request $request) => 'user:' . ($request->user()?->uuid ?? $request->ip());
        $org  = fn (Request $request) => 'org:' . $request->route('id');
        RateLimiter::for('alvora-erpnext-connect', fn (Request $request) => [Limit::perMinute(5)->by($user($request)), Limit::perHour(20)->by($org($request))]);
        RateLimiter::for('alvora-erpnext-test', fn (Request $request) => Limit::perMinute(10)->by($org($request)));
        RateLimiter::for('alvora-erpnext-status', fn (Request $request) => Limit::perMinute(60)->by($user($request)));
    }
}
