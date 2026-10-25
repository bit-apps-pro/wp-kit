<?php

namespace BitApps\WPKit\Http\Router;

use Closure;

use RuntimeException;

/**
 * Base class for route
 *
 * @method static RouteRegister match($methods, $path, callable$action)
 * @method static RouteRegister get($path, callable $action)
 * @method static RouteRegister post($path, callable $action)
 * @method static RouteRegister getMethods()
 * @method static RouteRegister action(callable $action)
 * @method static RouteRegister getAction()
 * @method static RouteRegister path($path)
 * @method static RouteRegister getPath()
 * @method static RouteRegister name($name)
 * @method static RouteRegister getName()
 * @method static RouteRegister isNoAuth()
 * @method static RouteRegister isTokenIgnored()
 * @method static RouteRegister regex()
 * @method static RouteRegister hasRegex()
 * @method static RouteRegister handleMiddleware()
 * @method static RouteRegister getRoutePrefix()
 * @method static RouteRegister getRouteParam($name)
 * @method static RouteRegister getRouteParams()
 * @method static RouteRegister setRouteParamValue($name, $value)
 * @method static RouteRegister getRouteParamValue($name)
 * @method static RouteRegister getRouteParamValues()
 * @method static RouteRegister setRequest(Request $request)
 * @method static RouteRegister getRequest()
 */
final class RouteBase
{
    private $_router;

    private $_prefix;

    private ?bool $_noAuth = null;

    private ?bool $_ignoreToken = null;

    private array $_middleware = [];

    /**
     * REST permission callback shared by every route in this group.
     *
     * @var null|callable
     */
    private $_permission;

    private static ?self $_isGrouped = null;

    /**
     * Handle static call to route.
     *
     * @param string $method     Name of method from RouteBase
     * @param mixed  $parameters Params to pass
     *
     * @return RouteRegister
     */
    public function __call(string $method, array $parameters)
    {
        if (method_exists(RouteRegister::class, $method)) {
            $route = \call_user_func_array([$this->getRegistrar(), $method], $parameters);
            $this->getRouter()->addRoute($route);

            return $route;
        }

        throw new RuntimeException('Undefined method [' . $method . '] called on ' . self::class . ' class.');
    }

    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->{$method}(...$parameters);
    }

    /**
     * Sets prefix for route.
     *
     * @param string $prefix prefix for route or route group
     *
     * @return RouteBase
     */
    public function prefix($prefix): self
    {
        $this->_prefix = $prefix;

        return $this;
    }

    /**
     * Sets if ajax route is also for frontend request.
     *
     * @return RouteBase
     */
    public function noAuth(): self
    {
        $this->_noAuth = true;

        return $this;
    }

    /**
     * Returns if ajax route is also for frontend request.
     *
     * @return bool
     */
    public function isNoAuth(): ?bool
    {
        return $this->_noAuth;
    }

    /**
     * Checks if token ignored for the request.
     *
     * @return bool
     */
    public function isTokenIgnored(): ?bool
    {
        return $this->_ignoreToken;
    }

    /**
     * Sets if token ignored for request.
     *
     * @return RouteBase
     */
    public function ignoreToken(): self
    {
        $this->_ignoreToken = true;

        return $this;
    }

    /**
     * Sets middleware for route.
     *
     * @return RouteBase
     */
    public function middleware(): self
    {
        $this->_middleware = array_merge($this->_middleware, \func_get_args());

        return $this;
    }

    /**
     * Returns middleware for this request.
     *
     * @return []
     */
    public function getMiddleware(): array
    {
        return $this->_middleware;
    }

    /**
     * Sets the REST permission callback for every route in this group.
     *
     * @param callable $callback receives the WP_REST_Request; return true, false or WP_Error
     *
     * @return RouteBase
     */
    public function permission(callable $callback): self
    {
        $this->_permission = $callback;

        return $this;
    }

    /**
     * Returns the group's REST permission callback, if any.
     */
    public function getPermission(): ?callable
    {
        return $this->_permission;
    }

    /**
     * Return  prefix for request.
     *
     * @return null|string
     */
    public function getRoutePrefix()
    {
        return $this->_prefix;
    }

    /**
     * Sets routes to a group using closure.
     *
     * @param Closure $callback function to set multiple route
     *
     * @return $this
     */
    public function group(Closure $callback): self
    {
        self::$_isGrouped = $this;
        $callback();
        self::$_isGrouped = null;

        return $this;
    }

    /**
     * Provides router for this route.
     *
     * @return Router
     */
    public function getRouter()
    {
        if (!isset($this->_router)) {
            $this->_router = Router::instance();
        }

        return $this->_router;
    }

    /**
     * Provides registrar for this route.
     *
     * @return RouteRegister
     */
    private function getRegistrar(): RouteRegister
    {
        $instance = $this;
        if (!\is_null(self::$_isGrouped)) {
            $instance = self::$_isGrouped;
        }

        return new RouteRegister($instance);
    }
}
