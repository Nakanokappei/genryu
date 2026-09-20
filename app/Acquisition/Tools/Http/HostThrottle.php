<?php

namespace App\Acquisition\Tools\Http;

use Illuminate\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Sleep;

/**
 * Paces requests per host to a profile's requests_per_minute. The last
 * request time lives in the cache so several queue workers share one
 * budget; when the store supports locks, read-wait-write is atomic.
 */
final class HostThrottle
{
    public function __construct(private Cache $cache) {}

    /**
     * Block until the next request to $host is allowed, then claim that slot.
     */
    public function await(string $host, int $requestsPerMinute): void
    {
        $intervalMs = (int) ceil(60_000 / max(1, $requestsPerMinute));
        $key = 'acquisition:throttle:'.strtolower($host);

        $claim = function () use ($key, $intervalMs): void {
            $now = (int) (microtime(true) * 1000);
            $last = $this->cache->get($key);

            if (is_int($last) && $now - $last < $intervalMs) {
                $waitMs = $intervalMs - ($now - $last);
                Sleep::for($waitMs)->milliseconds();
                $now += $waitMs;
            }

            $this->cache->put($key, $now, 120);
        };

        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            $store->lock($key.':lock', 10)->block(10, $claim);
        } else {
            $claim();
        }
    }
}
