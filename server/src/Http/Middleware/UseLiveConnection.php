<?php

namespace Alvora\ErpnextLink\Http\Middleware;

/**
 * An admin's console may be in sandbox mode (the Access-Console-Sandbox header), which stock code answers by
 * switching the default database to the sandbox copy. The client's connection is always a live-data matter, so
 * this puts the live connection back for these routes.
 */
class UseLiveConnection
{
    public function handle($request, \Closure $next)
    {
        $live = app('alvora.live_connection');
        config(['database.default' => $live, 'fleetbase.connection.db' => $live]);

        return $next($request);
    }
}
