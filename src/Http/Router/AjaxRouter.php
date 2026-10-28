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
        $requestMethod = self::requestMethod();
        $action        = self::requestAction();

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
        return self::requestMethod() . self::requestAction();
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

    /**
     * Sanitized HTTP method of the current request, or '' when absent.
     */
    private static function requestMethod(): string
    {
        return isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    }

    /**
     * Sanitized admin-ajax action of the current request, or '' when absent.
     */
    private static function requestAction(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing on the action only; handlers verify nonces.
        return isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
    }
}
