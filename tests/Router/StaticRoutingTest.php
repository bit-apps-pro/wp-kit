<?php

namespace BitApps\WPKit\Tests\Router;

use BitApps\WPKit\Http\Response;
use BitApps\WPKit\Http\Router\RouteBase;
use BitApps\WPKit\Http\Router\Router;
use BitApps\WPKit\Http\Router\StaticRouter;
use BitApps\WPKit\Http\Router\Emitter\StaticResponseEmitter;
use BitApps\WPKit\Tests\TestCase;
use UnexpectedValueException;
use WpKitTestState;

final class StaticDenyMiddleware
{
    public function handle()
    {
        return Response::error([])->code('DENIED')->message('Denied');
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class StaticRoutingTest extends TestCase
{
    public function testStaticPostRouteDoesNotExecuteOnGet(): void
    {
        $called = false;
        new Router('static', 'landing', null);
        (new RouteBase())->post('submit', static function () use (&$called) {
            $called = true;

            return 'submitted';
        });
        new StaticRouter('landing', 'plugin_activate', 'plugin_deactivate');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/landing/submit';

        do_action('template_redirect');

        assertSameValue(false, $called, 'POST static action executed for GET');
    }

    public function testDeniedStaticRouteDoesNotRenderErrorDataAsHtml(): void
    {
        $called = false;
        $router = new Router('static', 'landing', null);
        $router->setMiddlewares(['deny' => StaticDenyMiddleware::class]);
        (new RouteBase())->middleware('deny')->get('protected', static function () use (&$called) {
            $called = true;

            return 'secret';
        });
        new StaticRouter('landing', 'plugin_activate', 'plugin_deactivate');
        $_SERVER['REQUEST_URI'] = '/landing/protected';

        do_action('template_redirect');

        assertSameValue(false, $called, 'denied static action executed');
        assertSameValue('page', apply_filters('the_content', 'page'), 'denial data was rendered as page HTML');
    }

    public function testNullStaticOutputRendersAsEmptyContent(): void
    {
        $this->makeTransport(['empty' => static fn () => null]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/landing/empty';

        do_action('template_redirect');

        assertSameValue('page', apply_filters('the_content', 'page'), 'null output did not normalize to empty HTML');
    }

    public function testArrayStaticOutputFailsWithAContractException(): void
    {
        assertThrows(
            UnexpectedValueException::class,
            static fn () => (new StaticResponseEmitter())->emit(['data' => ['data' => ['invalid']]]),
            'array static output was accepted',
        );
    }

    public function testRewriteRulesMapPageAndParameterSegmentsToQueryVars(): void
    {
        $this->makeTransport(['entries/{id}' => static function () {
            return 'ok';
        }]);

        do_action('init');

        $rules = WpKitTestState::$rewriteRules;
        assertTest(!isset($rules['^landing/?$']), 'page root rule was registered without an empty-path route');
        assertSameValue('index.php?pagename=landing&id=$matches[1]', $rules['^landing/entries/([^/]+)/?$']['query'] ?? null, 'parameter rewrite rule changed');
        assertTest(!isset($rules['^landing/entries/?$']), 'undeclared intermediate route was registered');
    }

    public function testRewriteRulesForMultiParameterRouteChainQueryVars(): void
    {
        $this->makeTransport(['books/{author}/chapters/{chapter}' => static function () {
            return 'ok';
        }]);

        do_action('init');

        $rules = WpKitTestState::$rewriteRules;
        assertSameValue(
            'index.php?pagename=landing&author=$matches[1]&chapter=$matches[2]',
            $rules['^landing/books/([^/]+)/chapters/([^/]+)/?$']['query'] ?? null,
            'full rewrite rule did not chain both parameters',
        );
        assertTest(!isset($rules['^landing/books/([^/]+)/chapters/?$']), 'undeclared intermediate route was registered');
    }

    public function testInitWithoutRoutesRegistersNoRulesAndSkipsFlush(): void
    {
        $this->makeTransport();

        do_action('init');

        assertSameValue([], WpKitTestState::$rewriteRules, 'rules were registered without any routes');
        assertSameValue(0, WpKitTestState::$rewriteFlushes, 'rewrite rules were flushed without any routes');
    }

    public function testRewriteRulesForEveryRouteAreRegisteredTogether(): void
    {
        $this->makeTransport([
            'alpha/{a}' => static function () {
                return 'a';
            },
            'beta/{b}' => static function () {
                return 'b';
            },
        ]);

        do_action('init');

        assertTest(isset(WpKitTestState::$rewriteRules['^landing/alpha/([^/]+)/?$']), 'first route lost its rewrite rule');
        assertTest(isset(WpKitTestState::$rewriteRules['^landing/beta/([^/]+)/?$']), 'second route lost its rewrite rule');
    }

    public function testQueryVarsGainEachRouteParameterOnce(): void
    {
        $transport = $this->makeTransport([
            'entries/{id}' => static function () {
                return 'a';
            },
            'authors/{id}' => static function () {
                return 'b';
            },
        ]);

        do_action('init');

        assertSameValue(['page', 'id'], $transport->addQueryVars(['page']), 'route parameters were not merged into query vars exactly once');
    }

    public function testMatchedRequestRendersRouteOutputThroughContentFilter(): void
    {
        $this->makeTransport(['entries/{id}' => static function ($id) {
            return '<section>entry ' . $id . '</section>';
        }]);
        $_SERVER['REQUEST_URI'] = '/landing/entries/42';

        do_action('template_redirect');

        assertSameValue(
            '<main>page</main><section>entry 42</section>',
            apply_filters('the_content', '<main>page</main>'),
            'matched static route output was not appended to page content',
        );
    }

    public function testOptionalParameterRouteCapturesProvidedValue(): void
    {
        $this->makeTransport(['entries/{slug?}' => static function ($slug) {
            return 'entry ' . $slug;
        }]);
        $_SERVER['REQUEST_URI'] = '/landing/entries/hello';

        do_action('template_redirect');

        assertSameValue('page-entry hello', apply_filters('the_content', 'page-'), 'optional param value was not captured');
    }

    public function testOptionalParameterRouteMatchesWithoutValue(): void
    {
        $this->makeTransport(['entries/{slug?}' => static function ($slug) {
            return 'x';
        }]);
        $_SERVER['REQUEST_URI'] = '/landing/entries/';

        do_action('template_redirect');

        assertTest(isset(WpKitTestState::$filters['the_content']), 'optional-param route did not match without a value');
    }

    public function testOptionalParameterRouteMatchesWithoutTrailingSlash(): void
    {
        $this->makeTransport(['entries/{slug?}' => static function ($slug) {
            return 'x';
        }]);
        $_SERVER['REQUEST_URI'] = '/landing/entries';

        do_action('template_redirect');

        assertTest(isset(WpKitTestState::$filters['the_content']), 'optional-param route did not match without a trailing slash');
    }

    public function testMatchedRequestIgnoresQueryString(): void
    {
        $this->makeTransport(['entries/{id}' => static function ($id) {
            return 'entry ' . $id;
        }]);
        $_SERVER['REQUEST_URI'] = '/landing/entries/42?utm_source=mail&preview=1';

        do_action('template_redirect');

        assertSameValue('page-entry 42', apply_filters('the_content', 'page-'), 'query string broke static route matching');
    }

    public function testUnmatchedRequestDoesNotTouchPageContent(): void
    {
        $this->makeTransport(['entries/{id}' => static function () {
            return 'x';
        }]);
        $_SERVER['REQUEST_URI'] = '/elsewhere/7';

        do_action('template_redirect');

        assertTest(!isset(WpKitTestState::$filters['the_content']), 'content filter was registered for an unmatched request');
    }

    public function testActivationRegistersRewriteRulesAndFlushes(): void
    {
        $this->makeTransport(['entries/{id}' => static function () {
            return 'x';
        }]);

        do_action('plugin_activate');

        assertTest(isset(WpKitTestState::$rewriteRules['^landing/entries/([^/]+)/?$']), 'activation did not register route rewrite rules before flushing');
        assertTest(WpKitTestState::$rewriteFlushes > 0, 'activation did not flush rewrite rules');
    }

    public function testDeactivationClearsPersistedRulesInsteadOfFlushing(): void
    {
        $this->makeTransport(['entries/{id}' => static function () {
            return 'x';
        }]);
        WpKitTestState::$options['rewrite_rules'] = ['^landing/entries/([^/]+)/?$' => 'index.php?pagename=landing&id=$matches[1]'];

        do_action('plugin_deactivate');

        assertSameValue([], WpKitTestState::$rewriteRules, 'deactivation registered rewrite rules');
        assertSameValue(0, WpKitTestState::$rewriteFlushes, 'deactivation flushed, which re-saves rules still registered on this request');
        assertSameValue('', WpKitTestState::$options['rewrite_rules'], 'persisted rules were not cleared for regeneration');
    }

    public function testDeactivationAfterInitDoesNotPersistTheStillRegisteredRules(): void
    {
        $this->makeTransport(['entries/{id}' => static function () {
            return 'x';
        }]);
        WpKitTestState::$options['rewrite_rules'] = ['^landing/entries/([^/]+)/?$' => 'index.php?pagename=landing&id=$matches[1]'];

        do_action('init');
        do_action('plugin_deactivate');

        assertTest(isset(WpKitTestState::$rewriteRules['^landing/entries/([^/]+)/?$']), 'init did not register the route rule');
        assertSameValue(0, WpKitTestState::$rewriteFlushes, 'deactivation re-persisted the registered rules');
        assertSameValue('', WpKitTestState::$options['rewrite_rules'], 'route rule outlived deactivation');
    }

    public function testInitFlushesOnceWhenStaticRulesAreMissingFromPersistedRules(): void
    {
        $this->makeTransport(['entries/{id}' => static function () {
            return 'x';
        }]);

        do_action('init');

        assertSameValue(1, WpKitTestState::$rewriteFlushes, 'missing persisted rules did not trigger exactly one flush');
    }

    public function testInitSkipsFlushWhenStaticRulesAlreadyPersisted(): void
    {
        $this->makeTransport(['entries/{id}' => static function () {
            return 'x';
        }]);
        WpKitTestState::$options['rewrite_rules'] = [
            '^landing/?$'                 => 'index.php?pagename=landing',
            '^landing/entries/([^/]+)/?$' => 'index.php?pagename=landing&id=$matches[1]',
        ];

        do_action('init');

        assertSameValue(0, WpKitTestState::$rewriteFlushes, 'already persisted rules still triggered a flush');
    }

    public function testIsRewriteExistsChecksPersistedRulesByPath(): void
    {
        WpKitTestState::$options['rewrite_rules'] = ['^landing/custom' => 'index.php?pagename=landing'];

        assertTest(StaticRouter::isRewriteExists('landing/custom'), 'persisted path rule was not found');
        assertTest(!StaticRouter::isRewriteExists('landing/other'), 'missing path rule was reported as existing');
        assertTest(!StaticRouter::isRewriteExists(''), 'empty path was reported as existing');
    }

    public function testPersistedRewriteCheckRequiresEveryRule(): void
    {
        WpKitTestState::$options['rewrite_rules'] = [
            '^landing/?$' => 'index.php?pagename=landing',
        ];

        assertTest(
            !StaticRouter::isRewriteExists('', [
                '^landing/?$'       => 'index.php?pagename=landing',
                '^landing/about/?$' => 'index.php?pagename=landing',
            ]),
            'partial rewrite set was accepted as complete',
        );
    }

    public function testEmptyPathRouteRegistersThePageRootRule(): void
    {
        $this->makeTransport(['/' => static fn () => 'home']);

        do_action('init');

        assertSameValue('index.php?pagename=landing', WpKitTestState::$rewriteRules['^landing/?$']['query'] ?? null, 'empty-path route lost the page root rule');
    }

    public function testSubdirectoryInstallMatchesTheParsedWordPressPath(): void
    {
        $this->makeTransport(['entries/{id}' => static fn ($id) => 'entry ' . $id]);
        WpKitTestState::$options['home'] = 'https://example.test/blog';
        $_SERVER['REQUEST_URI']          = '/blog/landing/entries/42';
        $GLOBALS['wp']                   = new \WP();
        $GLOBALS['wp']->request          = 'landing/entries/42';

        do_action('template_redirect');

        assertSameValue('page-entry 42', apply_filters('the_content', 'page-'), 'subdirectory request did not match');
    }

    public function testPathInfoPermalinkMatchesTheParsedWordPressPath(): void
    {
        $this->makeTransport(['entries/{id}' => static fn ($id) => 'entry ' . $id]);
        $_SERVER['REQUEST_URI'] = '/index.php/landing/entries/7';
        $GLOBALS['wp']          = new \WP();
        $GLOBALS['wp']->request = 'landing/entries/7';

        do_action('template_redirect');

        assertSameValue('page-entry 7', apply_filters('the_content', 'page-'), 'index.php permalink request did not match');
    }

    public function testWithoutAParsedPathTheHomePathIsStrippedFromTheRequestUri(): void
    {
        $this->makeTransport(['entries/{id}' => static fn ($id) => 'entry ' . $id]);
        WpKitTestState::$options['home'] = 'https://example.test/blog';
        $_SERVER['REQUEST_URI']          = '/blog/landing/entries/42?preview=1';
        $GLOBALS['wp']                   = new \WP();

        do_action('template_redirect');

        assertSameValue('page-entry 42', apply_filters('the_content', 'page-'), 'home path was not stripped from the request URI');
    }

    public function testRequestsAreDispatchedOnTemplateRedirectByDefault(): void
    {
        $calls = 0;
        $this->makeTransport(['ping' => static function () use (&$calls) {
            ++$calls;

            return 'pong';
        }]);
        $GLOBALS['wp']          = new \WP();
        $GLOBALS['wp']->request = 'landing/ping';

        do_action('parse_request', $GLOBALS['wp']);
        assertSameValue(0, $calls, 'default router dispatched on parse_request');

        do_action('template_redirect');
        assertSameValue(1, $calls, 'default router did not dispatch on template_redirect');
    }

    public function testEarlyDispatchHandlesRequestsOnParseRequestOnly(): void
    {
        $calls = 0;
        $this->makeTransport(['ping' => static function () use (&$calls) {
            ++$calls;

            return 'pong';
        }])->dispatchOnParseRequest();
        $wp          = new \WP();
        $wp->request = 'landing/ping';

        do_action('parse_request', $wp);
        do_action('template_redirect');

        assertSameValue(1, $calls, 'early dispatch did not run exactly once on parse_request');
    }

    public function testInitSkipsFlushWithPlainPermalinks(): void
    {
        $this->makeTransport(['entries/{id}' => static fn () => 'x']);
        WpKitTestState::$options['permalink_structure'] = '';

        do_action('init');

        assertSameValue(0, WpKitTestState::$rewriteFlushes, 'rules were flushed although plain permalinks never use them');
    }

    public function testInitRetriesMissingRulesAtMostOncePerRetryWindow(): void
    {
        $this->makeTransport(['entries/{id}' => static fn () => 'x']);

        do_action('init');
        do_action('init');
        assertSameValue(1, WpKitTestState::$rewriteFlushes, 'rules that never persist were flushed on every request');

        WpKitTestState::$currentTime = '2024-01-02 03:10:05';
        do_action('init');
        assertSameValue(2, WpKitTestState::$rewriteFlushes, 'missing rules were not retried after the window');
    }

    public function testTemplateRedirectEmptyArgumentFallsBackToTheGlobalWp(): void
    {
        $transport = $this->makeTransport(['entries/{id}' => static fn ($id) => 'entry ' . $id]);
        WpKitTestState::$options['home'] = 'https://example.test/blog';
        $_SERVER['REQUEST_URI']          = '/blog/landing/entries/9';
        $GLOBALS['wp']                   = new \WP();
        $GLOBALS['wp']->request          = 'landing/entries/9';

        $transport->handleRequest('');

        assertSameValue('page-entry 9', apply_filters('the_content', 'page-'), "template_redirect's '' argument hid the global WP");
    }

    public function testParsedPathWithSurroundingSlashesStillMatches(): void
    {
        $this->makeTransport(['entries/{id}' => static fn ($id) => 'entry ' . $id]);
        $GLOBALS['wp']          = new \WP();
        $GLOBALS['wp']->request = '/landing/entries/5/';

        do_action('template_redirect');

        assertSameValue('page-entry 5', apply_filters('the_content', 'page-'), 'slashes around the parsed path broke matching');
    }

    public function testRequestUriFallbackWorksWithoutAWpObject(): void
    {
        $this->makeTransport(['entries/{id}' => static fn ($id) => 'entry ' . $id]);
        WpKitTestState::$options['home'] = 'https://example.test/blog/';
        $_SERVER['REQUEST_URI']          = '/blog/landing/entries/3';

        do_action('template_redirect');

        assertSameValue('page-entry 3', apply_filters('the_content', 'page-'), 'fallback needed a WP object');
    }

    public function testHomePathIsStrippedOnlyOnASegmentBoundary(): void
    {
        $this->makeTransport(['entries/{id}' => static fn ($id) => 'entry ' . $id]);
        WpKitTestState::$options['home'] = 'https://example.test/la';
        $_SERVER['REQUEST_URI']          = '/landing/entries/3';

        do_action('template_redirect');

        assertSameValue('page-entry 3', apply_filters('the_content', 'page-'), 'home path "/la" was stripped from the "/landing" segment');
    }

    public function testEarlyDispatchIgnoresUnmatchedRequests(): void
    {
        $calls = 0;
        $this->makeTransport(['ping' => static function () use (&$calls) {
            ++$calls;

            return 'pong';
        }])->dispatchOnParseRequest();
        $wp          = new \WP();
        $wp->request = 'landing/other';

        do_action('parse_request', $wp);

        assertSameValue(0, $calls, 'early dispatch ran a route for an unmatched path');
        assertTest(!isset(WpKitTestState::$filters['the_content']), 'early dispatch touched page content for an unmatched path');
    }

    public function testEarlyDispatchStillEnforcesTheRouteMethod(): void
    {
        $calls = 0;
        new Router('static', 'landing', null);
        (new RouteBase())->post('hook', static function () use (&$calls) {
            ++$calls;

            return 'ok';
        });
        (new StaticRouter('landing', 'plugin_activate', 'plugin_deactivate'))->dispatchOnParseRequest();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $wp                        = new \WP();
        $wp->request               = 'landing/hook';

        do_action('parse_request', $wp);

        assertSameValue(0, $calls, 'early dispatch ran a POST route for GET');
    }

    public function testRetryWindowIsTrackedPerPage(): void
    {
        $this->makeTransport(['entries/{id}' => static fn () => 'x']);
        new Router('static', 'portal', null);
        (new RouteBase())->get('profile', static fn () => 'x');
        new StaticRouter('portal', 'plugin_activate', 'plugin_deactivate');

        do_action('init');
        do_action('init');

        assertSameValue(2, WpKitTestState::$rewriteFlushes, 'one page\'s retry window suppressed another page\'s flush');
    }

    public function testActivationFlushesEvenInsideTheRetryWindow(): void
    {
        $this->makeTransport(['entries/{id}' => static fn () => 'x']);

        do_action('init');
        do_action('plugin_activate');

        assertSameValue(2, WpKitTestState::$rewriteFlushes, 'activation flush was throttled');
    }

    public function testPersistedRulesSkipFlushAfterAnEarlierRetry(): void
    {
        $this->makeTransport(['entries/{id}' => static fn () => 'x']);

        do_action('init');
        WpKitTestState::$options['rewrite_rules'] = ['^landing/entries/([^/]+)/?$' => 'index.php?pagename=landing&id=$matches[1]'];
        WpKitTestState::$currentTime              = '2024-01-02 04:00:00';
        do_action('init');

        assertSameValue(1, WpKitTestState::$rewriteFlushes, 'persisted rules were flushed again after the window');
    }

    public function testRootRuleCoexistsWithOtherRoutes(): void
    {
        $this->makeTransport([
            '/'            => static fn () => 'home',
            'entries/{id}' => static fn () => 'x',
        ]);

        do_action('init');

        assertTest(isset(WpKitTestState::$rewriteRules['^landing/?$']), 'root rule missing next to other routes');
        assertTest(isset(WpKitTestState::$rewriteRules['^landing/entries/([^/]+)/?$']), 'route rule missing next to the root rule');
    }

    public function testRootRouteMatchesTheParsedPageRoot(): void
    {
        $this->makeTransport(['/' => static fn () => 'home']);
        $this->setParsedRequest('landing');

        do_action('template_redirect');

        assertSameValue('page-home', apply_filters('the_content', 'page-'), 'root route did not match the parsed page root');
    }

    public function testRootRouteMatchesThePageRootOnSubdirectoryInstalls(): void
    {
        $this->makeTransport(['/' => static fn () => 'home']);
        WpKitTestState::$options['home'] = 'https://example.test/blog';
        $_SERVER['REQUEST_URI']          = '/blog/landing';

        do_action('template_redirect');

        assertSameValue('page-home', apply_filters('the_content', 'page-'), 'root route did not match on a subdirectory install');
    }

    public function testRootRouteMatchesThePageRootFromTheRequestUri(): void
    {
        $this->makeTransport(['/' => static fn () => 'home']);
        $_SERVER['REQUEST_URI'] = '/landing';

        do_action('template_redirect');

        assertSameValue('page-home', apply_filters('the_content', 'page-'), 'root route did not match a page root without a trailing slash');
    }

    public function testRootRouteMatchesThePageRootFromATrailingSlashRequestUri(): void
    {
        $this->makeTransport(['/' => static fn () => 'home']);
        $_SERVER['REQUEST_URI'] = '/landing/?preview=1';

        do_action('template_redirect');

        assertSameValue('page-home', apply_filters('the_content', 'page-'), 'root route did not match a page root with a trailing slash');
    }

    public function testRootRouteDoesNotMatchDeeperPaths(): void
    {
        $this->makeTransport(['/' => static fn () => 'home']);
        $this->setParsedRequest('landing/entries/42');

        do_action('template_redirect');

        assertTest(!isset(WpKitTestState::$filters['the_content']), 'root route matched a deeper path');
    }

    public function testRootRouteDoesNotMatchAPageSharingItsNamePrefix(): void
    {
        $this->makeTransport(['/' => static fn () => 'home']);
        $this->setParsedRequest('landing-archive');

        do_action('template_redirect');

        assertTest(!isset(WpKitTestState::$filters['the_content']), 'root route matched another page sharing its name prefix');
    }

    public function testRootRouteDoesNotShadowSiblingRoutes(): void
    {
        $this->makeTransport([
            '/'         => static fn () => 'home',
            'user/{id}' => static fn ($id) => 'user ' . $id,
        ]);
        $this->setParsedRequest('landing/user/5');

        do_action('template_redirect');

        assertSameValue('page-user 5', apply_filters('the_content', 'page-'), 'root route shadowed a sibling route');
    }

    public function testEmptyPathInsideAPrefixMatchesThePrefixRoot(): void
    {
        $this->makeTransport(['/' => static fn () => 'account'], 'account');
        $this->setParsedRequest('landing/account');

        do_action('template_redirect');

        assertSameValue('page-account', apply_filters('the_content', 'page-'), 'empty path inside a prefix did not match the prefix root');
    }

    /**
     * Registers the given GET routes, optionally under a prefix, on a static router for the landing page.
     */
    private function makeTransport(array $routes = [], ?string $prefix = null): StaticRouter
    {
        new Router('static', 'landing', null);
        foreach ($routes as $path => $action) {
            (new RouteBase())->prefix($prefix)->get($path, $action);
        }

        return new StaticRouter('landing', 'plugin_activate', 'plugin_deactivate');
    }

    /**
     * Sets the parsed WP request path that the router reads from the global WP object.
     */
    private function setParsedRequest(string $path): void
    {
        $GLOBALS['wp']          = new \WP();
        $GLOBALS['wp']->request = $path;
    }
}
