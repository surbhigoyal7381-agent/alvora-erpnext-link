<?php

namespace Alvora\ErpnextLink\Jobs;

use Alvora\ErpnextLink\Models\ErpnextConnection;
use Alvora\ErpnextLink\Support\Audit;
use Alvora\ErpnextLink\Support\Handoff;
use Alvora\ErpnextLink\Support\OutboundFailure;
use Alvora\ErpnextLink\Support\OutboundGuard;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Webhook\Signer\EndpointSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One connection run on the default queue: Connect (five steps) or Send test event (step 5 only).
 * Runs once (tries = 1) and ends in a defined state: connected or attention. The ERPNext key and
 * secret are never in this payload; they are pulled from the one-time handoff as the first action
 * and live only in local variables.
 */
class RunErpnextConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const EVENTS = ['order.completed', 'order.canceled'];

    public const WEBHOOK_PATH = '/api/method/alvoraa_vector.api.webhook';

    private const API = '/api/method/alvoraa_vector.api.';

    /** Below the worker's 60 s hard kill, so Laravel fails the job cleanly. */
    public int $timeout = 50;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $connectionUuid,
        public string $runUuid,
        public string $kind,
        public ?string $handoff = null,
        public ?string $actorUuid = null,
        public ?string $actorIp = null,
    ) {
    }

    public function handle(OutboundGuard $guard): void
    {
        $secrets    = $this->kind === 'connect' && $this->handoff ? Handoff::pull($this->handoff) : null;
        $connection = ErpnextConnection::find($this->connectionUuid);
        if (!$connection || $connection->run_uuid !== $this->runUuid || $connection->status !== ErpnextConnection::CONNECTING) {
            return; // superseded by a newer run or already swept
        }

        $company = Company::where('uuid', $connection->company_uuid)->first();
        $started = microtime(true);
        $error   = null;
        try {
            $this->kind === 'connect' ? $this->connect($connection, $company, $guard, $secrets) : $this->test($connection, $company, $guard);
        } catch (\Throwable $e) {
            $error = $e::class;
            $this->stop($connection, $company, [3 => 'key_create_failed', 4 => 'handover_failed', 5 => 'test_failed'][$connection->refresh()->step] ?? 'site_error');
        }

        $connection->refresh();
        Log::info('alvora_erpnext_link.run', [
            'run'         => $this->runUuid, 'organization' => $company?->public_id, 'kind' => $this->kind, 'step' => $connection->step,
            'outcome'     => $connection->status, 'error_code' => $connection->error_code, 'exception' => $error,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * Timeout or a killed process: leave a defined state (never a secret in the log).
     */
    public function failed(\Throwable $e): void
    {
        $connection = ErpnextConnection::find($this->connectionUuid);
        if ($connection && $connection->run_uuid === $this->runUuid) {
            $connection->sweep(true);
        }
        Log::warning('alvora_erpnext_link.run_failed', ['run' => $this->runUuid, 'exception' => $e::class]);
    }

    private function connect(ErpnextConnection $connection, Company $company, OutboundGuard $guard, ?array $secrets): void
    {
        // Step 1: the address, the secret, and whether another client already uses the site.
        if (!$secrets || !isset($secrets['address'], $secrets['key'], $secrets['secret'])) {
            $this->stop($connection, $company, 'secret_expired');

            return;
        }
        $address = $secrets['address'];
        $token   = 'token ' . $secrets['key'] . ':' . $secrets['secret'];
        unset($secrets);
        try {
            $guard->resolve(OutboundGuard::hostOf($address));
        } catch (OutboundFailure $e) {
            $this->stop($connection, $company, $e->errorCode);

            return;
        }
        if (ErpnextConnection::hostInUse($connection->host, $company->uuid)) {
            $this->stop($connection, $company, 'host_in_use');

            return;
        }
        $connection->advance(2);

        // Step 2: reach the site and check the key. Verify changes nothing on ERPNext.
        try {
            [$status, $json] = $guard->post($address, self::API . 'verify', '{}', ['Authorization' => $token]);
        } catch (OutboundFailure $e) {
            $this->stop($connection, $company, $e->errorCode);

            return;
        }
        if (in_array($status, [401, 403], true)) {
            $this->stop($connection, $company, 'key_refused');

            return;
        }
        if (in_array($status, [404, 417], true)) {
            $this->stop($connection, $company, 'connector_missing');

            return;
        }
        if ($status !== 200 || data_get($json, 'message.ok') !== true) {
            $this->stop($connection, $company, 'site_error');

            return;
        }
        $connection->advance(3);

        // Step 3: the owner must be the client's own person; make the key and webhook for this client only.
        $owner = $company->owner_uuid ? User::where('uuid', $company->owner_uuid)->first() : null;
        if (!$owner || $owner->isAdmin() || !self::isMember($company->uuid, $owner->uuid)) {
            $this->stop($connection, $company, 'owner_not_client');

            return;
        }
        $connection->revokePending(); // a run killed earlier may have left a pair behind
        try {
            [$credential, $webhook] = DB::transaction(fn () => $this->createPair($company, $owner, $connection->host));
        } catch (\Throwable) {
            $this->stop($connection, $company, 'key_create_failed');

            return;
        }
        $connection->update(['pending_api_credential_uuid' => $credential->uuid, 'pending_webhook_endpoint_uuid' => $webhook->uuid, 'step' => 4]);

        // Step 4: hand the pair to the connector. Exactly five keys, nothing about people.
        $body = json_encode([
            'connection_id'  => $connection->uuid,
            'api_key'        => $credential->key,
            'webhook_secret' => $webhook->secret,
            'webhook_id'     => $webhook->uuid,
            'events'         => self::EVENTS,
        ]);
        try {
            [$status, $json] = $guard->post($address, self::API . 'connect', $body, ['Authorization' => $token]);
        } catch (OutboundFailure $e) {
            // A timeout means the connector may have saved the new pair after we stopped waiting: the outcome is unknown,
            // so it is shown as an interruption at step 4, never as "the old one was left as it was" (review R1-1).
            $this->stop($connection, $company, match ($e->errorCode) {
                'address_blocked' => 'address_blocked',
                'site_timeout'    => 'interrupted',
                default           => 'handover_failed',
            });

            return;
        }
        unset($body, $token, $address);
        if ($status !== 200 || data_get($json, 'message.ok') !== true || data_get($json, 'message.connection_id') !== $connection->uuid) {
            $this->stop($connection, $company, 'handover_failed');

            return;
        }

        // The cut-over: the connector holds the new pair, so promote it at once. One UPDATE, the very next statement after
        // the connector's reply, makes the new pair active and parks the old one as "pending" to be revoked; a kill after
        // it leaves a consistent pair and the next Connect, Disconnect or sweep revokes the parked one.
        $old = [$connection->api_credential_uuid, $connection->webhook_endpoint_uuid];
        $connection->update([
            'api_credential_uuid'         => $credential->uuid, 'webhook_endpoint_uuid' => $webhook->uuid,
            'pending_api_credential_uuid' => $old[0], 'pending_webhook_endpoint_uuid' => $old[1], 'step' => 5,
        ]);
        $connection->revokePending();
        if ($old[0]) {
            $this->audit($company, $connection, 'replaced', 'ERPNext connection replaced', ['old_credential' => $old[0], 'new_credential' => $credential->uuid]);
        }

        // Step 5: a signed test event on the new pair.
        $this->test($connection, $company, $guard);
    }

    /**
     * Step 5: send a signed alvora.test event to the connector and require it to echo our connection id.
     */
    private function test(ErpnextConnection $connection, Company $company, OutboundGuard $guard): void
    {
        $credential = $connection->api_credential_uuid ? ApiCredential::where('uuid', $connection->api_credential_uuid)->first() : null;
        $webhook    = $connection->webhook_endpoint_uuid ? WebhookEndpoint::where('uuid', $connection->webhook_endpoint_uuid)->first() : null;
        $user       = $credential?->user_uuid ? User::where('uuid', $credential->user_uuid)->first() : null;
        if (!$credential || !$user || $user->isAdmin() || !self::isMember($company->uuid, $user->uuid)) {
            $this->stop($connection, $company, 'key_invalid');

            return;
        }
        if (!$webhook) {
            $this->stop($connection, $company, 'test_failed');

            return;
        }

        $body    = json_encode(['event' => 'alvora.test', 'connection_id' => $connection->uuid, 'sent_at' => now()->toIso8601String()]);
        $started = microtime(true);
        try {
            [$status, $json] = $guard->post('https://' . $connection->host, self::WEBHOOK_PATH, $body, [EndpointSignature::HEADER => EndpointSignature::header($body, $webhook->secret)]);
        } catch (OutboundFailure $e) {
            $this->stop($connection, $company, $e->errorCode === 'address_blocked' ? 'address_blocked' : 'test_failed');

            return;
        }
        if ($status !== 200 || data_get($json, 'message.ok') !== true || data_get($json, 'message.connection_id') !== $connection->uuid) {
            $this->stop($connection, $company, 'test_failed');

            return;
        }

        $connection->connected((int) round((microtime(true) - $started) * 1000));
        $this->kind === 'connect'
            ? $this->audit($company, $connection, 'connected', 'ERPNext connected')
            : $this->audit($company, $connection, 'test_event', 'ERPNext test event confirmed', ['result' => 'ok']);
    }

    /**
     * The key acts as the client's own owner, on the live connection, for this organization only.
     * Logging is off on both models so no key reaches the activity log. Everything is re-read after saving.
     *
     * @return array{0: ApiCredential, 1: WebhookEndpoint}
     */
    private function createPair(Company $company, User $owner, string $host): array
    {
        // ApiCredential decides live or test from the request's sandbox header, ignoring what it is given. A worker has no
        // request; a job that runs inside one (the sync queue) must not inherit the header, or the key would be a test key.
        $headers = request()->headers;
        $sandbox = $headers->get('Access-Console-Sandbox');
        $headers->remove('Access-Console-Sandbox');

        try {
            $credential = new ApiCredential(['company_uuid' => $company->uuid, 'user_uuid' => $owner->uuid, 'name' => 'AlvoraVector ERPNext connector']);
            $credential->disableLogging();
            $credential->save();

            $webhook = new WebhookEndpoint([
                'company_uuid' => $company->uuid, 'api_credential_uuid' => $credential->uuid, 'url' => 'https://' . $host . self::WEBHOOK_PATH,
                'mode'         => 'live', 'status' => 'enabled', 'events' => self::EVENTS, 'description' => 'AlvoraVector ERPNext connector',
            ]);
            $webhook->disableLogging();
            $webhook->save();
        } finally {
            $sandbox === null || $headers->set('Access-Console-Sandbox', $sandbox);
        }

        $stored = ApiCredential::where('uuid', $credential->uuid)->first();
        $hook   = WebhookEndpoint::where('uuid', $webhook->uuid)->first();
        if ($stored?->company_uuid !== $company->uuid || $stored->user_uuid !== $owner->uuid || $stored->test_mode || !str_starts_with((string) $stored->key, 'flb_live_')
            || $hook?->company_uuid !== $company->uuid || $hook->events !== self::EVENTS) {
            throw new \RuntimeException('created rows are not as specified');
        }

        return [$credential, $webhook];
    }

    /**
     * End the run in attention. Before the cut-over a half-made pair is revoked, so a failed run leaves nothing live.
     */
    private function stop(ErpnextConnection $connection, Company $company, string $code): void
    {
        $connection->refresh();
        $attempted = $connection->host; // fail() puts the still-connected site back; the audit entry names the one tried
        $connection->revokePending();
        $connection->fail($code);

        $this->kind === 'connect'
            ? $this->audit($company, $connection, 'connect_failed', 'ERPNext connect failed', ['step' => $connection->step, 'error_code' => $code, 'host' => $attempted])
            : $this->audit($company, $connection, 'test_event', 'ERPNext test event failed', ['result' => 'failed', 'error_code' => $code]);
    }

    private function audit(Company $company, ErpnextConnection $connection, string $event, string $description, array $properties = []): void
    {
        Audit::record($company, $this->actorUuid, $this->actorIp, $event, $description, $properties + ['host' => $connection->host]);
    }

    private static function isMember(string $companyUuid, string $userUuid): bool
    {
        return DB::table('company_users')->where('company_uuid', $companyUuid)->where('user_uuid', $userUuid)->whereNull('deleted_at')->exists();
    }
}
