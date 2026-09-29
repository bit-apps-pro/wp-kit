<?php

namespace BitApps\WPKit\Container;

/**
 * Base class for service providers: registers bindings on the container and optionally boots them.
 */
abstract class ServiceProvider
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * Register bindings on the container.
     */
    abstract public function register(): void;

    /**
     * Run after all providers are registered; override to perform post-registration setup.
     */
    public function boot(): void
    {
    }
}
