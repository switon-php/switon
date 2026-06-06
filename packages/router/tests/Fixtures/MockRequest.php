<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

/**
 * Mock request implementation for router tests.
 */
class MockRequest
{
    protected array $headers = [];
    protected array $params = [];
    protected array $postParams = [];
    protected array $server = [];
    protected array $files = [];
    protected array $attributes = [];
    protected array $route = [];
    protected string $pathValue = '/';
    protected string $verbValue = 'GET';
    protected string $schemeValue = 'http';
    protected array $queryParams = [];
    protected string $handlerValue = '';
    protected string $originValue = '';
    protected float $elapsedValue = 0.0;

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? $default;
    }

    public function path(bool $withQuery = false): string
    {
        return $this->pathValue;
    }

    public function verb(): string
    {
        return $this->verbValue;
    }

    public function scheme(): string
    {
        return $this->schemeValue;
    }

    public function query(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->queryParams;
        }
        return $this->queryParams[$name] ?? $default;
    }

    public function post(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->postParams;
        }
        return $this->postParams[$name] ?? $default;
    }

    public function rawBody(): ?string
    {
        return null;
    }

    public function parseBody(): void
    {
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function server(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->server;
        }
        return $this->server[$name] ?? $default;
    }

    public function has(string|int $name): bool
    {
        return isset($this->params[$name]);
    }

    public function get(string|int $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function all(): array
    {
        return $this->params;
    }

    public function only(array $names): array
    {
        return array_intersect_key($this->params, array_flip($names));
    }

    public function except(array $names): array
    {
        return array_diff_key($this->params, array_flip($names));
    }

    public function filters(array $fields = [], array $custom = []): array
    {
        return [];
    }

    public function wantsJson(): bool
    {
        return false;
    }

    public function isVerb(string $verb): bool
    {
        return strcasecmp($this->verbValue, $verb) === 0;
    }

    public function isAjax(): bool
    {
        return false;
    }

    public function host(): string
    {
        return $this->server['HTTP_HOST'] ?? '';
    }

    public function port(): int
    {
        return (int)($this->server['SERVER_PORT'] ?? 80);
    }

    public function isSecure(): bool
    {
        return $this->schemeValue === 'https';
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '';
    }

    public function files(bool $onlySuccessful = true): array
    {
        return $this->files;
    }

    public function file(?string $key = null): ?\Switon\Http\Request\FileInterface
    {
        return null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]);
    }

    public function origin(bool $strict = true): string
    {
        return $this->originValue;
    }

    public function url(bool $withQuery = false): string
    {
        return $this->pathValue;
    }

    public function elapsed(int $precision = 3): float
    {
        return $this->elapsedValue;
    }

    public function handler(): string
    {
        return $this->handlerValue;
    }

    public function route(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->route;
        }

        return $this->route[$name] ?? $default;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    // Test helper methods
    public function setHeader(string $name, string $value): void
    {
        $this->headers[strtolower($name)] = $value;
    }

    public function setPath(string $path): void
    {
        $this->pathValue = $path;
    }

    public function setVerb(string $verb): void
    {
        $this->verbValue = $verb;
    }

    public function setScheme(string $scheme): void
    {
        $this->schemeValue = $scheme;
    }

    public function setParam(string $name, mixed $value): void
    {
        $this->params[$name] = $value;
    }

    public function setQuery(string $name, mixed $value): void
    {
        $this->queryParams[$name] = $value;
    }

    public function setPost(string $name, mixed $value): void
    {
        $this->postParams[$name] = $value;
    }

    public function setServer(string $name, mixed $value): void
    {
        $this->server[$name] = $value;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function setRoute(string $name, mixed $value): void
    {
        $this->route[$name] = $value;
    }

    public function setHandler(string $value): void
    {
        $this->handlerValue = $value;
    }

    public function setOrigin(string $value): void
    {
        $this->originValue = $value;
    }

    public function setElapsed(float $value): void
    {
        $this->elapsedValue = $value;
    }
}
