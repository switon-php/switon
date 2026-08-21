<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit\Transport;

use Switon\McpClient\Exception\AbortedTransportException;
use Switon\McpClient\Transport\TransportInterface;

class StubTransport implements TransportInterface
{
    /** @var list<?array<string, mixed>> Queued responses; a null entry yields EOF (send returns null). */
    public array $responses = [];

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public int $openCount = 0;
    public int $closeCount = 0;

    public function open(): void
    {
        $this->openCount++;
    }

    public function send(array $message, callable $shouldAbort, ?callable $notificationHandler = null): ?array
    {
        if ($shouldAbort()) {
            AbortedTransportException::raise('MCP stub request aborted.');
        }

        $this->sent[] = $message;

        if (!array_key_exists('id', $message)) {
            return null;
        }

        return array_shift($this->responses);
    }

    public function close(): void
    {
        $this->closeCount++;
    }
}
