<?php

namespace BitApps\WPKit\Http\Router;

use WP_REST_Controller;
use WP_REST_Server;

final class APIRouter extends WP_REST_Controller
{
    const READABLE = WP_REST_Server::READABLE;

    const CREATABLE = WP_REST_Server::CREATABLE;

    const EDITABLE = WP_REST_Server::EDITABLE;

    const DELETABLE = WP_REST_Server::DELETABLE;

    public function __construct(private Router $_router)
    {
    }

    public function registerRoutes(): void
    {
        foreach ($this->_router->getRoutes() as $route) {
            $this->addRoute($route);
        }
    }

    /**
     * Registers api route.
     *
     * @param RouteRegister $route api route
     */
    public function addRoute(RouteRegister $route): void
    {
        $args = [];
        foreach ($route->getMethods() as $method) {
            $args[] = [
                'methods'             => $this->getMethod($method),
                'callback'            => [$route, 'handleRequest'],
                'permission_callback' => $route->getPermissionCallback(),
            ];
        }

        $path   = $route->hasRegex() ? $route->regex() : $route->getPath();
        $prefix = $route->getRoutePrefix();
        if ($prefix) {
            if (!str_ends_with($prefix, '/')) {
                $path = $prefix . '/' . $path;
            } else {
                $path = $prefix . $path;
            }
        }
        register_rest_route(
            $this->_router->getNamespace(),
            $this->_router->getVersion() . $path,
            $args
        );
    }

    public function getMethod($method)
    {
        return match (strtolower($method)) {
            'get'    => self::READABLE,
            'post'   => self::CREATABLE,
            'put'    => self::EDITABLE,
            'delete' => self::DELETABLE,
            default  => self::READABLE,
        };
    }
}
