<?php

declare(strict_types=1);

namespace Switon\Http\Ws;

use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

interface HandlerInterface
{
    public function onOpen(Request $request): void;

    public function onMessage(Frame $frame): void;

    public function onClose(int $fd): void;

    public function onError(int $fd, int $reactorId): void;
}
