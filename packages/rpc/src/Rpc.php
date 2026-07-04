<?php

declare(strict_types=1);

namespace Switon\Rpc;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\Communal;
use Switon\Core\ContainerInterface;
use Switon\Core\RandomInterface;
use Switon\Core\RunnerInterface;
use Switon\Executor\ExecutorInterface;
use Switon\Invoking\InvokerInterface;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;
use Switon\Rpc\Event\RpcCalling;
use Switon\Rpc\Event\RpcDaemonFailed;
use Switon\Rpc\Event\RpcReceived;
use Switon\Rpc\Exception\RpcMethodException;
use Switon\Rpc\Exception\RpcProtocolException;
use Switon\Rpc\Exception\RpcRemoteException;
use Switon\Rpc\Exception\RpcTimeoutException;
use Throwable;

use function array_key_exists;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function microtime;
use function usleep;

/**
 * Default queue-backed RPC client and server.
 *
 * Use when RPC should stay a thin request-reply layer over `switon/queue`.
 *
 * Road-signs:
 * - call() sends request and waits on reply queue
 * - daemon() pulls requests and submits workers
 * - run() handles one request envelope
 * - reply()/replyError() emit reply envelopes
 *
 * @see \Switon\Rpc\RpcInterface
 * @see \Switon\Queue\QueueInterface
 * @see \Switon\Rpc\Exception\RpcMethodException
 */
class Rpc implements RpcInterface, RunnerInterface
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ExecutorInterface $executor;
    #[Autowired] protected InvokerInterface $invoker;
    #[Autowired] protected RandomInterface $random;
    #[Autowired] protected QueueInterface $transport;

    #[Autowired] protected int $concurrency = 8192;
    /** @var array<string, string> */
    #[Autowired] protected array $methods = [];
    #[Autowired] protected string $queue = 'rpc';

    #[Communal] protected string $replyQueue;
    #[Communal] protected int $sequence = 0;
    /** @var array<string, QueueEnvelope|self> */
    #[Communal] protected array $replies = [];

    public function __construct()
    {
        $this->replyQueue = '.rpc.reply.' . $this->random->uuid();
    }

    /**
     * Sends one RPC request and waits for the matching reply envelope.
     */
    public function call(string $method, array $params = [], float $timeout = 3): mixed
    {
        $correlationId = (string)++$this->sequence;
        $this->replies[$correlationId] = $this;

        $request = QueueEnvelope::of(['method' => $method, 'params' => $params]);
        $request->correlation_id = $correlationId;
        $request->reply_to = $this->replyQueue;
        $this->eventDispatcher->dispatch(new RpcCalling($this, $method, $params, $correlationId, $timeout));

        try {
            $this->transport->push($this->queue, $request);
        } catch (Throwable $e) {
            unset($this->replies[$correlationId]);
            throw $e;
        }

        $deadline = microtime(true) + $timeout;
        while (true) {
            $reply = $this->replies[$correlationId];
            if ($reply instanceof QueueEnvelope) {
                unset($this->replies[$correlationId]);
                return $this->unwrapReply($method, $reply);
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                unset($this->replies[$correlationId]);
                RpcTimeoutException::raise(
                    'RPC call "{method}" timed out after {timeout} seconds.',
                    ['method' => $method, 'timeout' => $timeout]
                );
            }

            $reply = $this->transport->pull($this->replyQueue, $remaining);
            if ($reply !== null) {
                $replyId = (string)$reply->correlation_id;
                if (isset($this->replies[$replyId])) {
                    $this->replies[$replyId] = $reply;
                }
            }
        }
    }

    /**
     * Runs the long-lived RPC daemon loop.
     */
    public function daemon(): void
    {
        while (true) {
            $method = '';
            $correlationId = '';
            $replyTo = '';
            try {
                $envelope = $this->transport->pull($this->queue);
                if ($envelope === null) {
                    continue;
                }

                $method = (string)($envelope->body['method'] ?? '');
                $params = $envelope->body['params'] ?? [];
                $correlationId = $envelope->correlation_id ?? '';
                $replyTo = $envelope->reply_to ?? '';
                $this->eventDispatcher->dispatch(new RpcReceived($this, $method, $params, $correlationId, $replyTo));
                $this->executor->submit(static::class, $envelope, $this->concurrency);
            } catch (Throwable $e) {
                if ($replyTo !== '' && $correlationId !== '') {
                    try {
                        $this->replyError($replyTo, $correlationId, $e);
                    } catch (Throwable $replyError) {
                        $e = $replyError;
                    }
                }

                $this->eventDispatcher->dispatch(new RpcDaemonFailed($this, $method, $e));
                usleep(200000);
            }
        }
    }

    /**
     * Handles one dequeued RPC request envelope.
     *
     * @param QueueEnvelope $payload
     */
    public function run(mixed $payload): mixed
    {
        /** @var QueueEnvelope $envelope */
        $envelope = $payload;
        $method = $envelope->body['method'] ?? '';
        $params = $envelope->body['params'] ?? [];
        $correlationId = $envelope->correlation_id ?? '';
        $replyTo = $envelope->reply_to ?? '';

        try {
            $result = $this->dispatch($method, $params);
            $this->reply($replyTo, $correlationId, $result);
            return $result;
        } catch (Throwable $e) {
            $this->replyError($replyTo, $correlationId, $e);
            return null;
        }
    }

    /**
     * Resolves the target RPC method and invokes it with request params.
     *
     * @param array<string, mixed> $params
     */
    protected function dispatch(string $rpcMethod, array $params = []): mixed
    {
        $rpcMethod = $this->methods[$rpcMethod] ?? $rpcMethod;
        [$class, $method] = explode('::', $rpcMethod, 2) + ['', ''];
        if ($class === '' || $method === '') {
            RpcMethodException::raise(
                'RPC method "{method}" must use "ClassName::method" format.',
                ['method' => $rpcMethod]
            );
        }

        return $this->invoker->invoke([$this->container->get($class), $method], $params);
    }

    /**
     * Sends a successful RPC reply envelope.
     */
    protected function reply(string $replyTo, string $correlationId, mixed $result = null): string
    {
        $reply = QueueEnvelope::of(['result' => $result]);
        $reply->correlation_id = $correlationId;

        return $this->transport->push($replyTo, $reply);
    }

    /**
     * Sends an error RPC reply envelope.
     */
    protected function replyError(string $replyTo, string $correlationId, Throwable $error): string
    {
        $reply = QueueEnvelope::of([
            'error' => [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
            ],
        ]);
        $reply->correlation_id = $correlationId;

        return $this->transport->push($replyTo, $reply);
    }

    /**
     * Validates and unwraps an RPC reply envelope.
     */
    protected function unwrapReply(string $method, QueueEnvelope $reply): mixed
    {
        if (array_key_exists('error', $reply->body)) {
            $error = $reply->body['error'];
            if (is_array($error) && is_string($error['class'] ?? null) && is_string($error['message'] ?? null)) {
                RpcRemoteException::raise(
                    'RPC call "{method}" failed with {class}: {message}',
                    [
                        'method' => $method,
                        'class' => $error['class'],
                        'message' => $error['message'],
                    ],
                    is_int($error['code'] ?? null) ? $error['code'] : 0
                );
            }

            RpcProtocolException::raise(
                'RPC reply for "{method}" contains malformed "error" payload.',
                ['method' => $method]
            );
        }

        if (array_key_exists('result', $reply->body)) {
            return $reply->body['result'];
        }

        RpcProtocolException::raise(
            'RPC reply for "{method}" must contain either "result" or "error".',
            ['method' => $method]
        );
    }
}
