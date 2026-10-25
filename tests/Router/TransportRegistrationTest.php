<?php

namespace BitApps\WPKit\Tests\Router;

use BitApps\WPKit\Http\Router\AjaxRouter;
use BitApps\WPKit\Http\Router\APIRouter;
use BitApps\WPKit\Http\Router\RouteBase;
use BitApps\WPKit\Http\Router\Router;
use BitApps\WPKit\Http\Router\StaticRouter;
use BitApps\WPKit\Tests\TestCase;
use ReflectionProperty;
use WP_REST_Server;
use WpKitTestState;

/**
 * @internal
 *
 * @coversNothing
 */
final class TransportRegistrationTest extends TestCase
{
    public function testRESTTransportRouteRegistrationPreservesNamespaceVersionPathAndMethods(): void
    {
        $router = new Router('api', 'example', 'v1');
        $route  = (new RouteBase())
            ->prefix('admin')
            ->match('GET,POST', 'entries', function () {
                return 'ok';
            });

        (new APIRouter($router))->addRoute($route);

        assertSameValue(1, \count(WpKitTestState::$restRoutes), 'REST route was not registered once');
        $registration = WpKitTestState::$restRoutes[0];
        assertSameValue('example', $registration['namespace'], 'REST namespace changed');
        assertSameValue('v1/admin/entries', $registration['route'], 'REST versioned path changed');
        assertSameValue('GET', $registration['args'][0]['methods'], 'GET method mapping changed');
        assertSameValue('POST', $registration['args'][1]['methods'], 'POST method mapping changed');
        assertSameValue([$route, 'handleRequest'], $registration['args'][0]['callback'], 'REST callback changed');
        assertTest(\is_callable($registration['args'][0]['permission_callback']), 'REST permission callback is not callable');
    }

    public function testRESTTransportDefaultsToAPublicPermissionCallback(): void
    {
        $route = (new RouteBase())->get('entries', fn () => 'ok');

        (new APIRouter(new Router('api', 'example', 'v1')))->addRoute($route);

        assertSameValue('__return_true', WpKitTestState::$restRoutes[0]['args'][0]['permission_callback'], 'default permission changed');
    }

    public function testRESTTransportUsesTheGroupPermissionCallback(): void
    {
        $permission = fn () => false;
        $group      = (new RouteBase())->permission($permission);
        $route      = $group->match('GET,POST', 'entries', fn () => 'ok');

        (new APIRouter(new Router('api', 'example', 'v1')))->addRoute($route);

        foreach (WpKitTestState::$restRoutes[0]['args'] as $args) {
            assertSameValue($permission, $args['permission_callback'], 'group permission not applied to every method');
        }
    }

    public function testRESTTransportRoutePermissionOverridesTheGroup(): void
    {
        $routePermission = fn () => true;
        $route           = (new RouteBase())
            ->permission(fn () => false)
            ->get('entries', fn () => 'ok')
            ->permission($routePermission);

        (new APIRouter(new Router('api', 'example', 'v1')))->addRoute($route);

        assertSameValue($routePermission, WpKitTestState::$restRoutes[0]['args'][0]['permission_callback'], 'route permission did not win');
    }

    public function testRESTTransportWordPressMethodConstantsRemainMapped(): void
    {
        $router    = new Router('api', 'example', 'v1');
        $transport = new APIRouter($router);

        assertSameValue(WP_REST_Server::READABLE, $transport->getMethod('GET'), 'GET mapping changed');
        assertSameValue(WP_REST_Server::CREATABLE, $transport->getMethod('POST'), 'POST mapping changed');
        assertSameValue(WP_REST_Server::EDITABLE, $transport->getMethod('PUT'), 'PUT mapping changed');
        assertSameValue(WP_REST_Server::DELETABLE, $transport->getMethod('DELETE'), 'DELETE mapping changed');
    }

    public function testAJAXTransportAuthenticatedRoutesRegisterTheMatchingAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['action']        = 'example/v1/entries/42';
        $router                    = new Router('ajax', 'example', 'v1');
        $route                     = (new RouteBase())->post('/entries/{id}', function () {
            return 'ok';
        });

        $transport = new AjaxRouter($router);
        $transport->addRoute($route);

        $hook = 'wp_ajax_example/v1/entries/42';
        assertTest(isset(WpKitTestState::$actions[$hook]), 'authenticated AJAX hook was not registered');
        assertTest(!isset(WpKitTestState::$actions['wp_ajax_nopriv_example/v1/entries/42']), 'guest hook was registered');
        assertSameValue('42', $route->getRouteParamValue('id'), 'AJAX path parameter was not captured');
        assertSameValue($route, $transport->currentRoute(), 'current AJAX route lookup changed');
    }

    public function testAJAXTransportPublicRoutesAlsoRegisterTheGuestAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_REQUEST['action']        = 'example/public';
        $router                    = new Router('ajax', 'example', null);
        $route                     = (new RouteBase())->noAuth()->get('/public', function () {
            return 'ok';
        });

        (new AjaxRouter($router))->addRoute($route);

        assertTest(isset(WpKitTestState::$actions['wp_ajax_example/public']), 'authenticated public hook was not registered');
        assertTest(isset(WpKitTestState::$actions['wp_ajax_nopriv_example/public']), 'guest public hook was not registered');
    }

    public function testStaticTransportConstructorRegistersLifecycleAndDispatchHooks(): void
    {
        new Router('static', 'landing', null);
        $transport = new StaticRouter('landing', 'plugin_activate', 'plugin_deactivate');

        assertInstanceOf(Router::class, $transport->getRouter(), 'static transport did not expose its router');
        assertTest(isset(WpKitTestState::$actions['plugin_activate']), 'activation hook was not registered');
        assertTest(isset(WpKitTestState::$actions['plugin_deactivate']), 'deactivation hook was not registered');
        assertTest(isset(WpKitTestState::$actions['init']), 'rewrite registration hook was not registered');
        assertTest(isset(WpKitTestState::$actions['query_vars']), 'query variable hook was not registered');
        assertTest(isset(WpKitTestState::$actions['template_redirect']), 'dispatch hook was not registered');
    }

    public function testStaticTransportRenderedRouteOutputIsAppendedToContent(): void
    {
        new Router('static', 'landing', null);
        $transport  = new StaticRouter('landing', 'plugin_activate', 'plugin_deactivate');
        $reflection = new ReflectionProperty($transport, 'content');
        $reflection->setAccessible(true);
        $reflection->setValue($transport, '<section>route</section>');

        assertSameValue(
            '<main>page</main><section>route</section>',
            $transport->renderContent('<main>page</main>'),
            'static route output composition changed',
        );
    }
}
