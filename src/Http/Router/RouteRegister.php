<?php

namespace BitApps\WPKit\Http\Router;

use BitApps\WPKit\Http\Request\Request;
use BitApps\WPKit\Http\RequestType;
use BitApps\WPKit\Http\Response;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use WP_REST_Request;

final class RouteRegister
{
    private $_name;

    private array $_methods = [];

    private $_action;

    private $_path;

    private $_routeParams = [];

    private $_routeParamValues = [];

    private array $_middleware = [];

    /**
     * REST permission callback for this route; falls back to the group's.
     *
     * @var null|callable
     */
    private $_permission;

    /**
     * Instance of rest request.
     *
     * @var WP_REST_Request
     */
    private $_restRequest;

    /**
     * Instance of Request.
     *
     * @var Request
     */
    private $_request;

    private array $_response = [];

    private ?int $_bufferLevel = null;

    private $_compiled;

    private bool $_compiledDone = false;

    public function __construct(private RouteBase $_routeBase)
    {
    }

    public function match($methods, $path, $action): self
    {
        if (\is_string($methods)) {
            $methods = explode(',', $methods);
        }

        foreach ($methods as $method) {
            $this->register($method, $path, $action);
        }

        return $this;
    }

    public function get($path, $action): RouteRegister
    {
        return $this->register('GET', $path, $action);
    }

    public function post($path, $action): RouteRegister
    {
        return $this->register('POST', $path, $action);
    }

    public function getMethods(): array
    {
        return $this->_methods;
    }

    public function action($action): self
    {
        $this->_action = $action;

        return $this;
    }

    public function getAction()
    {
        return $this->_action;
    }

    public function path($path): self
    {
        $this->_path         = $path;
        $this->_compiledDone = false;

        return $this;
    }

    public function getPath()
    {
        return $this->_path;
    }

    public function name($name): self
    {
        $this->_name = $name;

        return $this;
    }

    public function getName()
    {
        return $this->_name;
    }

    public function isNoAuth()
    {
        return $this->_routeBase->isNoAuth();
    }

    public function isTokenIgnored()
    {
        return $this->_routeBase->isTokenIgnored();
    }

    public function regex()
    {
        if ($this->compiledPattern() === null) {
            return false;
        }

        return $this->makeRegex();
    }

    public function hasRegex(): bool
    {
        return $this->compiledPattern() !== null;
    }

    public function getMiddleware(): array
    {
        return array_merge($this->_routeBase->getMiddleware(), $this->_middleware);
    }

    public function middleware(): self
    {
        $this->_middleware = array_merge($this->_middleware, \func_get_args());

        return $this;
    }

    /**
     * Sets the REST permission callback for this route.
     *
     * @param callable $callback receives the WP_REST_Request; return true, false or WP_Error
     */
    public function permission(callable $callback): self
    {
        $this->_permission = $callback;

        return $this;
    }

    /**
     * REST permission callback: the route's own, else its group's, else public.
     */
    public function getPermissionCallback(): callable
    {
        return $this->_permission ?? $this->_routeBase->getPermission() ?? '__return_true';
    }

    public function handleMiddleware(): bool
    {
        try {
            $this->runMiddlewares();
        } catch (RouteBlockedException $exception) {
            $this->recordBlock($exception);

            return false;
        }

        return true;
    }

    public function getRoutePrefix()
    {
        return $this->_routeBase->getRoutePrefix();
    }

    public function getRouteParam($name)
    {
        if (!isset($this->_routeParams[$name])) {
            return false;
        }

        return $this->_routeParams[$name];
    }

    public function getRouteParams()
    {
        return $this->_routeParams;
    }

    public function setRouteParamValue($name, $value): void
    {
        $this->_routeParamValues[$name] = $value;
    }

    public function getRouteParamValue($name)
    {
        if (!isset($this->_routeParamValues[$name])) {
            return false;
        }

        return $this->_routeParamValues[$name];
    }

    public function getParamValue(ReflectionParameter $param)
    {
        try {
            return $this->resolveParamValue($param);
        } catch (RouteBlockedException $exception) {
            $this->recordBlock($exception);

            return;
        }
    }

    public function getRouteParamValues()
    {
        return $this->_routeParamValues;
    }

    /**
     * Returns Request for this route.
     *
     * @return Request
     */
    public function getRequest()
    {
        try {
            return $this->resolveRequest();
        } catch (RouteBlockedException $exception) {
            $this->recordBlock($exception);

            return $this->_request;
        }
    }

    /**
     * Returns router for this route.
     *
     * @return Router
     */
    public function getRouter()
    {
        return $this->_routeBase->getRouter();
    }

    /**
     * Returns router type for this route.
     *
     * @return string
     */
    public function getRouterType()
    {
        return $this->getRouter()->getRequestType();
    }

    public function handleRequest()
    {
        $this->_response = [];
        unset($this->_request, $this->_restRequest);
        Response::reset();

        $this->_bufferLevel = ob_get_level();
        ob_start();
        if (\func_num_args() && ($apiRequest = \func_get_args()[0]) instanceof WP_REST_Request) {
            $this->setRestRequest($apiRequest);
        }

        try {
            $this->runMiddlewares();
            $this->handleAction();
        } catch (RouteBlockedException $exception) {
            $this->recordBlock($exception);
        } finally {
            // an action throwing anything else must not leak the buffer we opened
            $this->collectBufferedOutput();
        }

        return $this->sendResponse();
    }

    private function resolveParamValue(ReflectionParameter $param)
    {
        $value         = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
        $paramName     = $param->getName();
        $hasRouteParam = $this->hasRouteParamValue($paramName);
        if ($hasRouteParam) {
            $value = $this->_routeParamValues[$paramName];
        }

        if (!$type = $param->getType()) {
            return $value;
        }

        if ($type instanceof ReflectionNamedType) {
            $type = $type->getName();
        } else {
            $type = (string) $type;
        }

        if (!class_exists($type)) {
            return $value;
        }

        if ($type === Request::class || is_subclass_of($type, Request::class)) {
            $this->setRequest($type);
            $value = $this->resolveRequest();
        } elseif ($hasRouteParam && method_exists($type, '__construct')) {
            $constructor = new ReflectionMethod($type, '__construct');
            if ($constructor->getNumberOfParameters() === 1) {
                $parameter = $constructor->getParameters()[0];
                if (!$parameter->hasType()) {
                    $value = new $type($value);
                } elseif (method_exists($type, 'query')) {
                    $value = $type::query()->find($value);
                }
            }
        } elseif (!$param->isOptional()) {
            $value = new $type();
        }

        return $value;
    }

    private function hasRouteParamValue(string $name): bool
    {
        return \array_key_exists($name, $this->_routeParamValues);
    }

    private function runMiddlewares(): void
    {
        if (empty($middlewares = $this->getMiddleware())) {
            return;
        }

        $router = $this->getRouter();
        foreach ($middlewares as $middleware) {
            $middlewareData = explode(':', (string) $middleware);
            $middleware     = $middlewareData[0];
            $params         = [];
            if (isset($middlewareData[1])) {
                $params = explode(',', (string) $middlewareData[1]);
            }

            try {
                $middlewareObj = $router->getRegisteredMiddleware($middleware);
            } catch (MiddlewareConfigurationException) {
                throw new RouteBlockedException(Response::error([], 500)->code('MIDDLEWARE_CONFIGURATION')->message('Route middleware is not configured'));
            }

            $response = $this->invokeAsReflection($middlewareObj, 'handle', $params);
            if ($response !== true) {
                $this->block($response);
            }
        }
    }

    private function setRestRequest(WP_REST_Request $request): void
    {
        $this->_restRequest = $request;
    }

    /**
     * Sets Request for this route.
     *
     * @param Request $request
     */
    private function resolveRequest()
    {
        if (!isset($this->_request)) {
            $this->setRequest();
        }

        return $this->_request;
    }

    private function setRequest($request = null)
    {
        if ($request === null) {
            $this->_request = new Request($this);
        } else {
            $this->_request = new $request($this);
        }

        if (isset($this->_restRequest)) {
            $this->_request->setApiRequest($this->_restRequest);
        }

        $this->authorize();
        $this->validate();

        return $this->_request;
    }

    private function authorize(): void
    {
        if (method_exists($this->_request, 'authorize') && !$this->_request->authorize()) {
            $message = 'You are not authorized to access this endpoint';
            if (method_exists($this->_request, 'failedAuthorizationMessage')) {
                $message = $this->_request->failedAuthorizationMessage();
            }

            $this->block(
                Response::error([])
                    ->code('NOT_AUTHORIZED')
                    ->message($message)
            );
        }
    }

    private function validate(): void
    {
        if (method_exists($this->_request, 'rules')) {
            $messages   = [];
            $attributes = [];

            if (method_exists($this->_request, 'messages')) {
                $messages = $this->_request->messages();
            }

            if (method_exists($this->_request, 'attributes')) {
                $attributes = $this->_request->attributes();
            }

            $validation = $this->_request->make(
                $this->_request->all(),
                $this->_request->rules(),
                $messages,
                $attributes
            );

            if ($validation->fails()) {
                $this->block(Response::error($validation->errors())->code('VALIDATION'));
            }
        }
    }

    private function register($method, $path, $action): self
    {
        $this->_methods[] = strtoupper($method);
        $this->path($path);
        $this->action($action);

        return $this;
    }

    private function makeRegex()
    {
        $compiled = $this->compiledPattern();
        foreach ($compiled['params'] as $name => $attribute) {
            $this->setRouteParam($name, $attribute);
        }

        return $compiled['regex'];
    }

    /**
     * Compiles the route path once and memoizes it (null when the path has no placeholders).
     *
     * @return null|array
     */
    private function compiledPattern()
    {
        if (!$this->_compiledDone) {
            $this->_compiled     = isset($this->_path) ? RoutePattern::compile($this->_path) : null;
            $this->_compiledDone = true;
        }

        return $this->_compiled;
    }

    private function setRouteParam($name, $attribute): void
    {
        $this->_routeParams[$name] = $attribute;
    }

    private function handleAction(): void
    {
        $action = $this->getAction();
        if (\is_array($action) && method_exists($action[0], $action[1])) {
            $response = $this->invokeAsReflection($action[0], $action[1]);
        } elseif (\is_callable($action)) {
            $response = $this->invokeAsReflectionFunction($action);
        } else {
            $response = Response::message('Route action doesn\'t exists');
        }

        $this->setResponse($response);
    }

    /**
     * @param Closure|string $method
     */
    private function invokeAsReflectionFunction(callable $method): mixed
    {
        $reflectionFunction = new ReflectionFunction($method);
        $params             = $this->processParameters($reflectionFunction->getParameters());

        return $reflectionFunction->invoke(...$params);
    }

    private function processParameters(array $reflectionParams, array $params = []): array
    {
        $requestParams = [];
        foreach ($reflectionParams as $param) {
            $requestParams[] = $this->resolveParamValue($param);
        }

        return array_merge($requestParams, $params);
    }

    private function invokeAsReflection($class, $method, array $params = []): mixed
    {
        $reflectionMethod = new ReflectionMethod($class, $method);
        $reflectionParams = $reflectionMethod->getParameters();

        /**
         * If the ReflectionMethod is a method of a Middleware then we will set the first parameter.
         * First parameter will be Request object
         * Rest of params will be from Middleware ex: 'role:admin'.
         *
         * If params count is 0 then the method is handle of Middleware and called from handleMiddleware
         */
        $reflectionParams = \count($params) === 0 ? $reflectionParams : [$reflectionParams[0]];

        $params = $this->processParameters($reflectionParams, $params);

        return $reflectionMethod->invoke($reflectionMethod->isStatic() ? null : new $class(), ...$params);
    }

    private function block($response): void
    {
        throw new RouteBlockedException($response);
    }

    private function recordBlock(RouteBlockedException $exception): void
    {
        $this->setResponse($exception->getResponse());
    }

    /**
     * Captures stray output from the buffer handleRequest() opened; never touches buffers owned by others.
     */
    private function collectBufferedOutput(): string|false
    {
        if ($this->_bufferLevel === null || ob_get_level() <= $this->_bufferLevel) {
            return '';
        }

        $this->_bufferLevel = null;

        return ob_get_clean();
    }

    private function setResponse($response): void
    {
        $this->_response = ResponseEnvelope::build($response, $this->collectBufferedOutput());
    }

    private function sendResponse()
    {
        return $this->resolveEmitter()->emit($this->_response);
    }

    private function resolveEmitter(): Emitter\ResponseEmitter
    {
        return match ($this->getRouterType()) {
            RequestType::API  => new Emitter\ApiResponseEmitter(),
            RequestType::AJAX => new Emitter\AjaxResponseEmitter(),
            default           => new Emitter\RawResponseEmitter(),
        };
    }
}
