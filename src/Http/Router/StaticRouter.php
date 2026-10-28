<?php

namespace BitApps\WPKit\Http\Router;

use BitApps\WPKit\Http\RequestType;
use BitApps\WPKit\Http\Response;
use WP;

if (!\defined('ABSPATH')) {
    exit;
}

class StaticRouter
{
    /**
     * Seconds to wait before re-flushing rules that did not persist (e.g. filtered out by another plugin).
     */
    private const FLUSH_RETRY_SECONDS = 300;

    /**
     * Appended to the page name, so the transient carries the consumer's own prefix rather than a shared one.
     */
    private const FLUSH_RETRY_TRANSIENT_SUFFIX = '_rewrite_flush_retry';

    private Router $router;

    private string $pageName;

    private array $rewriteRules = [];

    private array $queryVars = [];

    private string $content = '';

    public function __construct(string $pageName, string $activationHook, string $deactivationHook, ?Router $router = null)
    {
        $this->pageName = trim($pageName, '/');
        $this->router   = $router ?: Router::instance(RequestType::STATIC_PAGE, $this->pageName);
        $this->registerHooks($activationHook, $deactivationHook);
    }

    public function flushOnActivate(): void
    {
        $this->registerRewriteRules();
        flush_rewrite_rules();
    }

    /**
     * Empties the persisted rules so the next request rebuilds them without ours; a flush here would
     * re-save them, as they are still registered on the deactivating request.
     */
    public function flushOnDeactivate(): void
    {
        update_option('rewrite_rules', '');
    }

    public function registerRewriteRules(): void
    {
        $this->processRoutes();

        if (empty($this->rewriteRules)) {
            return;
        }

        foreach ($this->rewriteRules as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }

        $this->maybeFlushRewriteRules();
    }

    public function addQueryVars($vars): array
    {
        return array_merge($vars, $this->queryVars);
    }

    /**
     * Dispatches on parse_request instead of template_redirect, before the main query runs or a 404 is
     * flagged; for routes that send their own response and exit (callbacks, webhooks), not page content.
     */
    public function dispatchOnParseRequest(): self
    {
        remove_action('template_redirect', [$this, 'handleRequest']);
        add_action('parse_request', [$this, 'handleRequest']);

        return $this;
    }

    /**
     * Runs the first route matching the request; parse_request passes the WP object, template_redirect nothing.
     *
     * @param mixed $wp
     */
    public function handleRequest($wp = null): void
    {
        $requestPath = $this->requestPath($wp instanceof WP ? $wp : ($GLOBALS['wp'] ?? null));
        $method      = strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        foreach ($this->router->getRoutes() as $route) {
            if (!\in_array($method, $route->getMethods(), true)) {
                continue;
            }

            if ($this->isRouteMatched($route, $requestPath)) {
                $result = $route->handleRequest();
                if (Response::ERROR === Response::getStatus()) {
                    return;
                }

                $this->content = (new Emitter\StaticResponseEmitter())->emit([
                    'data' => ['data' => $result],
                ]);

                // this filter needs to be added here to avoid affecting other routes
                add_filter('the_content', [$this, 'renderContent']);

                return;
            }
        }
    }

    public function renderContent(string $content): string
    {
        return $content . ($this->content ?? '');
    }

    public function loadRoutesFromFile($filePath): void
    {
        $this->router->registerFile($filePath);
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public static function isRewriteExists(?string $path = '', ?array $rewriteRules = null): bool
    {
        if (empty($path) && empty($rewriteRules)) {
            return false;
        }

        $rules = get_option('rewrite_rules');
        if (!$rules) {
            return false;
        }

        $rulesToCheck = $path ? ['^' . trim($path, '/')] : array_keys($rewriteRules);
        foreach ($rulesToCheck as $rule) {
            if (!isset($rules[$rule])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Flushes when our rules are missing from the persisted set, at most once per retry window, and never
     * under plain permalinks where rewrite rules are unused.
     */
    public function maybeFlushRewriteRules(): void
    {
        if (
            empty($this->rewriteRules)
            || !get_option('permalink_structure')
            || self::isRewriteExists('', $this->rewriteRules)
        ) {
            return;
        }

        $retryKey = $this->pageName . self::FLUSH_RETRY_TRANSIENT_SUFFIX;
        if (get_transient($retryKey) !== false) {
            return;
        }

        set_transient($retryKey, 1, self::FLUSH_RETRY_SECONDS);
        flush_rewrite_rules();
    }

    private function registerHooks(string $activationHook, string $deactivationHook): void
    {
        add_action($activationHook, [$this, 'flushOnActivate']);
        add_action($deactivationHook, [$this, 'flushOnDeactivate']);
        add_action('init', [$this, 'registerRewriteRules']);
        add_action('query_vars', [$this, 'addQueryVars']);
        add_action('template_redirect', [$this, 'handleRequest']);
    }

    /**
     * Request path relative to the site root: WP's parsed path when set (strips home and index.php),
     * else the request URI minus the home path (plain permalinks).
     */
    private function requestPath(?WP $wp): string
    {
        if ($wp !== null && \is_string($wp->request) && $wp->request !== '') {
            return sanitize_url('/' . trim($wp->request, '/'));
        }

        $path     = (string) wp_parse_url(sanitize_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
        $homePath = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');

        return $homePath !== '' && str_starts_with($path, $homePath . '/') ? substr($path, \strlen($homePath)) : $path;
    }

    private function processRoutes(): void
    {
        $ruleSet = new RewriteRuleSet($this->pageName);
        foreach ($this->router->getRoutes() as $route) {
            $ruleSet->addPath($this->routePath($route));
        }

        $this->rewriteRules = $ruleSet->rules();
        $this->queryVars    = $ruleSet->queryVars();
    }

    private function routePath(RouteRegister $route): string
    {
        $prefix = trim((string) $route->getRoutePrefix(), '/');
        $path   = trim((string) $route->getPath(), '/');

        return self::joinPath($prefix, $path);
    }

    /**
     * Joins path segments with '/', skipping empty ones so a root route leaves no trailing slash.
     */
    private static function joinPath(string ...$segments): string
    {
        return implode('/', array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }

    private function isRouteMatched(RouteRegister $route, string $requestPath): bool
    {
        $path     = self::joinPath($this->pageName, $this->routePath($route));
        $compiled = RoutePattern::compile($path);
        $pattern  = $compiled === null ? preg_quote($path, '~') : $compiled['regex'];

        if (!preg_match('~^/' . $pattern . '/?$~', $requestPath, $matches)) {
            return false;
        }

        foreach ($matches as $param => $value) {
            if (\is_string($param)) {
                $route->setRouteParamValue($param, $value);
            }
        }

        return true;
    }
}
