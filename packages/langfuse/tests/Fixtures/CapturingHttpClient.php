<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Fixtures;

use LogicException;
use Switon\HttpClient\HttpClientInterface;
use Switon\HttpClient\Request;
use Switon\HttpClient\RequestOptions;
use Switon\HttpClient\Response;
use Throwable;

class CapturingHttpClient implements HttpClientInterface
{
    /** @var list<array{url: string|array<int|string, mixed>, body: string|array<int|string, mixed>, headers: array<string, mixed>}> */
    public array $posts = [];

    public ?Throwable $throwOnPost = null;

    public function request(
        string $method,
        string|array $url,
        null|string|array $body = null,
        array $headers = [],
        ?RequestOptions $options = null,
    ): Response {
        throw new LogicException('CapturingHttpClient supports post() only.');
    }

    public function head(string|array $url, array $headers = [], ?RequestOptions $options = null): Response
    {
        throw new LogicException('CapturingHttpClient supports post() only.');
    }

    public function get(string|array $url, array $headers = [], ?RequestOptions $options = null): Response
    {
        throw new LogicException('CapturingHttpClient supports post() only.');
    }

    public function post(
        string|array $url,
        string|array $body = [],
        array $headers = [],
        ?RequestOptions $options = null,
    ): Response {
        if ($this->throwOnPost !== null) {
            throw $this->throwOnPost;
        }

        $this->posts[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        $encodedBody = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

        return new Response(
            new Request('POST', $url, $encodedBody, $headers, []),
            ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
            '{}',
        );
    }

    public function delete(string|array $url, array $headers = [], ?RequestOptions $options = null): Response
    {
        throw new LogicException('CapturingHttpClient supports post() only.');
    }

    public function put(
        string|array $url,
        string|array $body = [],
        array $headers = [],
        ?RequestOptions $options = null,
    ): Response {
        throw new LogicException('CapturingHttpClient supports post() only.');
    }

    public function patch(
        string|array $url,
        string|array $body = [],
        array $headers = [],
        ?RequestOptions $options = null,
    ): Response {
        throw new LogicException('CapturingHttpClient supports post() only.');
    }

    public function fetch(string $url, string $method = 'GET', array $headers = [], ?string $body = null, array $options = []): array
    {
        throw new LogicException('CapturingHttpClient supports post() only.');
    }
}
