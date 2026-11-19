<?php

namespace Alvora\ErpnextLink\Http\Controllers;

use Alvora\ErpnextLink\Jobs\RunErpnextConnection;
use Alvora\ErpnextLink\Models\ErpnextConnection;
use Alvora\ErpnextLink\Support\Audit;
use Alvora\ErpnextLink\Support\Handoff;
use Alvora\ErpnextLink\Support\OutboundGuard;
use Fleetbase\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * The ERPNext tab's four calls. Admin only (AdminGuard on the route group). The target organization
 * comes from the path, never from the admin's own session company. Plain controller on purpose: it
 * has no IAM permission of its own, AdminGuard alone decides.
 */
class ErpnextConnectionController extends Controller
{
    public function show(string $id): JsonResponse
    {
        if (!($company = $this->company($id))) {
            return $this->notFound();
        }

        $connection = ErpnextConnection::forCompany($company->uuid);
        $connection?->sweep();

        return response()->json($connection ? $connection->toStatus() : ErpnextConnection::emptyStatus());
    }

    public function connect(Request $request, string $id): JsonResponse
    {
        if (!($company = $this->company($id))) {
            return $this->notFound();
        }

        $input = $request->validate([
            'address'    => ['required', 'string', 'starts_with:https://', 'max:255'],
            'api_key'    => ['required', 'string', 'max:255'],
            'api_secret' => ['required', 'string', 'max:255'],
        ]);

        $before     = ErpnextConnection::forCompany($company->uuid)?->getAttributes();
        $runUuid    = (string) Str::uuid();
        $connection = ErpnextConnection::begin($company->uuid, 'connect', $runUuid, OutboundGuard::displayHost($input['address']));
        if (!$connection) {
            return $this->busy();
        }

        $reference = null;
        try {
            $reference = Handoff::put(['address' => $input['address'], 'key' => $input['api_key'], 'secret' => $input['api_secret']]);
            RunErpnextConnection::dispatch($connection->uuid, $runUuid, 'connect', $reference, $request->user()->uuid, $request->ip());
        } catch (\Throwable) {
            $reference && Handoff::forget($reference);
            $connection->undoBegin($before);

            return response()->json(['error' => 'The connection could not be started.'], 500);
        }

        Audit::record($company, $request->user()->uuid, $request->ip(), 'connect_started', 'ERPNext connect started', ['host' => $connection->host]);

        return response()->json($connection->toStatus(), 202);
    }

    public function test(Request $request, string $id): JsonResponse
    {
        if (!($company = $this->company($id))) {
            return $this->notFound();
        }

        $existing = ErpnextConnection::forCompany($company->uuid);
        $existing?->sweep();
        if ($existing?->fresh()->isBusy()) {
            return $this->busy();
        }
        if (!$existing || !$existing->fresh()->hasActivePair()) {
            return response()->json(['error' => 'There is no connection to test.'], 422);
        }

        $runUuid    = (string) Str::uuid();
        $connection = ErpnextConnection::begin($company->uuid, 'test', $runUuid);
        if (!$connection) {
            return $this->busy();
        }

        try {
            RunErpnextConnection::dispatch($connection->uuid, $runUuid, 'test', null, $request->user()->uuid, $request->ip());
        } catch (\Throwable) {
            $connection->undoBegin($existing->getAttributes());

            return response()->json(['error' => 'The test could not be started.'], 500);
        }

        return response()->json($connection->toStatus(), 202);
    }

    public function disconnect(Request $request, string $id): JsonResponse
    {
        if (!($company = $this->company($id))) {
            return $this->notFound();
        }

        $connection = ErpnextConnection::forCompany($company->uuid);
        if (!$connection) {
            return $this->notFound();
        }
        if (!$connection->disconnect()) {
            return $this->busy();
        }

        Audit::record($company, $request->user()->uuid, $request->ip(), 'disconnected', 'ERPNext disconnected', ['host' => $connection->host]);

        return response()->json($connection->toStatus());
    }

    private function company(string $publicId): ?Company
    {
        return Company::where('public_id', $publicId)->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'Not found.'], 404);
    }

    private function busy(): JsonResponse
    {
        return response()->json(['error' => 'A connection is already being set up.'], 409);
    }
}
