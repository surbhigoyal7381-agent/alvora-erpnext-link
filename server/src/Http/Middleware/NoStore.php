<?php

namespace Alvora\ErpnextLink\Http\Middleware;

/**
 * Connection status is never cached, by the browser or anything in between.
 */
class NoStore
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
