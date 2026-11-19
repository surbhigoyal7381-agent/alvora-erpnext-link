<?php

use Alvora\ErpnextLink\Http\Middleware\NoStore;
use Alvora\ErpnextLink\Http\Middleware\UseLiveConnection;
use Fleetbase\Http\Middleware\AdminGuard;
use Illuminate\Support\Facades\Route;

/*
| Platform-admin calls for one organization's ERPNext connection. Nothing here is public, nothing
| is on /v1, nothing is published to a socket channel.
*/
Route::prefix('int/v1/alvora')
    ->namespace('Alvora\ErpnextLink\Http\Controllers')
    ->middleware(['fleetbase.protected', UseLiveConnection::class, NoStore::class, AdminGuard::class])
    ->group(function ($router) {
        $router->get('organizations/{id}/erpnext', 'ErpnextConnectionController@show')->middleware('throttle:alvora-erpnext-status');
        $router->post('organizations/{id}/erpnext/connect', 'ErpnextConnectionController@connect')->middleware('throttle:alvora-erpnext-connect');
        $router->post('organizations/{id}/erpnext/test', 'ErpnextConnectionController@test')->middleware('throttle:alvora-erpnext-test');
        $router->delete('organizations/{id}/erpnext', 'ErpnextConnectionController@disconnect');
    });
