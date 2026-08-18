<?php

declare(strict_types=1);

namespace Switon\Thread\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Thread\ExcerptMessage;

#[CoversClass(ExcerptMessage::class)]
class ExcerptMessageTest extends TestCase
{
    public function testBodyKeepsReadableRowsAndDerivesTheHeader(): void
    {
        $message = new ExcerptMessage([
            ['role' => 'user', 'text' => ' kept '],
            ['role' => 'asst', 'text' => '  '],
        ], true);

        $this->assertSame(ExcerptMessage::HEADER . "\nuser: kept", $message->text());

        $stored = $message->jsonSerialize();
        $this->assertArrayNotHasKey('content', $stored);
        $this->assertTrue($stored['dropped']);

        $restored = ExcerptMessage::decode([
            'items' => $stored['items'],
            'dropped' => $stored['dropped'],
        ]);
        $this->assertSame($message->text(), $restored->text());
    }

    public function testClearFlagOmitsTheHeader(): void
    {
        $message = new ExcerptMessage([
            ['role' => 'signal', 'text' => 'stop'],
        ], false);

        $this->assertSame('signal: stop', $message->text());
    }
}
