<?php

namespace BitApps\WPKit\Http;

use InvalidArgumentException;

final class Response
{
    const SUCCESS = 'success';

    const ERROR = 'error';

    private static ?Response $_current = null;

    private $_message;

    private ?string $_status = null;

    private $_code;

    private $_data;

    private $_httpStatus;

    private array $_headers = [];

    public static function instance(): Response
    {
        return self::current();
    }

    public static function reset(): self
    {
        return self::$_current = new self();
    }

    /**
     * Makes an existing response the current one so the static accessors read it back.
     *
     * @return self
     */
    public static function adopt(self $response): self
    {
        return self::$_current = $response;
    }

    /**
     * Sets data for success response.
     *
     * @param mixed $data       Data to return on response
     * @param mixed $httpStatus
     *
     * @return self
     */
    public static function success($data, $httpStatus = 200): self
    {
        return self::start($data, self::SUCCESS, $httpStatus);
    }

    /**
     * Sets data for error response.
     *
     * @param mixed $data       Data to return on response
     * @param mixed $httpStatus
     *
     * @return self
     */
    public static function error($data, $httpStatus = 400): self
    {
        return self::start($data, self::ERROR, $httpStatus);
    }

    /**
     * Returns data for response.
     *
     * @return mixed $_data
     */
    public static function getData()
    {
        return self::current()->_data;
    }

    /**
     * Returns status for response.
     *
     * @return string $_status
     */
    public static function getStatus(): ?string
    {
        return self::current()->_status;
    }

    /**
     * Sets message for response.
     *
     * @param string $message Data to return on response
     *
     * @return self
     */
    public static function message($message): self
    {
        $current           = self::current();
        $current->_message = $message;

        return $current;
    }

    /**
     * Returns message response.
     *
     * @return mixed $_message
     */
    public static function getMessage()
    {
        return self::current()->_message;
    }

    /**
     * Sets code for response.
     *
     * @param string $code status code to return on response
     *
     * @return self
     */
    public static function code($code): self
    {
        $current        = self::current();
        $current->_code = $code;

        return $current;
    }

    /**
     * Returns status code response.
     *
     * @return mixed $_code
     */
    public static function getCode()
    {
        $current = self::current();
        if (!isset($current->_code)) {
            return isset($current->_status) ? strtoupper($current->_status) : null;
        }

        return $current->_code;
    }

    /**
     * Sets http status code for response.
     *
     * @param int $code http status code to return on response
     *
     * @return self
     */
    public static function httpStatus($code): self
    {
        $current              = self::current();
        $current->_httpStatus = $code;

        return $current;
    }

    /**
     * Returns status code response.
     *
     * @return mixed $_httpStatus
     */
    public static function getHttpStatusCode()
    {
        $current    = self::current();
        $statusCode = $current->_httpStatus;
        if (!$statusCode) {
            $statusCode = self::ERROR === $current->_status ? 400 : 200;
        }

        return $statusCode;
    }

    /**
     * Sets http headers for response.
     *
     * @param array $headers http headers to return on response
     *
     * @return self
     */
    public static function headers($headers): Response
    {
        if (!\is_array($headers)) {
            throw new InvalidArgumentException('Response headers must be an array.');
        }

        $validated = [];
        foreach ($headers as $header => $value) {
            [$header, $value]   = self::validateHeader($header, $value);
            $validated[$header] = $value;
        }

        $current           = self::current();
        $current->_headers = $validated;

        return $current;
    }

    /**
     * Sets http headers for response.
     *
     * @param string $header http header
     * @param string $value
     *
     * @return self
     */
    public static function header($header, $value): self
    {
        [$header, $value] = self::validateHeader($header, $value);

        $current                    = self::current();
        $current->_headers[$header] = $value;

        return $current;
    }

    /**
     * Returns headers for response.
     *
     * @return array $_headers
     */
    public static function getHeaders(): array
    {
        return self::current()->_headers;
    }

    private static function start($data, string $status, $httpStatus): self
    {
        $response              = new self();
        $response->_data       = $data;
        $response->_status     = $status;
        $response->_httpStatus = $httpStatus;

        return self::$_current = $response;
    }

    private static function validateHeader($header, $value): array
    {
        if (!\is_string($header) || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $header) !== 1) {
            throw new InvalidArgumentException('Invalid response header name.');
        }

        if (!\is_scalar($value) || preg_match('/[\r\n\0]/', (string) $value)) {
            throw new InvalidArgumentException('Invalid response header value.');
        }

        return [$header, $value];
    }

    private static function current(): Response
    {
        self::$_current ??= new self();

        return self::$_current;
    }
}
