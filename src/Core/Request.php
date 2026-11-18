<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public readonly string $method;

    public readonly string $path;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        string $method,
        string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        array $headers = [],
        public readonly array $cookies = [],
        public readonly array $attributes = [],
    ) {
        $this->method = strtoupper($method);
        $parsedPath = parse_url($path, PHP_URL_PATH);
        $this->path = $parsedPath === false || $parsedPath === null || $parsedPath === '' ? '/' : $parsedPath;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /** @var array<string, string> */
    public readonly array $headers;

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = is_array($headers) ? $headers : [];
        $contentType = strtolower((string) ($headers['Content-Type'] ?? $_SERVER['CONTENT_TYPE'] ?? ''));

        $body = $_POST;

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        } elseif (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            parse_str((string) file_get_contents('php://input'), $body);
        }

        return new self($method, $uri, $query, $body, $headers, $_COOKIE);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryString(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function inputString(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->headers,
            $this->cookies,
            $attributes
        );
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type', '') ?? ''), 'application/json');
    }

    public function expectsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains(strtolower($this->header('accept', '') ?? ''), 'application/json');
    }
}
