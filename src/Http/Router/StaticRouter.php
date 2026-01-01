<?php

namespace BitApps\BitConnect\Providers;

use BitApps\WPKit\Http\Router\Router;
use BitApps\WPKit\Http\Router\RouteRegister;

if (!\defined('ABSPATH')) {
    exit;
}

class StaticRouter
{
    private Router $router;

    private array $rewriteRules = [];

    private array $queryVars = [];

    private string $content;

    public function __construct(
        private string $pageName,
        private string $activationHook,
        private string $deactivationHook
    ) {
        $this->router = Router::instance('static', $this->pageName);
        $this->registerHooks();
    }

    public function flushOnActivate()
    {
        $this->registerRewriteRules();
        flush_rewrite_rules();
    }

    public function flushOnDeactivate()
    {
        flush_rewrite_rules();
    }

    public function registerRewriteRules()
    {
        $this->processRoutes();

        if (empty($this->rewriteRules)) {
            return;
        }


        foreach ($this->rewriteRules as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    public function addQueryVars($vars)
    {
        if (empty($this->rewriteRules)) {
            return $vars;
        }
        $this->maybeFlashRewriteRules();
        $uniqueQueryVars = array_unique($this->queryVars);

        return array_merge($vars, $uniqueQueryVars);
    }

    public function handleRequest()
    {
        $requestPath = sanitize_url($_SERVER['REQUEST_URI']) ?? '';
        $pageName = trim($this->pageName, '/');
        foreach ($this->router->getRoutes() as $route) {
            /**
             * RouteRegister instance to check against.
             *
             * @var RouteRegister $route
             */
            $path = '/' . $pageName . '/' . trim($route->getPath(), '/');
            $prefix = $route->getRoutePrefix();

            if ($prefix) {
                $path = $prefix . '/' . $path;
            }
            if ($this->isRouteMatched($path, $requestPath)) {
                $this->setRouteParameters($route, $requestPath);
                $this->content = $route->handleRequest();

                return;
            }
        }
    }

    public function renderContent(string $content): string
    {
        return $content . $this->content ?? $content;
    }

    public function loadRoutesFromFile($filePath)
    {
        $this->router->registerFile($filePath);
    }

    public function getRouter()
    {
        return $this->router;
    }

    public static function isRewriteExists(?string $path = '', ?array $rewriteRules = null): bool
    {
        if (empty($path) && empty($rewriteRules)) {
            return false;
        }

        $rules = get_option('rewrite_rules');
        $ignorePatterns = ['(.?.+?)(?:/([0-9]+))?/?$', '([^/]+)(?:/([0-9]+))?/?$'];
        $rulesToCheck = $path ? ['^' . trim($path, '/')] : array_keys($rewriteRules);

        if ($rules) {
            $patterns = array_keys($rules);
            foreach ($patterns as $pattern) {
                if (!\in_array($pattern, $ignorePatterns, true) && \in_array($pattern, $rulesToCheck, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function maybeFlashRewriteRules()
    {
        if (empty($this->rewriteRules) || $this->isRewriteExists(rewriteRules: $this->rewriteRules)) {
            // error_log('Rewrite rules already exist, skipping flush.');
            return;
        }

        flush_rewrite_rules();
    }

    private function registerHooks()
    {
        add_action($this->activationHook, [$this, 'flushOnDeactivate']);
        add_action($this->deactivationHook, [$this, 'flushOnActivate']);
        add_action('init', [$this, 'registerRewriteRules']);
        add_action('query_vars', [$this, 'addQueryVars']);
        add_filter('the_content', [$this, 'renderContent']);
        add_action('template_redirect', [$this, 'handleRequest']);
    }

    private function processRoutes()
    {
        $routes = $this->router->getRoutes();

        foreach ($routes as $route) {
            /**
             * RouteRegister instance to process.
             *
             * @var RouteRegister $route
             */
            $path = $route->getPath();
            $prefix = $route->getRoutePrefix();

            if ($prefix) {
                $path = $prefix . '/' . $path;
            }

            $this->makeRewriteRuleForPath($path);
        }
    }

    private function isRouteMatched($routePath, $requestPath)
    {
        // Replace route parameters with regex pattern for matching
        $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $routePath);
        $pattern = '^' . $pattern . '/?$';

        return preg_match('~' . $pattern . '~', $requestPath);
    }

    private function setRouteParameters(RouteRegister $route, $requestPath)
    {
        $path = $route->getPath();
        $prefix = $route->getRoutePrefix();

        if ($prefix) {
            $path = $prefix . '/' . $path;
        }

        $cleanPath = trim($path, '/');

        preg_match_all('/\{(\w+)\}/', $cleanPath, $matches);
        $routeParams = $matches[1];

        if (empty($routeParams)) {
            return;
        }

        $regex = '~^' . preg_replace('/\{(\w+)\}/', '([^/]+)', $cleanPath) . '~';

        if (preg_match($regex, $requestPath, $matchedValues)) {
            // Skip the full match at index 0
            array_shift($matchedValues);

            foreach ($routeParams as $i => $param) {
                if (isset($matchedValues[$i])) {
                    $route->setRouteParamValue($param, $matchedValues[$i]);
                }
            }
        }
    }

    private function makeRewriteRuleForPath(string $path)
    {
        preg_match_all('/\{\w+\??\}\??/', $path, $regexMatched);
        $pagename = trim($this->pageName, '/');
        $path = $pagename . '/' . trim($path, '/') . '/';
        $this->rewriteRules = [];
        $this->rewriteRules["^{$pagename}/?$"] = "index.php?pagename={$pagename}";
        $matchCount = 1;
        $previousPath = "^{$pagename}/?$";
        while ($param = array_shift($regexMatched[0])) {
            $param = trim($param, '{}?');
            $pathChunk = substr($path, 0, strpos($path, "{{$param}}"));
            $pathChunkWithoutParam = '^' . $pathChunk . '?$';
            $pathChunkWitParam = '^' . $pathChunk . '([^/]+)/?$';

            $path = str_replace("{{$param}}", '([^/]+)', $path);
            if (!isset($this->rewriteRules[$pathChunkWithoutParam]) && strpos($pathChunkWithoutParam, '([^/]+)')) {
                $previousPath = trim(substr($pathChunkWithoutParam, 0, strpos($pathChunkWithoutParam, '([^/]+)') + \strlen('([^/]+)') + 1), '/') . '/?$';
            }
            $this->rewriteRules[$pathChunkWithoutParam] = $this->rewriteRules[$previousPath];
            $this->rewriteRules[$pathChunkWitParam] = $this->rewriteRules[$pathChunkWithoutParam] . "&{$param}=\$matches[{$matchCount}]";
            ++$matchCount;
            $this->queryVars[] = $param;
        }
    }
}
