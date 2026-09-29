<?php

namespace BitApps\WPKit\Cache;

use BitApps\WPKit\Cache\Contracts\Store;
use BitApps\WPKit\Cache\Stores\ArrayStore;
use BitApps\WPKit\Cache\Stores\FileStore;
use BitApps\WPKit\Cache\Stores\TransientStore;
use BitApps\WPKit\Cache\Stores\WpObjectCacheStore;
use InvalidArgumentException;

/**
 * Resolves named cache stores (array|transient|object|file) from a single config array into
 * memoised Repository instances, so callers always get the same Repository for a given name.
 */
final class CacheManager
{
    /**
     * @var array<string,Repository>
     */
    private array $repositories = [];

    /**
     * @param array{default?:string,prefix?:string,stores?:array<string,array<string,mixed>>} $config
     */
    public function __construct(private array $config = [])
    {
    }

    /**
     * Resolve (and memoise) the Repository for a named store, or the configured default when null.
     */
    public function store(?string $name = null): Repository
    {
        $name ??= $this->defaultStoreName();

        if (!isset($this->repositories[$name])) {
            $this->repositories[$name] = new Repository($this->makeStore($name));
        }

        return $this->repositories[$name];
    }

    /**
     * Builds the raw Store backend for a store name, namespaced with the manager's prefix.
     */
    private function makeStore(string $name): Store
    {
        return match ($name) {
            'array'     => new ArrayStore(),
            'transient' => new TransientStore($this->prefix()),
            'object'    => new WpObjectCacheStore($this->objectCacheGroup()),
            'file'      => new FileStore($this->fileStorePath(), $this->prefix()),
            default     => throw new InvalidArgumentException("Unknown cache store [{$name}]."),
        };
    }

    private function defaultStoreName(): string
    {
        return $this->config['default'] ?? 'transient';
    }

    private function prefix(): string
    {
        return $this->config['prefix'] ?? '';
    }

    private function objectCacheGroup(): string
    {
        $group = $this->config['stores']['object']['group'] ?? $this->prefix();

        return $group !== '' ? $group : 'default';
    }

    /**
     * Resolves the configured `stores.file.path`, throwing when the `file` store is requested
     * without one — FileStore has no directory to fall back to.
     */
    private function fileStorePath(): string
    {
        $path = $this->config['stores']['file']['path'] ?? null;

        if (!\is_string($path) || $path === '') {
            throw new InvalidArgumentException('Cache store [file] requires a configured stores.file.path.');
        }

        return $path;
    }
}
