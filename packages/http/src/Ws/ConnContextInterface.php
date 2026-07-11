<?php

declare(strict_types=1);

namespace Switon\Http\Ws;

/** Per-connection state keyed by Swoole file descriptor (worker-local). */
interface ConnContextInterface
{
    /**
     * Bind a WebSocket controller to a worker-local connection fd.
     *
     * @param array<string, mixed> $variables Route variables bound to the controller
     */
    public function bind(int $fd, string $controller, array $variables = [], string $path = ''): void;

    /**
     * @return array{controller: string, variables: array<string, mixed>, path: string}|null
     */
    public function get(int $fd): ?array;

    public function remove(int $fd): void;
}
