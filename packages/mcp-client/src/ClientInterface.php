<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\Ai\ToolSchema;

/**
 * One MCP connection. Transport is stdio or HTTP.
 *
 * @see \Switon\McpClient\Client
 */
interface ClientInterface
{
    /** Local catalog line: `url`, or `command` plus args. Available before connect. */
    public function brief(): string;

    /** @param callable(array<string, mixed>): void $handler */
    public function onNotification(callable $handler): void;

    public function ping(): void;

    /**
     * Tools keyed by name. Skips unusable entries. Follows `nextCursor` until it repeats.
     * Cached until `$refresh` or the connection drops.
     *
     * @return array<string, ToolSchema>
     */
    public function listTools(bool $refresh = false): array;

    /**
     * `$raw` returns the wire result. Otherwise joined text, or `['error' => string]` when `isError`.
     * Null `$shouldAbort` does not abort. Trailing `.` keys are not sent.
     *
     * @param array<string, mixed> $arguments
     * @param null|callable(): bool $shouldAbort
     *
     * @return array<string, mixed>|mixed
     */
    public function callTool(string $name, array $arguments = [], ?callable $shouldAbort = null, bool $raw = true): mixed;
}
