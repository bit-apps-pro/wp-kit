<?php

namespace BitApps\WPKit\Cache\Stores;

use BitApps\WPKit\Cache\Contracts\Store;

/**
 * Store backed by the WordPress transients API (`get_transient`/`set_transient`/`delete_transient`).
 *
 * flush() is a documented no-op: WordPress does not expose a way to enumerate or bulk-delete
 * transients without a direct `$wpdb` options-table scan, so this store cannot portably clear
 * only the keys it owns. Callers that need a flushable cache should use WpObjectCacheStore instead.
 */
final class TransientStore implements Store
{
    /**
     * @param string $prefix prepended to every transient name to namespace this store's keys
     */
    public function __construct(private string $prefix = '')
    {
    }

    /**
     * @inheritDoc
     */
    public function get(string $key)
    {
        $value = get_transient($this->prefix . $key);

        return $value === false ? null : $value;
    }

    /**
     * @inheritDoc
     */
    public function put(string $key, $value, int $ttl): bool
    {
        return set_transient($this->prefix . $key, $value, $ttl);
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
        return delete_transient($this->prefix . $key);
    }

    /**
     * @inheritDoc
     */
    public function flush(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function increment(string $key, int $by = 1): int
    {
        $new = (int) $this->get($key) + $by;

        // get_transient() doesn't expose the remaining TTL, so a counter can't preserve its
        // original expiry here; it becomes non-expiring, matching forever() semantics.
        $this->forever($key, $new);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }
}
