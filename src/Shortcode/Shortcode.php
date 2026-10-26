<?php

namespace BitApps\WPKit\Shortcode;

use RuntimeException;

/**
 * A forwarder class for actions and filters.
 *
 * @method static void doShortcode( $content, $ignoreHtml = false )
 * @method static void addShortcode($tag, callable $callback)
 * @method static void removeShortcode($tag)
 * @method static bool shortcodeExists($tag)
 * @method static bool hasShortcode($content, $tag)
 */
final class Shortcode
{
    private static ?ShortcodeWrapper $_wrapper = null;

    public function __construct()
    {
        self::$_wrapper ??= new ShortcodeWrapper();
    }

    public function __call(string $method, array $parameters)
    {
        if (method_exists($this->getInstance(), $method)) {
            return \call_user_func_array([$this->getInstance(), $method], $parameters);
        }

        throw new RuntimeException('Undefined method [' . $method . '] called on ' . self::class . ' class.');
    }

    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->{$method}(...$parameters);
    }

    public function getInstance(): ?ShortcodeWrapper
    {
        return self::$_wrapper;
    }
}
