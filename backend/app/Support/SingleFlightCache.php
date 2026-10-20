<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Stale-while-revalidate with single flight, on any store with atomic locks (Redis in production).
 *
 *  - fresh: served straight from the cache;
 *  - stale (past fresh, within fresh + stale): one request takes the refresh lock and recomputes, every
 *    other request keeps getting the stale value: no thundering herd when a hot key expires;
 *  - missing: requests wait up to 3 s for the one computing it, then compute without the cache rather
 *    than fail (a Redis outage degrades to "no cache", never to errors).
 * Versioned namespaces (version()/bump()) invalidate whole families of keys with one INCR, e.g. every
 * multi-seller offer list for one GTIN after a price change.
 */
final class SingleFlightCache
{
    public function __construct(private readonly ?Repository $store = null) {}

    public function remember(string $key, int $freshSeconds, int $staleSeconds, Closure $compute): mixed
    {
        $store = $this->store();
        try {
            $entry = $store->get($key);
        } catch (Throwable) {
            return $compute();
        }
        $now = $this->clock();
        if (is_array($entry) && isset($entry['at']) && array_key_exists('value', $entry)) {
            $age = $now - (float) $entry['at'];
            if ($age < $freshSeconds) {
                return $entry['value'];
            }
            if ($age < $freshSeconds + $staleSeconds) {
                try {
                    $lock = $store->lock($key.':refresh', 10);
                    if (! $lock->get()) {
                        return $entry['value'];
                    }
                } catch (Throwable) {
                    return $entry['value'];
                }
                try {
                    return $this->put($store, $key, $compute(), $freshSeconds + $staleSeconds);
                } finally {
                    $this->release($lock);
                }
            }
        }

        try {
            $lock = $store->lock($key.':refresh', 10);
            $lock->block(3);
        } catch (LockTimeoutException) {
            return $compute();
        } catch (Throwable) {
            return $compute();
        }
        try {
            $entry = $store->get($key);
            if (is_array($entry) && isset($entry['at']) && $this->clock() - (float) $entry['at'] < $freshSeconds) {
                return $entry['value'];
            }

            return $this->put($store, $key, $compute(), $freshSeconds + $staleSeconds);
        } finally {
            $this->release($lock);
        }
    }

    private function release(mixed $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
        }
    }

    public function version(string $namespace): int
    {
        try {
            return (int) ($this->store()->get('ver:'.$namespace) ?? 1);
        } catch (Throwable) {
            return 0;
        }
    }

    public function bump(string $namespace): void
    {
        try {
            $store = $this->store();
            if ($store->get('ver:'.$namespace) === null) {
                $store->forever('ver:'.$namespace, 1);
            }
            $store->increment('ver:'.$namespace);
        } catch (Throwable) {
            // A missed bump only means the entry lives until its TTL.
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->store()->forget($key);
        } catch (Throwable) {
        }
    }

    private function put(Repository $store, string $key, mixed $value, int $ttl): mixed
    {
        try {
            $store->put($key, ['at' => $this->clock(), 'value' => $value], max(1, $ttl));
        } catch (Throwable) {
        }

        return $value;
    }

    /** Seconds with microseconds, from the application clock (so Carbon::setTestNow works). */
    private function clock(): float
    {
        return (float) now()->format('U.u');
    }

    private function store(): Repository
    {
        return $this->store ?? Cache::store();
    }
}
