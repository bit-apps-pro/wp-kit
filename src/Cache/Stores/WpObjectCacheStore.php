<?php

namespace BitApps\WPKit\Cache\Stores;

use BitApps\WPKit\Cache\Contracts\Store;

/**
 * Store backed by the WordPress object cache API (`wp_cache_*`), scoped to a cache group.
 *
 * WordPress's built-in object cache is non-persistent (per-request) unless a persistent
 * object-cache drop-in (Redis, Memcached, etc.) is installed; without one, entries do not
 * survive across requests despite any TTL passed to put()/forever().
 */
final class WpObjectCacheStore implements Store
{
    /**
     * @param string $group WordPress object-cache group all of this store's keys are scoped to
     */
    public function __construct(private string $group = 'default')
    {
    }

    /**
     * @inheritDoc
     */
    public function get(string $key)
    {
        $found = false;
        $value = wp_cache_get($key, $this->group, false, $found);

        return $found ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function put(string $key, $value, int $ttl): bool
    {
        return wp_cache_set($key, $value, $this->group, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function add(string $key, $value, int $ttl): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function forever(string $key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * @inheritDoc
     */
    public function forget(string $key): bool
    {
        return wp_cache_delete($key, $this->group);
    }

    /**
     * @inheritDoc
     *
     * Blast radius: wp_cache_flush() clears the ENTIRE object cache (every group and plugin),
     * not just this store's group — WordPress exposes no group-scoped flush.
     */
    public function flush(): bool
    {
        return wp_cache_flush();
    }

    /**
     * @inheritDoc
     */
    public function increment(string $key, int $by = 1)
    {
        return wp_cache_incr($key, $by, $this->group);
    }

    /**
     * @inheritDoc
     */
    public function decrement(string $key, int $by = 1)
    {
        return wp_cache_decr($key, $by, $this->group);
    }
}
