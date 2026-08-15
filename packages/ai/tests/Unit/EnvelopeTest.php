<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Envelope;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\Turn;
use Switon\Ai\Message\UserMessage;

#[CoversClass(Envelope::class)]
class EnvelopeTest extends TestCase
{
    public function testOfStringWrapsUserMessage(): void
    {
        $envelope = Envelope::of('hi', 'main', 'high');

        $this->assertInstanceOf(UserMessage::class, $envelope->letter);
        $this->assertSame('hi', $envelope->letter->text());
        $this->assertSame('main', $envelope->model);
        $this->assertSame('high', $envelope->effort);
        $this->assertNull($envelope->from);
    }

    public function testOfMessagePassesThrough(): void
    {
        $mail = RetryContinuation::of();
        $from = Turn::of('t1');
        $envelope = Envelope::of($mail, 'fast', from: $from);

        $this->assertSame($mail, $envelope->letter);
        $this->assertSame('fast', $envelope->model);
        $this->assertSame($from, $envelope->from);
    }
}
