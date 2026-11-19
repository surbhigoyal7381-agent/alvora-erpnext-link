<?php

namespace Alvora\ErpnextLink\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Carries the ERPNext address, key and secret from the web request to the queued job without
 * putting them in the job payload (the Redis has no password and failed jobs keep their
 * arguments). The value is encrypted, lives at most TTL seconds and is read once.
 */
final class Handoff
{
    public const STORE = 'alvora_handoff';

    public const TTL = 120;

    public static function put(array $payload): string
    {
        $reference = Str::random(48);
        Cache::store(self::STORE)->put($reference, Crypt::encryptString(json_encode($payload)), self::TTL);

        return $reference;
    }

    public static function pull(string $reference): ?array
    {
        $value = Cache::store(self::STORE)->pull($reference);
        if (!is_string($value)) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($value), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    public static function forget(string $reference): void
    {
        Cache::store(self::STORE)->forget($reference);
    }
}
