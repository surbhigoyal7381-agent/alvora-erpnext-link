<?php

namespace Alvora\ErpnextLink\Models;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;

/**
 * The state of one client organization's ERPNext connection. A plain Eloquent model on purpose:
 * every query is scoped by company_uuid by hand, never by the admin's own session company.
 * It holds uuids and state only, never a key or secret.
 */
class ErpnextConnection extends Model
{
    use HasUuids;

    public const CONNECTING = 'connecting';

    public const CONNECTED = 'connected';

    public const ATTENTION = 'attention';

    public const DISCONNECTED = 'disconnected';

    /** A run with no update for this long is treated as killed. */
    public const STALE_AFTER = 300;

    /** The step each error code belongs to; interrupted keeps the step it stopped at. */
    public const STEP_OF = [
        'secret_expired'   => 1, 'address_blocked' => 1, 'host_in_use' => 1,
        'key_refused'      => 2, 'site_timeout' => 2, 'connector_missing' => 2, 'site_error' => 2,
        'owner_not_client' => 3, 'key_create_failed' => 3,
        'handover_failed'  => 4,
        'test_failed'      => 5, 'key_invalid' => 5,
    ];

    protected $table = 'alvora_erpnext_connections';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'step'            => 'integer',
        'last_test_ok'    => 'boolean',
        'last_test_ms'    => 'integer',
        'connected_at'    => 'datetime',
        'last_test_at'    => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public static function forCompany(string $companyUuid): ?self
    {
        return static::where('company_uuid', $companyUuid)->first();
    }

    /**
     * Another organization already uses this host, ignoring case: it holds a live key for it, or has a run
     * going. A failed, interrupted or disconnected row locks nothing (DEC-035, Surbhi 19 Nov 2026).
     */
    public static function hostInUse(string $host, string $exceptCompanyUuid): bool
    {
        return static::where('host', strtolower($host))
            ->where('company_uuid', '!=', $exceptCompanyUuid)
            ->where(function ($q) {
                $q->whereNotNull('api_credential_uuid')
                    ->orWhereNotNull('pending_api_credential_uuid')
                    ->orWhere(fn ($running) => $running->where('status', self::CONNECTING)->where('updated_at', '>', now()->subSeconds(self::STALE_AFTER)));
            })
            ->exists();
    }

    /**
     * Start a run, or return null when one is already going. The check and the change are one
     * conditional UPDATE, so two simultaneous presses give exactly one winner.
     * A test run needs a connected or attention row with an active pair.
     */
    public static function begin(string $companyUuid, string $kind, string $runUuid, ?string $host = null): ?self
    {
        $now   = now();
        $state = [
            'status'     => self::CONNECTING, 'run_kind' => $kind, 'run_uuid' => $runUuid, 'step' => $kind === 'test' ? 5 : 1,
            'error_code' => null, 'last_attempt_at' => $now,
        ];
        if ($host !== null) {
            $state['host'] = $host;
        }

        $row = static::forCompany($companyUuid);
        if (!$row) {
            if ($kind === 'test') {
                return null;
            }
            try {
                return static::create(['company_uuid' => $companyUuid, 'host' => $host] + $state)->fresh();
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        }

        $query = static::whereKey($row->uuid)->where(function ($q) use ($now) {
            $q->where('status', '!=', self::CONNECTING)->orWhere('updated_at', '<=', $now->copy()->subSeconds(self::STALE_AFTER));
        });
        if ($kind === 'test') {
            $query->whereIn('status', [self::CONNECTED, self::ATTENTION])->whereNotNull('api_credential_uuid');
        }

        return $query->update($state) === 1 ? $row->fresh() : null;
    }

    /**
     * Put a row back as it was before begin(), when the run could not be queued.
     */
    public function undoBegin(?array $before): void
    {
        if ($before === null) {
            $this->delete();

            return;
        }
        static::whereKey($this->uuid)->update(Arr::only($before, ['status', 'run_kind', 'run_uuid', 'step', 'error_code', 'host', 'last_attempt_at']));
    }

    /**
     * Stop the flow now: revoke the active and any pending pair, keep the row and host. Refuses (false)
     * while a run is going; the check and the change are one conditional UPDATE.
     */
    public function disconnect(): bool
    {
        $changed = static::whereKey($this->uuid)->where(function ($q) {
            $q->where('status', '!=', self::CONNECTING)->orWhere('updated_at', '<=', now()->subSeconds(self::STALE_AFTER));
        })->update(['status' => self::DISCONNECTED, 'run_kind' => null, 'run_uuid' => null, 'step' => null, 'error_code' => null]);
        if ($changed !== 1) {
            return false;
        }

        $this->refresh();
        static::revoke($this->api_credential_uuid, $this->webhook_endpoint_uuid);
        static::revoke($this->pending_api_credential_uuid, $this->pending_webhook_endpoint_uuid);
        $this->update(['api_credential_uuid' => null, 'webhook_endpoint_uuid' => null, 'pending_api_credential_uuid' => null, 'pending_webhook_endpoint_uuid' => null]);

        return true;
    }

    public function isBusy(): bool
    {
        return $this->status === self::CONNECTING && $this->updated_at->gt(now()->subSeconds(self::STALE_AFTER));
    }

    public function hasActivePair(): bool
    {
        return $this->api_credential_uuid !== null;
    }

    public function advance(int $step): void
    {
        $this->update(['step' => $step]);
    }

    public function fail(string $code): void
    {
        $step   = self::STEP_OF[$code] ?? $this->step;
        $update = ['status' => self::ATTENTION, 'step' => $step, 'error_code' => $code, 'run_kind' => null];
        if ($step < 5 && ($host = $this->activeHost())) {
            $update['host'] = $host; // a failed attempt at a new site must not hide the site that is still connected
        }
        if ($step === 5) {
            $update += ['last_test_at' => now(), 'last_test_ok' => false];
        }
        $this->update($update);
    }

    /** The host the active pair delivers to, or null when there is none. */
    private function activeHost(): ?string
    {
        $url  = $this->webhook_endpoint_uuid ? WebhookEndpoint::where('uuid', $this->webhook_endpoint_uuid)->value('url') : null;
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) ? strtolower($host) : null;
    }

    public function connected(int $ms): void
    {
        $this->update([
            'status'       => self::CONNECTED, 'step' => null, 'error_code' => null, 'run_kind' => null,
            'connected_at' => $this->connected_at ?? now(), 'last_test_at' => now(), 'last_test_ok' => true, 'last_test_ms' => $ms,
        ]);
    }

    /**
     * Revoke a key and its webhook: the credential is soft-deleted (its key then gives 401) and the
     * webhook removed. Safe to run twice. Logging is switched off so no key reaches the activity log.
     */
    public static function revoke(?string $credentialUuid, ?string $webhookUuid): void
    {
        if ($credentialUuid && ($credential = ApiCredential::where('uuid', $credentialUuid)->first())) {
            $credential->disableLogging();
            $credential->delete();
        }
        if ($webhookUuid && ($webhook = WebhookEndpoint::where('uuid', $webhookUuid)->first())) {
            $webhook->disableLogging();
            $webhook->delete();
        }
    }

    public function revokePending(): void
    {
        static::revoke($this->pending_api_credential_uuid, $this->pending_webhook_endpoint_uuid);
        $this->update(['pending_api_credential_uuid' => null, 'pending_webhook_endpoint_uuid' => null]);
    }

    /**
     * A run that stopped updating is treated as killed (done on read; no scheduled job). Whatever is pending
     * is revoked: before the cut-over that is the half-made new pair, after it the old pair; the active pair is
     * never touched. `$force` ignores the five-minute wait.
     * Returns true when this call changed the row.
     */
    public function sweep(bool $force = false): bool
    {
        if ($this->status !== self::CONNECTING) {
            return false;
        }

        $claimed = static::whereKey($this->uuid)->where('status', self::CONNECTING);
        if (!$force) {
            $claimed->where('updated_at', '<=', now()->subSeconds(self::STALE_AFTER));
        }
        if ($claimed->update(['status' => self::ATTENTION, 'error_code' => 'interrupted', 'run_kind' => null]) !== 1) {
            return false;
        }

        $this->refresh();
        $this->revokePending(); // before the cut-over: the half-made pair; after it: the old pair that was left to revoke
        if (($this->step ?? 1) < 5 && ($host = $this->activeHost())) {
            $this->update(['host' => $host]);
        }

        return true;
    }

    /**
     * What the console may see: eleven fixed keys, no internal id, no key, no secret.
     */
    public function toStatus(): array
    {
        return [
            'status'          => $this->status,
            'step'            => $this->step,
            'run_kind'        => $this->status === self::CONNECTING ? $this->run_kind : null,
            'host'            => $this->host,
            'error_code'      => $this->status === self::ATTENTION ? $this->error_code : null,
            'connected_at'    => $this->connected_at?->toIso8601String(),
            'last_test_at'    => $this->last_test_at?->toIso8601String(),
            'last_test_ok'    => $this->last_test_ok,
            'last_test_ms'    => $this->last_test_ms,
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'has_previous'    => $this->hasActivePair(),
        ];
    }

    public static function emptyStatus(): array
    {
        return [
            'status'       => self::DISCONNECTED, 'step' => null, 'run_kind' => null, 'host' => null, 'error_code' => null, 'connected_at' => null,
            'last_test_at' => null, 'last_test_ok' => null, 'last_test_ms' => null, 'last_attempt_at' => null, 'has_previous' => false,
        ];
    }
}
