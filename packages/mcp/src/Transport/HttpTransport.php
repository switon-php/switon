<?php

declare(strict_types=1);

namespace Switon\Mcp\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\JsonException;
use Switon\Core\Json;
use Switon\Core\RandomInterface;
use Switon\Core\StopFlow;
use Switon\Http\Exception\BadRequestException;
use Switon\Http\Exception\ForbiddenException;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Mcp\Event\TransportRead;
use Switon\Mcp\Event\TransportWrite;
use Switon\Mcp\SessionInterface;

use function in_array;
use function is_array;
use function is_string;
use function str_contains;
use function strtolower;

/**
 * Streamable HTTP MCP over the current HTTP request/response.
 *
 * @see \Switon\Mcp\Transport\HttpTransportInterface
 * @see \Switon\Mcp\Transport\StdioTransport
 */
class HttpTransport implements HttpTransportInterface
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected RandomInterface $random;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected SessionInterface $session;

    /**
     * Allowed `Origin` values when the header is present; empty allows same-host only.
     *
     * @var list<string>
     */
    #[Autowired] protected array $allowedOrigins = [];

    public function getSessionId(): ?string
    {
        return $this->session->getId();
    }

    public function read(): array
    {
        $this->assertOriginAllowed();
        $this->assertAcceptsStreamableHttp();

        $rawBody = $this->request->rawBody();
        if ($rawBody === null || $rawBody === '') {
            BadRequestException::raise('MCP request body is required.');
        }

        try {
            $payload = Json::parse($rawBody);
        } catch (JsonException) {
            BadRequestException::raise('MCP request body must be valid JSON.');
        }

        if (!is_array($payload)) {
            BadRequestException::raise('MCP request body must be a JSON object.');
        }

        $this->eventDispatcher->dispatch(new TransportRead($payload));

        return $payload;
    }

    public function write(array $request, array $response): ResponseInterface
    {
        $event = new TransportWrite($request, $response);
        $this->eventDispatcher->dispatch($event);
        $response = $event->response;

        if ($response === []) {
            $this->response->setStatus(202);

            return $this->response;
        }

        if ($this->isInitializeRequest($request) && !isset($response['error'])) {
            $this->response->setHeader(self::SESSION_HEADER, $this->random->chars(20));
        }

        $this->response->raw(Json::stringify($response), 'application/json');

        return $this->response;
    }

    public function terminate(): ResponseInterface
    {
        $this->response->setStatus(204);

        return $this->response;
    }

    /**
     * @param array<string, mixed> $request
     */
    protected function isInitializeRequest(array $request): bool
    {
        $method = $request['method'] ?? null;

        return is_string($method) && $method === 'initialize';
    }

    protected function assertOriginAllowed(): void
    {
        $origin = $this->request->header('origin');
        if ($origin === null || $origin === '') {
            return;
        }

        if ($this->allowedOrigins !== [] && in_array($origin, $this->allowedOrigins, true)) {
            return;
        }

        $self = $this->request->scheme() . '://' . $this->request->host();
        if ($origin === $self) {
            return;
        }

        ForbiddenException::raise('Invalid Origin header.');
    }

    protected function assertAcceptsStreamableHttp(): void
    {
        $accept = strtolower($this->request->header('accept', ''));
        if (!str_contains($accept, 'application/json') || !str_contains($accept, 'text/event-stream')) {
            $this->response->raw(
                Json::stringify([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32600,
                        'message' => 'Accept must include application/json and text/event-stream.',
                    ],
                ]),
                'application/json',
                406,
            );

            throw StopFlow::abort();
        }
    }
}
