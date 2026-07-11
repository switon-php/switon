<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Http\Ws\Message;

class WsMessageTest extends TestCase
{
    public function testCarriesFdAndPayload(): void
    {
        $message = new Message(7, '{"type":"ping"}');

        $this->assertSame(7, $message->fd);
        $this->assertSame('{"type":"ping"}', $message->data);
    }
}
