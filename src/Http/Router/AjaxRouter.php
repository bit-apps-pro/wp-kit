<?php

namespace BitApps\WPKit\Http\Router;

use BitApps\WPKit\Hooks\Hooks;

/**
 * Base Router.
 *
 * @var Router $_route
 */
final class AjaxRouter
{
    public function __construct(private Router $_router)
    {
    }

    public function registerRoutes(): void
    {
        foreach ($this->_router->getRoutes() as $route) {
            $this->addRoute($route);
        }
    }

    public function addRoute(RouteRegister $route): void
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field($_SERVER['REQUEST_METHOD']) : '';
        $action        = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';

        if (!str_contains($action, $route->getRouter()->getAjaxPrefix())
            || !\in_array(strtoupper($requestMethod), $route->getMethods())
        ) {
            return;
        }

        $requestPath = str_replace($route->getRouter()->getAjaxPrefix(), '', $action);
        if (!$this->isRouteMatched($route, $requestPath)) {
            return;
        }

        Hooks::addAction('wp_ajax_' . $action, [$route, 'handleRequest']);
        if ($route->isNoAuth()) {
            Hooks::addAction('wp_ajax_nopriv_' . $action, [$route, 'handleRequest']);
        }

        $route->getRouter()->addRegisteredRoute($this->currentRouteName(), $route);
    }

    public function currentRouteName(): string
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field($_SERVER['REQUEST_METHOD']) : '';
        $action        = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';

        return $requestMethod . $action;
    }

    /**
     * Returns current registered route.
     *
     * @return RouteRegister
     */
    public function currentRoute()
    {
        return $this->_router->getRegisteredRoute($this->currentRouteName());
    }

    private function isRouteMatched(RouteRegister $route, string|array $requestPath): bool
    {
        if ($route->getRoutePrefix() . $route->getPath() === $requestPath) {
            return true;
        }

        if (
            !$route->hasRegex()
            || preg_match('~^(?|' . $route->regex() . ')$~x', $requestPath, $matchedRoutes) === false
            || empty($matchedRoutes)
        ) {
            return false;
        }

        foreach ($route->getRouteParams() as $param => $attribute) {
            if (isset($matchedRoutes[$param])) {
                $route->setRouteParamValue($param, $matchedRoutes[$param]);
            }
        }

        return $matchedRoutes[0] === $requestPath;
    }
}
