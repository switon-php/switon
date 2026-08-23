<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\McpClient\Event\ToolCalled;
use Switon\McpClient\Event\ToolCalling;
use Switon\McpClient\Exception\AbortedTransportException;
use Switon\McpClient\Exception\RpcException;
use Switon\McpClient\Exception\TransportException;
use Switon\McpClient\Transport\TransportInterface;
use Switon\Sync\CloseableInterface;

use function array_key_last;
use function is_array;
use function is_int;
use function is_string;
use function str_starts_with;
use function trim;

/**
 * Lazy `initialize` on first use. A dead idle `ping` reconnects before the request; a rejected `ping` is not tried again. A failed request is not sent again.
 *
 * Register with {@see \Switon\Sync\TerminationInterface} (via {@see ClientDiscovery}) so CLI
 * {@see \Switon\Cli\Server} terminates the transport in-coroutine; {@see __destruct()} is only a fallback.
 */
class Client implements ClientInterface, CloseableInterface
{
    #[Autowired] protected TransportInterface $transport;
    #[Autowired] protected ClientInfo $clientInfo;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    #[Autowired] protected string $server;
    #[Autowired] protected string $brief = '';
    #[Autowired] protected float $idle = 30.0;

    protected int $nextId = 1;
    /** @var null|callable(array<string, mixed>): void */
    protected mixed $onNotify = null;
    protected bool $initialized = false;
    /** False after the server rejects `ping`. A new `initialize` turns it back on. */
    protected bool $probe = true;
    protected float $seen = 0.0;
    /** @var array<string, ToolSchema>|null */
    protected ?array $tools = null;
    protected bool $closed = false;

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeConnection();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function onNotification(callable $handler): void
    {
        $this->onNotify = $handler;
    }

    public function brief(): string
    {
        return $this->brief;
    }

    public function initialize(): void
    {
        $this->clientInfo->negotiatedProtocol = null;
        $response = $this->request('initialize', $this->nextRequestId(), [
            'protocolVersion' => $this->clientInfo->protocolVersion,
            'capabilities' => new stdClass(),
            'clientInfo' => [
                'name' => $this->clientInfo->name,
                'version' => $this->clientInfo->version,
            ],
        ]);
        $negotiated = $response['result']['protocolVersion'] ?? null;
        if (!is_string($negotiated) || trim($negotiated) === '') {
            $this->transport->close();
            Exception::raise('MCP server did not return a protocol version.');
        }
        $this->clientInfo->negotiatedProtocol = trim($negotiated);
        $this->notify('notifications/initialized');
        $this->probe = true;
        $this->initialized = true;
        $this->seen = microtime(true);
    }

    public function ping(): void
    {
        $this->send('ping', [], self::neverAbort(...));
    }

    public function listTools(bool $refresh = false): array
    {
        if (!$refresh && $this->tools !== null) {
            return $this->tools;
        }

        $tools = [];
        $cursor = null;
        /** @var array<string, true> $seen */
        $seen = [];
        do {
            $params = [];
            if (is_string($cursor) && $cursor !== '') {
                if (isset($seen[$cursor])) {
                    break;
                }
                $seen[$cursor] = true;
                $params['cursor'] = $cursor;
            }

            $response = $this->send('tools/list', $params, self::neverAbort(...));
            $raw = $response['result']['tools'] ?? [];
            if (is_array($raw)) {
                foreach ($raw as $tool) {
                    if (!is_array($tool)) {
                        continue;
                    }

                    $name = $tool['name'] ?? null;
                    if (!is_string($name) || $name === '') {
                        continue;
                    }

                    $tools[$name] = ToolSchema::of($tool);
                }
            }

            $next = $response['result']['nextCursor'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
        } while ($cursor !== null);

        return $this->tools = $tools;
    }

    public function callTool(string $name, array $arguments = [], ?callable $shouldAbort = null, bool $raw = true): mixed
    {
        $shouldAbort ??= self::neverAbort(...);
        $calling = new ToolCalling($name, $arguments);
        $this->eventDispatcher->dispatch($calling);
        $arguments = $calling->arguments;
        $wireArguments = $arguments;
        while ($wireArguments !== []) {
            $key = array_key_last($wireArguments);
            if (!is_string($key) || !str_starts_with($key, '.')) {
                break;
            }
            unset($wireArguments[$key]);
        }

        try {
            $response = $this->send('tools/call', [
                'name' => $name,
                'arguments' => $wireArguments === [] ? new stdClass() : $wireArguments,
            ], $shouldAbort);
        } catch (AbortedTransportException) {
            return $this->cancelledToolResult($raw);
        }

        $called = new ToolCalled($name, $arguments, $response['result'] ?? []);
        $this->eventDispatcher->dispatch($called);

        $result = $called->result;
        if ($raw) {
            return $result;
        }

        if (($result['isError'] ?? false) === true) {
            return ['error' => $this->textFromCallResult($result)];
        }

        return $this->textFromCallResult($result);
    }

    /** @param array<string, mixed> $result */
    protected function textFromCallResult(array $result): mixed
    {
        $content = $result['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (!is_array($block)) {
                    continue;
                }

                if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                    $parts[] = $block['text'];
                }
            }

            if ($parts !== []) {
                return implode("\n", $parts);
            }
        }

        if (isset($result['text']) && is_string($result['text'])) {
            return $result['text'];
        }

        return $result;
    }

    protected function ensureReady(): void
    {
        if (!$this->initialized) {
            $this->transport->open();
            $this->initialize();

            return;
        }

        if ($this->probe && microtime(true) - $this->seen > $this->idle) {
            try {
                $this->request('ping', $this->nextRequestId(), shouldAbort: self::neverAbort(...));
            } catch (TransportException) {
                $this->reconnect();
            } catch (RpcException) {
                $this->probe = false;
            }
        }

        $this->seen = microtime(true);
    }

    protected function reconnect(): void
    {
        $this->transport->close();
        $this->transport->open();
        $this->initialized = false;
        $this->tools = null;
        $this->initialize();
    }

    /**
     * A transport failure closes the connection and is not sent again.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function send(string $method, array $params, callable $shouldAbort): array
    {
        $this->ensureReady();
        try {
            return $this->request($method, $this->nextRequestId(), $params, $shouldAbort);
        } catch (AbortedTransportException $e) {
            throw $e;
        } catch (TransportException $e) {
            $this->closeConnection();
            throw $e;
        }
    }

    protected function closeConnection(): void
    {
        $this->transport->close();
        $this->initialized = false;
        $this->tools = null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function request(string $method, int $id, array $params = [], ?callable $shouldAbort = null): array
    {
        $response = $this->transport->send($this->encodeMessage($id, $method, $params), $shouldAbort ?? self::neverAbort(...), $this->onNotify);
        if ($response === null) {
            TransportException::raise('MCP response stream closed unexpectedly.');
        }

        return $this->decodeMessage($response);
    }

    /** @param array<string, mixed> $params */
    protected function notify(string $method, array $params = []): void
    {
        $this->transport->send($this->encodeMessage(null, $method, $params), self::neverAbort(...));
    }

    /**
     * Empty `$params` are omitted.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function encodeMessage(?int $id, string $method, array $params = []): array
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];

        if ($id !== null) {
            $message['id'] = $id;
        }

        if ($params !== []) {
            $message['params'] = $params;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    protected function decodeMessage(array $message): array
    {
        if (isset($message['error']) && is_array($message['error'])) {
            $code = $message['error']['code'] ?? -32000;
            $text = $message['error']['message'] ?? 'Unknown MCP JSON-RPC error.';
            RpcException::raise('MCP JSON-RPC error {code}: {message}', [
                'code' => is_int($code) ? $code : -32000,
                'message' => is_string($text) ? $text : 'Unknown MCP JSON-RPC error.',
            ]);
        }

        return $message;
    }

    protected function nextRequestId(): int
    {
        return $this->nextId++;
    }

    private static function neverAbort(): bool
    {
        return false;
    }

    protected function cancelledToolResult(bool $raw): mixed
    {
        if ($raw) {
            return [
                'isError' => true,
                'cancelled' => true,
                'content' => [['type' => 'text', 'text' => 'cancelled']],
            ];
        }

        return ['error' => 'cancelled', 'cancelled' => true];
    }
}
