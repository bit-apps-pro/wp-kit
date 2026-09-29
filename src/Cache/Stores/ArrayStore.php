<?php

namespace BitApps\WPKit\Cache\Stores;

use BitApps\WPKit\Cache\Contracts\Store;

/**
 * In-memory Store implementation for tests and per-request caching; nothing persists across requests.
 */
final class ArrayStore implements Store
{
    /**
     * @var array<string,array{value:mixed,expiresAt:null|int}>
     */
    private array $items = [];

    /**
     * @var callable
     */
    private $clock;

    /**
     * @param null|callable $clock returns the current unix timestamp; defaults to time() but is
     *                             injectable so tests can advance it deterministically (see M4)
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * @inheritDoc
     */
    public function get(string $key)
    {
        if (!isset($this->items[$key])) {
            return;
        }

        $item = $this->items[$key];

        if ($item['expiresAt'] !== null && ($this->clock)() >= $item['expiresAt']) {
            $this->forget($key);

            return;
        }

        return $item['value'];
    }

    /**
     * @inheritDoc
     */
    public function put(string $key, $value, int $ttl): bool
    {
        $this->items[$key] = [
            'value'     => $value,
            'expiresAt' => ($this->clock)() + $ttl,
        ];

        return true;
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
        $this->items[$key] = ['value' => $value, 'expiresAt' => null];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function forget(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function increment(string $key, int $by = 1): int
    {
        $new = (int) $this->get($key) + $by;

        // Preserve an existing expiry; a freshly-created counter never expires, matching forever() semantics.
        $this->items[$key]['value'] = $new;
        $this->items[$key]['expiresAt'] ??= null;

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
