<?php

declare(strict_types=1);

namespace Switon\Http\Ws;

/** Incoming WebSocket frame payload for {@see \Switon\Http\Attribute\WsMessageMapping} actions. */
class Message
{
    public function __construct(public int $fd, public string $data)
    {
    }
}
