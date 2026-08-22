<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use Switon\Cdp\WebSocket;

use function array_shift;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * WebSocket that answers CDP calls from a script. No network.
 *
 * A reply is queued as soon as the command is sent, so {@see \Switon\Cdp\Session::call()} reads it
 * on the next pump. Events listed in `$then` follow that reply and wait for a later pump.
 */
final class ScriptedSocket extends WebSocket
{
    /** @var list<string> */
    public array $sent = [];

    /** @var list<string> */
    public array $inbox = [];

    /**
     * Method name to a result array, or a function of the sent message.
     *
     * @var array<string, mixed>
     */
    public array $results = [];

    /** @var array<string, mixed> Method name to an error message, or a function of the sent message. */
    public array $failures = [];

    /**
     * Events queued after that method's reply, then forgotten.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $then = [];

    public bool $closed = false;

    public function __construct()
    {
    }

    public function sendText(string $payload): void
    {
        $this->sent[] = $payload;
        /** @var array{id: int, method: string} $message */
        $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $method = $message['method'];
        $failure = $this->failures[$method] ?? null;
        if (is_callable($failure)) {
            $failure = $failure($message);
        }
        if (is_string($failure) && $failure !== '') {
            $this->inbox[] = json_encode([
                'id' => $message['id'],
                'error' => ['message' => $failure],
            ], JSON_THROW_ON_ERROR);

            return;
        }

        $result = $this->results[$method] ?? [];
        if (is_callable($result)) {
            $result = $result($message);
        }
        $this->inbox[] = json_encode(['id' => $message['id'], 'result' => $result], JSON_THROW_ON_ERROR);
        foreach ($this->then[$method] ?? [] as $event) {
            $this->inbox[] = json_encode($event, JSON_THROW_ON_ERROR);
        }
        unset($this->then[$method]);
    }

    public function tryReceiveText(float $timeout): ?string
    {
        $next = array_shift($this->inbox);

        return $next === null ? null : $next;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
