<?php

use BitApps\WPKit\Http\Request\Request;
use BitApps\WPKit\Http\Response;
use BitApps\WPKit\Http\Router\Router;
use PHPUnit\Framework\Assert;

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    exit;
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/Fixtures/wordpress/');
}

require_once dirname(__DIR__) . '/src/Http/Client/HttpClient.php';

final class WpKitTestState
{
    public static $httpCalls = [];

    public static $lastHttpRequest;

    public static $actions = [];

    public static $filters = [];

    public static $cron = [];

    public static $restRoutes = [];

    public static $shortcodes = [];

    public static $shortcodeRenders = [];

    public static $rewriteRules = [];

    public static $rewriteFlushes = 0;

    public static $sentJson;

    public static $options = [];

    public static $currentTime;

    public static $capabilities = [];

    public static $sites = [];

    public static $switchedBlogs = [];

    public static $restoredBlogs = 0;

    public static $multisite = false;

    public static $networkActive = false;

    public static $wpVersion = '6.6';

    public static $isAdmin = false;

    public static $transients = [];

    public static $objectCache = [];
}

class WP_Error
{
    private $code;

    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_code()
    {
        return $this->code;
    }
}

final class FakeWpError extends WP_Error
{
}

final class WpDieException extends RuntimeException
{
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $body;

        private $json;

        private $query;

        private $route;

        private $headers;

        public function __construct($body = [], $query = [], $route = [], $json = [], $headers = [])
        {
            $this->body    = $body;
            $this->query   = $query;
            $this->route   = $route;
            $this->json    = $json;
            $this->headers = $headers;
        }

        public function get_headers()
        {
            return $this->headers;
        }

        public function get_body_params()
        {
            return $this->body;
        }

        public function get_json_params()
        {
            return $this->json;
        }

        public function get_query_params()
        {
            return $this->query;
        }

        public function get_url_params()
        {
            return $this->route;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;

        private $status;

        private $headers = [];

        public function set_data($data)
        {
            $this->data = $data;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function set_status($status)
        {
            $this->status = $status;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function set_headers($headers)
        {
            $this->headers = $headers;
        }

        public function get_headers()
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_REST_Controller')) {
    class WP_REST_Controller
    {
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';

        public const CREATABLE = 'POST';

        public const EDITABLE = 'POST, PUT, PATCH';

        public const DELETABLE = 'DELETE';
    }
}

function resetWpKitTestState()
{
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;

    WpKitTestState::$httpCalls = [
        'safe'   => 0,
        'unsafe' => 0,
    ];
    WpKitTestState::$lastHttpRequest  = null;
    WpKitTestState::$actions          = [];
    WpKitTestState::$filters          = [];
    WpKitTestState::$cron             = [];
    WpKitTestState::$restRoutes       = [];
    WpKitTestState::$shortcodes       = [];
    WpKitTestState::$shortcodeRenders = [];
    WpKitTestState::$rewriteRules     = [];
    WpKitTestState::$rewriteFlushes   = 0;
    WpKitTestState::$sentJson         = null;
    WpKitTestState::$options          = [
        'date_format'     => 'Y-m-d',
        'time_format'     => 'H:i:s',
        'timezone_string' => 'UTC',
        'gmt_offset'      => 0,
        'rewrite_rules'   => [],
    ];
    WpKitTestState::$currentTime   = '2024-01-02 03:04:05';
    WpKitTestState::$capabilities  = [];
    WpKitTestState::$sites         = [];
    WpKitTestState::$switchedBlogs = [];
    WpKitTestState::$restoredBlogs = 0;
    WpKitTestState::$multisite     = false;
    WpKitTestState::$networkActive = false;
    WpKitTestState::$wpVersion     = '6.6';
    WpKitTestState::$isAdmin       = false;
    WpKitTestState::$transients    = [];
    WpKitTestState::$objectCache   = [];

    $_GET     = [];
    $_POST    = [];
    $_FILES   = [];
    $_REQUEST = [];
    $_SERVER  = [
        'CONTENT_TYPE'   => '',
        'REMOTE_ADDR'    => '198.51.100.10',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI'    => '/',
    ];
    if ($scriptFilename !== null) {
        $_SERVER['SCRIPT_FILENAME'] = $scriptFilename;
    }

    if (class_exists(Response::class)) {
        Response::reset();
    }

    if (class_exists(Router::class)) {
        Router::reset();
    }

    if (class_exists(Request::class)) {
        Request::setTrustedProxies([]);
    }
}

function assertTest($condition, $message)
{
    Assert::assertTrue((bool) $condition, $message);
}

function assertSameValue($expected, $actual, $message)
{
    Assert::assertSame($expected, $actual, $message);
}

function assertInstanceOf($class, $actual, $message)
{
    Assert::assertInstanceOf($class, $actual, $message);
}

function assertThrows($exceptionClass, callable $callback, $message)
{
    try {
        $callback();
    } catch (Throwable $exception) {
        Assert::assertInstanceOf($exceptionClass, $exception, $message);

        return $exception;
    }

    Assert::fail($message);
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

// mirrors WordPress core: flushes every buffer level to output, returns nothing
function wp_ob_end_flush_all()
{
    $levels = ob_get_level();
    for ($i = 0; $i < $levels; ++$i) {
        ob_end_flush();
    }
}

function sanitize_text_field($value)
{
    return $value;
}

function sanitize_url($value)
{
    return $value;
}

function wp_unslash($value)
{
    return $value;
}

function wp_parse_args($args, $defaults = [])
{
    return array_merge($defaults, (array) $args);
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}

function wp_safe_remote_request($url, $options)
{
    ++WpKitTestState::$httpCalls['safe'];
    WpKitTestState::$lastHttpRequest = compact('url', 'options');

    if ($url === 'https://example.com/error') {
        return new FakeWpError();
    }

    return [
        'body'     => '{"safe":true}',
        'headers'  => ['X-Transport' => 'safe'],
        'response' => ['code' => 200],
    ];
}

function wp_remote_request($url, $options)
{
    ++WpKitTestState::$httpCalls['unsafe'];
    WpKitTestState::$lastHttpRequest = compact('url', 'options');

    return [
        'body'     => '{"safe":false}',
        'headers'  => ['X-Transport' => 'unsafe'],
        'response' => ['code' => 202],
    ];
}

function wp_remote_retrieve_body($response)
{
    return $response['body'];
}

function wp_remote_retrieve_headers($response)
{
    return $response['headers'];
}

function wp_remote_retrieve_response_code($response)
{
    if (is_wp_error($response) || !isset($response['response']) || !is_array($response['response'])) {
        return '';
    }

    return $response['response']['code'];
}

function wp_generate_password($length, $specialChars = true, $extraSpecialChars = false)
{
    return str_repeat('a', $length);
}

function add_action($tag, $callback, $priority = 10, $acceptedArgs = 1)
{
    WpKitTestState::$actions[$tag][] = compact('callback', 'priority', 'acceptedArgs');

    return true;
}

function remove_action($tag, $callback, $priority = 10)
{
    if (!isset(WpKitTestState::$actions[$tag])) {
        return false;
    }

    foreach (WpKitTestState::$actions[$tag] as $index => $registered) {
        if ($registered['callback'] === $callback && $registered['priority'] === $priority) {
            unset(WpKitTestState::$actions[$tag][$index]);

            return true;
        }
    }

    return false;
}

function do_action($tag, ...$args)
{
    foreach (WpKitTestState::$actions[$tag] ?? [] as $registered) {
        call_user_func_array($registered['callback'], array_slice($args, 0, $registered['acceptedArgs']));
    }
}

function add_filter($tag, $callback, $priority = 10, $acceptedArgs = 1)
{
    WpKitTestState::$filters[$tag][] = compact('callback', 'priority', 'acceptedArgs');

    return true;
}

function remove_filter($tag, $callback, $priority = 10)
{
    if (!isset(WpKitTestState::$filters[$tag])) {
        return false;
    }

    foreach (WpKitTestState::$filters[$tag] as $index => $registered) {
        if ($registered['callback'] === $callback && $registered['priority'] === $priority) {
            unset(WpKitTestState::$filters[$tag][$index]);

            return true;
        }
    }

    return false;
}

function apply_filters($tag, $value, ...$args)
{
    foreach (WpKitTestState::$filters[$tag] ?? [] as $registered) {
        $parameters = array_slice([$value, ...$args], 0, $registered['acceptedArgs']);
        $value      = call_user_func_array($registered['callback'], $parameters);
    }

    return $value;
}

function wp_next_scheduled($hook, $args = [])
{
    return WpKitTestState::$cron[$hook] ?? false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = [])
{
    WpKitTestState::$cron[$hook] = $timestamp;

    return true;
}

function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    WpKitTestState::$cron[$hook] = $timestamp;

    return true;
}

function wp_clear_scheduled_hook($hook, $args = [])
{
    unset(WpKitTestState::$cron[$hook]);

    return null;
}

function register_rest_route($namespace, $route, $args)
{
    WpKitTestState::$restRoutes[] = compact('namespace', 'route', 'args');

    return true;
}

function __return_true()
{
    return true;
}

function wp_send_json($data, $status = null)
{
    WpKitTestState::$sentJson = compact('data', 'status');

    return WpKitTestState::$sentJson;
}

function add_rewrite_rule($regex, $query, $position)
{
    WpKitTestState::$rewriteRules[$regex] = compact('query', 'position');
}

function flush_rewrite_rules()
{
    ++WpKitTestState::$rewriteFlushes;
}

function get_option($name, $default = false)
{
    return WpKitTestState::$options[$name] ?? $default;
}

function update_option($name, $value, $autoload = null)
{
    WpKitTestState::$options[$name] = $value;

    return true;
}

function current_time($type)
{
    return WpKitTestState::$currentTime;
}

function get_transient($key)
{
    $entry = WpKitTestState::$transients[$key] ?? null;

    if ($entry === null) {
        return false;
    }

    if ($entry['expires'] !== null && strtotime(WpKitTestState::$currentTime) >= $entry['expires']) {
        unset(WpKitTestState::$transients[$key]);

        return false;
    }

    return $entry['value'];
}

function set_transient($key, $value, $ttl = 0)
{
    WpKitTestState::$transients[$key] = [
        'value'   => $value,
        'expires' => $ttl > 0 ? strtotime(WpKitTestState::$currentTime) + $ttl : null,
    ];

    return true;
}

function delete_transient($key)
{
    $existed = array_key_exists($key, WpKitTestState::$transients);
    unset(WpKitTestState::$transients[$key]);

    return $existed;
}

function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
    $found = array_key_exists($group, WpKitTestState::$objectCache)
        && array_key_exists($key, WpKitTestState::$objectCache[$group]);

    return $found ? WpKitTestState::$objectCache[$group][$key] : false;
}

function wp_cache_set($key, $value, $group = '', $ttl = 0)
{
    WpKitTestState::$objectCache[$group][$key] = $value;

    return true;
}

function wp_cache_delete($key, $group = '')
{
    if (!isset(WpKitTestState::$objectCache[$group][$key])) {
        return false;
    }

    unset(WpKitTestState::$objectCache[$group][$key]);

    return true;
}

function wp_cache_flush()
{
    WpKitTestState::$objectCache = [];

    return true;
}

function wp_cache_incr($key, $offset = 1, $group = '')
{
    if (!isset(WpKitTestState::$objectCache[$group][$key])) {
        return false;
    }

    WpKitTestState::$objectCache[$group][$key] = (int) WpKitTestState::$objectCache[$group][$key] + $offset;

    return WpKitTestState::$objectCache[$group][$key];
}

function wp_cache_decr($key, $offset = 1, $group = '')
{
    return wp_cache_incr($key, -$offset, $group);
}

function wp_timezone_string()
{
    return WpKitTestState::$options['timezone_string'];
}

function wp_timezone()
{
    return new DateTimeZone(wp_timezone_string());
}

function do_shortcode($content, $ignoreHtml = false)
{
    WpKitTestState::$shortcodeRenders[] = compact('content', 'ignoreHtml');

    return 'rendered:' . $content . ($ignoreHtml ? ':ignore-html' : '');
}

function add_shortcode($tag, $callback)
{
    WpKitTestState::$shortcodes[$tag] = $callback;
}

function remove_shortcode($tag)
{
    unset(WpKitTestState::$shortcodes[$tag]);
}

function shortcode_exists($tag)
{
    return isset(WpKitTestState::$shortcodes[$tag]);
}

function has_shortcode($content, $tag)
{
    return strpos($content, '[' . $tag) !== false;
}

function current_user_can($capability, ...$args)
{
    return WpKitTestState::$capabilities[$capability] ?? false;
}

function is_admin()
{
    return WpKitTestState::$isAdmin;
}

function is_user_logged_in()
{
    return WpKitTestState::$capabilities['logged_in'] ?? false;
}

function wp_get_current_user()
{
    return (object) ['ID' => 42];
}

function wp_kses($value, $allowedHtml)
{
    return strip_tags($value);
}

function esc_html($value)
{
    return $value;
}

function get_bloginfo($field)
{
    return $field === 'version' ? WpKitTestState::$wpVersion : null;
}

function wp_die($message, $title = '')
{
    throw new WpDieException($title . ': ' . $message);
}

function get_sites($args)
{
    return WpKitTestState::$sites;
}

function get_current_network_id()
{
    return 1;
}

function switch_to_blog($site)
{
    WpKitTestState::$switchedBlogs[] = $site;
}

function restore_current_blog()
{
    ++WpKitTestState::$restoredBlogs;
}

function is_multisite()
{
    return WpKitTestState::$multisite;
}

function is_plugin_active_for_network($basename)
{
    return WpKitTestState::$networkActive;
}
