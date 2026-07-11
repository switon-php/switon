<?php

declare(strict_types=1);

namespace Switon\Http;

/**
 * WebSocket push API for controllers (implemented by the Swoole server adapter).
 *
 * @see \Switon\Http\Server\Adapter\Swoole
 */
interface WsSenderInterface
{
    public function push(int $fd, string $data): bool;

    public function disconnect(int $fd): bool;

    public function isEstablished(int $fd): bool;
}
