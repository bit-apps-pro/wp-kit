<?php

namespace BitApps\WPKit\Hooks;

use RuntimeException;

/**
 * A forwarder class for actions and filters.
 *
 * @method static void  doAction($tag, ...$arg)
 * @method static true  addAction($tag, callable $functionToAdd, $priority = 10, $acceptedArgs = 1)
 * @method static bool  removeAction($tag, callable $functionToRemove, $priority = 10)
 * @method static true  addFilter($tag, callable $functionToAdd, $priority = 10, $acceptedArgs = 1)
 * @method static bool  removeFilter($tag, callable $functionToRemove, $priority = 10)
 * @method static mixed applyFilter($tag, $value, ...$args)
 */
final class Hooks
{
    private static ?HooksWrapper $_hook = null;

    public function __construct()
    {
        self::$_hook ??= new HooksWrapper();
    }

    public function __call(string $method, array $parameters)
    {
        if (method_exists($this->getInstance(), $method)) {
            return \call_user_func_array([$this->getInstance(), $method], $parameters);
        }

        throw new RuntimeException('Undefined method [' . $method . '] called on Model class.');
    }

    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->{$method}(...$parameters);
    }

    public function getInstance(): ?HooksWrapper
    {
        return self::$_hook;
    }
}
