<?php

declare(strict_types=1);

namespace Switon\McpClient\Transport;

/** JSON-RPC exchange. HTTP `open()` is a no-op; stdio `open()` spawns the child. */
interface TransportInterface
{
    public function open(): void;

    /**
     * Null when `$message` is a notification.
     *
     * @param array<string, mixed> $message
     * @param callable(): bool $shouldAbort
     * @param null|callable(array<string, mixed>): void $notificationHandler
     *
     * @return array<string, mixed>|null
     */
    public function send(array $message, callable $shouldAbort, ?callable $notificationHandler = null): ?array;

    public function close(): void;
}
