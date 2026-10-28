<?php

namespace BitApps\WPKit\Http\Request;

use ArrayAccess;
use BitApps\WPKit\Configs\JsonConfig;
use BitApps\WPKit\Helpers\Arr;
use BitApps\WPKit\Helpers\JSON;
use BitApps\WPKit\Http\IpTool;
use BitApps\WPKit\Http\Router\RouteRegister;
use BitApps\WPValidator\Validator;
use JsonSerializable;
use ReturnTypeWillChange;
use RuntimeException;
use WP_REST_Request;

class Request extends Validator implements ArrayAccess, JsonSerializable
{
    use InteractsWithHeaders;
    use IpTool;

    private const APPLICATION_MEDIA_PREFIX = 'application/';

    protected $rest;

    protected array $attributes = [];

    protected $queryParams = [];

    protected $routeParams = [];

    protected $body = [];

    /**
     * Raw php://input, read at most once.
     */
    private ?string $_content = null;

    /**
     * Undocumented function.
     */
    public function __construct(protected ?RouteRegister $route = null)
    {
        $this->setBody();
        $this->setQueryParams();
        $this->setRouteParams();
        $this->attributes = (array) $this->queryParams + (array) $this->body + (array) $this->routeParams;
    }

    public function __isset(string $offset)
    {
        return $this->has($offset);
    }

    public function __get(string $offset)
    {
        return $this->get($offset);
    }

    public function __set(string $offset, mixed $value)
    {
        $this->setAttribute($offset, $value);
    }

    public function __unset(string $offset)
    {
        $this->unsetAttribute($offset);
    }

    public function __call(string $method, array $parameters)
    {
        if (isset($this->rest) && method_exists($this->rest, $method)) {
            return \call_user_func_array([$this->rest, $method], $parameters);
        }

        throw new RuntimeException('Undefined method [' . (string) $method . '] called on ' . self::class . 'class.');
    }

    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->{$method}(...$parameters);
    }

    public function __clone()
    {
        // do nothing
    }

    public function queryParams()
    {
        return $this->queryParams;
    }

    /**
     * Returns Route sets for current request.
     *
     * @return RouteRegister
     */
    public function getRoute()
    {
        return $this->route;
    }

    public function body()
    {
        return $this->body;
    }

    /**
     * Returns the raw request body exactly as sent, e.g. for signature checks.
     *
     * @return string
     */
    public function content()
    {
        if (isset($this->rest)) {
            return (string) $this->rest->get_body();
        }

        return $this->_content ??= (string) file_get_contents('php://input');
    }

    /**
     * Returns the sanitized, upper-cased HTTP method, or '' when the server omits it.
     *
     * @return string
     */
    public function method()
    {
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            return '';
        }

        return strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])));
    }

    /**
     * Returns the sanitized Content-Type with a leading "application/" removed (e.g. "json").
     *
     * @return string
     */
    public function contentType()
    {
        $type = (string) $this->header('Content-Type', '');

        // Media types are case-insensitive (RFC 9110 §8.3.1).
        return str_starts_with(strtolower($type), self::APPLICATION_MEDIA_PREFIX)
            ? substr($type, \strlen(self::APPLICATION_MEDIA_PREFIX))
            : $type;
    }

    public function setApiRequest(WP_REST_Request $request)
    {
        $this->setBody((array) $request->get_body_params() + (array) $request->get_json_params());
        $this->setQueryParams((array) $request->get_query_params());
        $this->setRouteParams((array) $request->get_url_params());

        $this->attributes = (array) $this->queryParams + (array) $this->body + (array) $this->routeParams;

        $this->rest = $request;
    }

    /**
     * Provides all files in a request, or an empty array when there are none.
     *
     * @return array
     */
    public function files()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- request wrapper; nonce checks belong to route middleware.
        return $_FILES ?? [];
    }

    public function all()
    {
        return $this->attributes;
    }

    public function get($offset, $default = null)
    {
        return Arr::get($this->attributes, $offset, $default);
    }

    public function input($offset, $default = null)
    {
        return $this->get($offset, $default);
    }

    /**
     * @return mixed[]
     */
    public function except()
    {
        $paramToIgnore = \func_get_args();
        $all           = $this->all();
        foreach ($paramToIgnore as $key) {
            unset($all[$key]);
        }

        return $all;
    }

    public function has($offset)
    {
        return Arr::has($this->attributes, $offset);

        // return isset($this->attributes[$offset]);
    }

    #[ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $this->has($offset);
    }

    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    #[ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->setAttribute($offset, $value);
    }

    #[ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $this->unsetAttribute($offset);
    }

    #[ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->attributes;
    }

    public function validate($rules, $messages = null, $attributeLabels = null)
    {
        if (\count($this->files()) > 0) {
            $this->attributes = array_merge($this->attributes, $this->files());
        }

        $validator = $this->make($this->attributes, $rules, $messages, $attributeLabels);

        if ($validator->fails()) {
            $response = [
                'status' => 'error',
                'code'   => 'VALIDATION',
                'data'   => $validator->errors(),
            ];

            wp_send_json($response, 422);
        }

        return $validator->validated();
    }

    protected function setQueryParams($params = [])
    {
        if (!empty($params)) {
            $this->queryParams = $params;
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request wrapper; nonce checks belong to route middleware.
            $this->queryParams = (array) wp_unslash($_GET ?? []);
        }
    }

    protected function setRouteParams($params = [])
    {
        if (!empty($params)) {
            $this->routeParams = $params;
            foreach ($params as $name => $value) {
                $this->route->setRouteParamValue($name, $value);
            }
        } elseif (isset($this->route) && !\is_null($this->route)) {
            $this->routeParams = (array) $this->route->getRouteParamValues();
        }
    }

    protected function setBody($body = [])
    {
        if (!empty($body)) {
            $this->body = $body;
        } else {
            if (
                !str_contains($this->contentType(), 'form-data')
                && !str_contains($this->contentType(), 'x-www-form-urlencoded')
            ) {
                $this->body = JSON::maybeDecode($this->content(), JsonConfig::decodeAsArray());
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- request wrapper; nonce checks belong to route middleware.
            $post = (array) wp_unslash($_POST ?? []);
            if (!empty($post)) {
                $this->body = (array) $this->body + $post;
            }
        }
    }

    protected function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    protected function unsetAttribute($key)
    {
        unset($this->attributes[$key]);
    }
}
