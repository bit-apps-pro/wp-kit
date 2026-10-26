<?php

namespace BitApps\WPKit\Http\Request;

use WP_REST_Request;

/**
 * Header access for a request: the hydrated WP_REST_Request when the host sets `$rest`, else the server globals.
 */
trait InteractsWithHeaders
{
    /**
     * CGI exposes these headers without the HTTP_ prefix.
     */
    private static array $_unprefixedHeaders = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];

    /**
     * Returns every request header, sanitized, keyed by canonical name (lowercase, underscores).
     *
     * @return array<string, string>
     */
    public function headers()
    {
        return array_map('sanitize_text_field', $this->rawHeaders());
    }

    /**
     * Returns one sanitized header by any name style ("X-Token" or "x_token"), or the default when absent.
     *
     * @return null|string
     */
    public function header(string $name, ?string $default = null)
    {
        $value = $this->rawHeader($name);

        return $value === null ? $default : sanitize_text_field($value);
    }

    /**
     * Returns one header exactly as sent, for signature or token checks only; never output or store it unsanitized.
     *
     * @return null|string
     */
    public function rawHeader(string $name, ?string $default = null)
    {
        return $this->rawHeaders()[self::canonicalHeaderName($name)] ?? $default;
    }

    /**
     * Reports whether the request carries the header.
     *
     * @return bool
     */
    public function hasHeader(string $name)
    {
        return \array_key_exists(self::canonicalHeaderName($name), $this->rawHeaders());
    }

    /**
     * Collects unsanitized headers from the REST request when hydrated, else from the server globals.
     *
     * @return array<string, string>
     */
    private function rawHeaders(): array
    {
        if (isset($this->rest) && $this->rest instanceof WP_REST_Request) {
            return array_map(static fn ($values) => implode(', ', (array) $values), (array) $this->rest->get_headers());
        }

        return $this->serverHeaders();
    }

    /**
     * Collects headers from $_SERVER, which WordPress slashes.
     *
     * @return array<string, string>
     */
    private function serverHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[self::canonicalHeaderName(substr($key, 5))] = wp_unslash($value);
            } elseif ($value !== '' && \in_array($key, self::$_unprefixedHeaders, true)) {
                // FastCGI passes CONTENT_* as '' when the request has no body.
                $headers[self::canonicalHeaderName($key)] = wp_unslash($value);
            }
        }

        return $headers;
    }

    /**
     * Normalizes a header name the way WP_REST_Request does.
     */
    private static function canonicalHeaderName(string $name): string
    {
        return str_replace('-', '_', strtolower($name));
    }
}
