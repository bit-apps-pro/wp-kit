<?php

namespace BitApps\WPKit\Cache;

use BitApps\WPKit\Cache\Contracts\Store;

/**
 * Store-agnostic cache façade: adds remember/pull convenience helpers over a raw Store.
 */
final class Repository
{
    public function __construct(private Store $store)
    {
    }

    /**
     * Retrieve an item, or the given default if it is missing or expired.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->store->get($key);

        return $value ?? $default;
    }

    /**
     * Check whether a non-expired item exists for the key.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Store an item for the given number of seconds.
     *
     * @param mixed $value
     */
    public function put(string $key, $value, int $ttl): bool
    {
        return $this->store->put($key, $value, $ttl);
    }

    /**
     * Store an item only if it doesn't already exist (or has expired).
     *
     * @param mixed $value
     */
    public function add(string $key, $value, int $ttl): bool
    {
        return $this->store->add($key, $value, $ttl);
    }

    /**
     * Store an item indefinitely.
     *
     * @param mixed $value
     */
    public function forever(string $key, $value): bool
    {
        return $this->store->forever($key, $value);
    }

    /**
     * Remove an item from the store.
     */
    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }

    /**
     * Remove all items from the store.
     */
    public function flush(): bool
    {
        return $this->store->flush();
    }

    /**
     * Increment a stored integer value and return the new value.
     *
     * @return int|false
     */
    public function increment(string $key, int $by = 1)
    {
        return $this->store->increment($key, $by);
    }

    /**
     * Decrement a stored integer value and return the new value.
     *
     * @return int|false
     */
    public function decrement(string $key, int $by = 1)
    {
        return $this->store->decrement($key, $by);
    }

    /**
     * Return the cached value for a key, computing and storing it via the callback on a miss.
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Return the cached value for a key, computing and storing it forever via the callback on a miss.
     *
     * @return mixed
     */
    public function rememberForever(string $key, callable $callback)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->forever($key, $value);

        return $value;
    }

    /**
     * Retrieve an item and remove it from the store in one step.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function pull(string $key, $default = null)
    {
        $value = $this->get($key, $default);

        $this->forget($key);

        return $value;
    }
}
