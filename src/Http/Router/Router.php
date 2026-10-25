<?php

namespace BitApps\WPKit\Http\Router;

use BitApps\WPKit\Http\RequestType;

final class Router
{
    private array $_routes = [];

    private array $_registeredRoutes = [];

    private MiddlewareRegistry $_middlewareRegistry;

    private static ?self $_instance = null;

    // keyed by type only — two routers of the same type share one slot, last constructed wins
    private static array $_registry = [];

    public function __construct(private $_requestType, private $_namespace, private $_version)
    {
        $this->_middlewareRegistry            = new MiddlewareRegistry();
        self::$_instance                      = $this;
        self::$_registry[$this->_requestType] = $this;
    }

    public function getRequestType()
    {
        return $this->_requestType;
    }

    public function getVersion(): string
    {
        return empty($this->_version) ? '' : $this->_version . '/';
    }

    public function getNamespace()
    {
        return $this->_namespace;
    }

    public function getAjaxPrefix(): string
    {
        return $this->getNamespace() . (empty($this->_version) ? '' : '/' . $this->_version);
    }

    public function getRoutes(): array
    {
        return $this->_routes;
    }

    public function getRoute($routeIndex)
    {
        return $this->_routes[$routeIndex] ?? null;
    }

    public function addRoute(RouteRegister $route): void
    {
        $this->_routes[] = $route;
    }

    public function addRegisteredRoute($name, RouteRegister $route): void
    {
        $this->_registeredRoutes[$name] = $route;
    }

    public function getRegisteredRoute($routeName)
    {
        return $this->_registeredRoutes[$routeName] ?? null;
    }

    public function getRegisteredRoutes(): array
    {
        return $this->_registeredRoutes;
    }

    public static function instance($type = null, $namespace = null, $version = null)
    {
        if ($type === null) {
            self::$_instance ??= new self(RequestType::AJAX, $namespace, $version);

            return self::$_instance;
        }

        // creates, registers, AND makes the new router current — declare routes before constructing transports
        return self::$_registry[$type] ?? new self($type, $namespace, $version);
    }

    public static function reset(): void
    {
        self::$_instance = null;
        self::$_registry = [];
    }

    public function registerFile($routeFile): void
    {
        self::$_instance = $this;

        include_once $routeFile;
    }

    public function register(): void
    {
        if ($this->getRequestType() === RequestType::AJAX) {
            $ajaxRouter = new AjaxRouter($this);
            $ajaxRouter->registerRoutes();
        } elseif ($this->getRequestType() === RequestType::API) {
            $ajaxRouter = new APIRouter($this);
            $ajaxRouter->registerRoutes();
        }
    }

    public function setMiddlewares($middlewares): void
    {
        $this->_middlewareRegistry->register($middlewares);
    }

    public function getRegisteredMiddleware($name)
    {
        return $this->_middlewareRegistry->resolve($name);
    }
}
