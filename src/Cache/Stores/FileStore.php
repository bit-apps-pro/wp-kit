<?php

namespace BitApps\WPKit\Cache\Stores;

use BitApps\WPKit\Cache\Contracts\Store;

/**
 * Store backed by serialized files on disk; each entry lives in its own file named by a hash of the key.
 */
final class FileStore implements Store
{
    private string $directory;

    /**
     * @var callable
     */
    private $clock;

    /**
     * @param null|callable $clock returns the current unix timestamp; defaults to time() but is
     *                             injectable so tests can advance it deterministically (see M4)
     */
    public function __construct(string $directory, string $prefix = '', ?callable $clock = null)
    {
        // Scope each prefix to its own subdirectory so flush() only clears THIS store's entries,
        // letting sibling FileStores share a base directory without wiping each other.
        $this->directory = rtrim($directory, '/\\') . '/' . sha1($prefix);
        $this->clock     = $clock ?? 'time';

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $key)
    {
        $entry = $this->read($key);

        return $entry === null ? null : $entry['value'];
    }

    /**
     * @inheritDoc
     */
    public function put(string $key, $value, int $ttl): bool
    {
        return $this->write($key, $value, ($this->clock)() + $ttl);
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
        return $this->write($key, $value, null);
    }

    /**
     * @inheritDoc
     */
    public function forget(string $key): bool
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return false;
        }

        return unlink($path);
    }

    /**
     * @inheritDoc
     */
    public function flush(): bool
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function increment(string $key, int $by = 1): int
    {
        $entry = $this->read($key);
        $new   = (int) ($entry['value'] ?? 0) + $by;

        // Preserve an existing expiry; a freshly-created counter never expires, matching forever() semantics.
        $this->write($key, $new, $entry['expiresAt'] ?? null);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }

    /**
     * Maps a cache key to its on-disk file path within this store's prefix-scoped subdirectory.
     */
    private function path(string $key): string
    {
        return $this->directory . '/' . sha1($key);
    }

    /**
     * Reads and decodes the entry file for a key, evicting and returning null if it has expired.
     *
     * @return null|array{expiresAt:null|int,value:mixed}
     */
    private function read(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $entry = @unserialize(file_get_contents($path));

        if (!\is_array($entry) || !\array_key_exists('value', $entry) || !\array_key_exists('expiresAt', $entry)) {
            return null;
        }

        if ($entry['expiresAt'] !== null && ($this->clock)() >= $entry['expiresAt']) {
            $this->forget($key);

            return null;
        }

        return $entry;
    }

    /**
     * Atomically writes an entry via a temp file + rename, avoiding partial reads by concurrent processes.
     *
     * @param mixed $value
     */
    private function write(string $key, $value, ?int $expiresAt): bool
    {
        $path = $this->path($key);
        $tmp  = $path . '.' . uniqid('', true) . '.tmp';

        if (file_put_contents($tmp, serialize(['expiresAt' => $expiresAt, 'value' => $value])) === false) {
            return false;
        }

        if (!rename($tmp, $path)) {
            unlink($tmp);

            return false;
        }

        return true;
    }
}
