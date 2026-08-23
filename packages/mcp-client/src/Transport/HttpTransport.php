<?php

declare(strict_types=1);

namespace Switon\McpClient\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\JsonException;
use Switon\Core\Json;
use Switon\HttpClient\Exception\AbortedException;
use Switon\HttpClient\HttpClientInterface;
use Switon\HttpClient\RequestOptions;
use Switon\HttpClient\Response;
use Switon\McpClient\ClientInfo;
use Switon\McpClient\Event\TransportRead;
use Switon\McpClient\Event\TransportWrite;
use Switon\McpClient\Exception;
use Switon\McpClient\Exception\AbortedTransportException;
use Switon\McpClient\Exception\DecodeException;
use Swoole\Coroutine;
use Throwable;

use function array_key_exists;
use function ctype_digit;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * One POST per exchange (`application/json` or `text/event-stream`).
 * A `202` with no JSON-RPC message fails. This transport does not open the server GET stream.
 */
class HttpTransport implements HttpTransportInterface
{
    #[Autowired] protected HttpClientInterface $httpClient;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    #[Autowired] protected string $url = '';
    /** @var array<string, mixed> */
    #[Autowired] protected array $headers = [];
    /** Negotiated protocol header. Null when built without a client identity. */
    #[Autowired] protected ?ClientInfo $clientInfo = null;

    protected ?string $sessionId = null;

    public function open(): void
    {
        // Stateless: each send() is an independent HTTP request; nothing to open ahead of time.
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function send(array $message, callable $shouldAbort, ?callable $notificationHandler = null): ?array
    {
        if ($shouldAbort()) {
            AbortedTransportException::raise('MCP HTTP request aborted.');
        }

        $this->eventDispatcher->dispatch(new TransportWrite($message));

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->url,
                Json::stringify($message),
                $this->requestHeaders(),
                RequestOptions::of([RequestOptions::SHOULD_ABORT => $shouldAbort]),
            );
        } catch (AbortedException) {
            AbortedTransportException::raise('MCP HTTP request aborted.');
        }

        $sessionId = $response->header(self::SESSION_HEADER);
        if (is_string($sessionId) && $sessionId !== '') {
            $this->sessionId = $sessionId;
        }

        $status = $response->status();
        try {
            $messages = $this->messagesFrom($response);
        } catch (DecodeException $e) {
            if ($status >= 400) {
                Exception::raise('MCP HTTP request failed with status {status}.', ['status' => $status]);
            }

            throw $e;
        }
        if (!array_key_exists('id', $message)) {
            if ($status >= 400 && $this->rpcError($messages) === null) {
                Exception::raise('MCP HTTP request failed with status {status}.', ['status' => $status]);
            }

            return null;
        }

        $requestId = $message['id'];
        $match = null;
        foreach ($messages as $decoded) {
            if (!array_key_exists('id', $decoded)) {
                if ($notificationHandler !== null) {
                    $notificationHandler($decoded);
                }
                continue;
            }

            $this->eventDispatcher->dispatch(new TransportRead($decoded));
            if (is_int($requestId) && $this->idsMatch($decoded['id'], $requestId)) {
                $match = $decoded;
            }
        }
        if ($match !== null) {
            return $match;
        }

        $error = $this->rpcError($messages);
        if ($error !== null) {
            if (!array_key_exists('id', $error)) {
                $this->eventDispatcher->dispatch(new TransportRead($error));
            }

            return $error;
        }
        if ($status === 202) {
            Exception::raise('MCP HTTP server accepted the request without a JSON-RPC response.');
        }
        if ($status >= 400) {
            Exception::raise('MCP HTTP request failed with status {status}.', ['status' => $status]);
        }

        DecodeException::raise('MCP HTTP response did not include a JSON-RPC message.');
    }

    public function close(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        $headers = $this->requestHeaders();
        $this->sessionId = null;
        // http-client's pool uses coroutine channels; Client::__destruct may run at process
        // shutdown outside a coroutine (fast /quit after /mcp). Skip DELETE then.
        // Split CI may run without ext-swoole; treat that like "outside a coroutine".
        if (!extension_loaded('swoole') || Coroutine::getCid() < 0) {
            return;
        }
        try {
            $this->httpClient->request(
                'DELETE',
                $this->url,
                null,
                $headers,
                RequestOptions::of([RequestOptions::SHOULD_ABORT => static fn (): bool => false]),
            );
        } catch (Throwable) {
            // Session teardown must not escape Client::__destruct().
        }
    }

    /**
     * @return array<string, string>
     */
    protected function requestHeaders(): array
    {
        $headers = [
            HttpClientInterface::HEADER_ACCEPT => 'application/json, text/event-stream',
            HttpClientInterface::HEADER_CONTENT_TYPE => 'application/json',
        ];
        foreach ($this->headers as $name => $value) {
            if (is_string($name) && is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }
        if ($this->sessionId !== null) {
            $headers[self::SESSION_HEADER] = $this->sessionId;
        }
        $protocol = $this->clientInfo?->negotiatedProtocol;
        if (is_string($protocol) && $protocol !== '') {
            $headers[self::PROTOCOL_HEADER] = $protocol;
        }

        return $headers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function messagesFrom(Response $response): array
    {
        $body = $response->text();
        if (str_contains(strtolower($response->getContentType()), 'text/event-stream')) {
            return $this->parseEventStream($body);
        }
        if (trim($body) === '') {
            return [];
        }

        try {
            $decoded = Json::parse($body);
        } catch (JsonException $e) {
            DecodeException::raise('Invalid MCP JSON-RPC response: {message}', ['message' => $e->getMessage()]);
        }
        if (!is_array($decoded)) {
            DecodeException::raise('MCP JSON-RPC response must decode to an object.');
        }

        return [$decoded];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parseEventStream(string $body): array
    {
        $messages = [];
        $data = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        foreach ($lines as $line) {
            if ($line === '') {
                $message = $this->eventMessage($data);
                if ($message !== null) {
                    $messages[] = $message;
                }
                $data = [];
                continue;
            }
            if (str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }
        $message = $this->eventMessage($data);
        if ($message !== null) {
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param list<string> $data
     *
     * @return array<string, mixed>|null
     */
    protected function eventMessage(array $data): ?array
    {
        if ($data === []) {
            return null;
        }
        $payload = implode("\n", $data);
        if ($payload === '') {
            return null;
        }

        try {
            $decoded = Json::parse($payload);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, mixed>|null
     */
    protected function rpcError(array $messages): ?array
    {
        foreach ($messages as $decoded) {
            if (isset($decoded['error']) && is_array($decoded['error'])) {
                return $decoded;
            }
        }

        return null;
    }

    protected function idsMatch(mixed $responseId, int $requestId): bool
    {
        if ($responseId === $requestId) {
            return true;
        }

        return is_int($responseId) || (is_string($responseId) && ctype_digit($responseId))
            ? (int) $responseId === $requestId
            : false;
    }
}
