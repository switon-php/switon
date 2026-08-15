<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Part\AskPart;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Json;

#[CoversClass(TextPart::class)]
#[CoversClass(AskPart::class)]
#[CoversClass(UserMessage::class)]
class TextPartTest extends TestCase
{
    public function testWireIsAPlainTextBlock(): void
    {
        $message = UserMessage::of('', [TextPart::of('skill body')]);

        $this->assertSame([['type' => 'text', 'text' => 'skill body']], $message->wire());
    }

    public function testTextIgnoresContextSoTheAskStaysFirst(): void
    {
        $message = UserMessage::of('what is this?', [
            TextPart::of('expanded skill body'),
        ]);

        $this->assertSame('what is this?', $message->text());
        $this->assertInstanceOf(AskPart::class, $message->parts[0]);
        $this->assertInstanceOf(TextPart::class, $message->parts[1]);
        $this->assertSame('expanded skill body', $message->parts[1]->text);
    }

    public function testContextOnlyMessageHasNoText(): void
    {
        $message = UserMessage::of('', [TextPart::of('context only')]);

        $this->assertNull($message->text());
        $this->assertCount(1, $message->parts);
        $this->assertInstanceOf(TextPart::class, $message->parts[0]);
    }

    public function testContextStillReachesTheWire(): void
    {
        $message = UserMessage::of('ask', [TextPart::of('ctx')]);

        $this->assertSame([
            ['type' => 'text', 'text' => 'ask'],
            ['type' => 'text', 'text' => 'ctx'],
        ], $message->wire());
    }

    public function testContextSurvivesStorageRoundTrip(): void
    {
        $message = UserMessage::of('ask', [
            AskPart::of('second block'),
            MediaPart::of('attachments/a.png', mime: 'image/png'),
            TextPart::of('ctx'),
        ]);

        $encoded = Json::parse(Json::stringify($message));
        unset($encoded['class']);
        $decoded = UserMessage::decode($encoded);

        $this->assertCount(4, $decoded->parts);
        $this->assertSame(TextPart::class, $encoded['parts'][3]['class']);
        $this->assertInstanceOf(TextPart::class, $decoded->parts[3]);
        $this->assertSame('ctx', $decoded->parts[3]->text);
        $this->assertSame("ask\nsecond block", $decoded->text());
    }
}
