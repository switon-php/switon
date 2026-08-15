<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Core\Json;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\AskPart;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Message\UserMessage;

#[CoversClass(UserMessage::class)]
#[CoversClass(AskPart::class)]
#[CoversClass(MediaPart::class)]
class UserMessageTest extends TestCase
{
    public function testPartsRoundTripKeepsPathRefAndMime(): void
    {
        $message = UserMessage::of('', [
            AskPart::of('caption'),
            MediaPart::of('attachments/a.png', mime: 'image/png'),
            AskPart::of('second block'),
        ]);

        $encoded = Json::parse(Json::stringify($message));
        unset($encoded['class']);
        $decoded = UserMessage::decode($encoded);

        $this->assertCount(3, $decoded->parts);
        $this->assertInstanceOf(MediaPart::class, $decoded->parts[1]);
        $this->assertSame('attachments/a.png', $decoded->parts[1]->ref);
        $this->assertSame('image/png', $decoded->parts[1]->mime);
        $this->assertArrayNotHasKey('url', $encoded['parts'][1]);
        $this->assertInstanceOf(AskPart::class, $decoded->parts[2]);
        $this->assertSame('second block', $decoded->parts[2]->text);
        $this->assertSame(AskPart::class, $encoded['parts'][2]['class']);
    }

    public function testMediaPartsOmittedFromMessageWire(): void
    {
        $message = UserMessage::of('', [
            AskPart::of('please look'),
            MediaPart::of('attachments/a.png', mime: 'image/png'),
            MediaPart::of('https://cdn.example.com/a.png', mime: 'image/png'),
            AskPart::of('sales chart Q1'),
        ]);

        // Reader-facing text is every text block; media stays off it (and off the wire).
        $this->assertSame("please look\nsales chart Q1", $message->text());
        $this->assertSame([
            ['type' => 'text', 'text' => 'please look'],
            ['type' => 'text', 'text' => 'sales chart Q1'],
        ], $message->wire());
    }

    public function testImagePathRefJson(): void
    {
        $part = MediaPart::of('attachments/a.png', mime: 'image/jpeg');

        $this->assertSame([
            'class' => MediaPart::class,
            'ref' => 'attachments/a.png',
            'mime' => 'image/jpeg',
            'extra' => [],
        ], $part->jsonSerialize());
    }

    public function testMediaExtraStaysOnPartNotWire(): void
    {
        $part = MediaPart::of('https://cdn.example.com/a.png', mime: 'image/png', extra: ['detail' => 'low']);

        $this->assertSame(['detail' => 'low'], $part->extra);
        $this->assertSame(['detail' => 'low'], $part->jsonSerialize()['extra']);
    }

    public function testImageHttpsRefRoundTrip(): void
    {
        $part = MediaPart::of('https://cdn.example.com/a.png', mime: 'image/png');

        $encoded = $part->jsonSerialize();
        $this->assertSame([
            'class' => MediaPart::class,
            'ref' => 'https://cdn.example.com/a.png',
            'mime' => 'image/png',
            'extra' => [],
        ], $encoded);

        $decoded = MediaPart::decode($encoded);
        $this->assertSame('https://cdn.example.com/a.png', $decoded->ref);
        $this->assertSame('image/png', $decoded->mime);
    }

    public function testMediaPartDecodeWithoutExtraUsesParentDefaults(): void
    {
        $decoded = MediaPart::decode([
            'class' => MediaPart::class,
            'ref' => 'attachments/a.png',
            'mime' => 'image/png',
        ]);

        $this->assertSame('attachments/a.png', $decoded->ref);
        $this->assertSame('image/png', $decoded->mime);
        $this->assertSame([], $decoded->extra);
    }

    public function testCaptionPromotesToTextPartBesideImage(): void
    {
        $message = UserMessage::of('caption', [
            MediaPart::of('https://cdn.example.com/a.png', mime: 'image/png'),
        ]);

        $this->assertInstanceOf(AskPart::class, $message->parts[0]);
        $this->assertSame('caption', $message->text());
        $this->assertSame([
            ['type' => 'text', 'text' => 'caption'],
        ], $message->wire());
    }

    public function testVideoPartsDoNotAppearOnMessageWire(): void
    {
        $message = UserMessage::of('watch', [
            MediaPart::of('https://cdn.example.com/a.mp4', mime: 'video/mp4'),
            MediaPart::of('mm_file://file_abc', mime: 'video/mp4'),
        ]);

        $this->assertSame('watch', $message->text());
        $this->assertCount(3, $message->parts);
        $this->assertSame([
            ['type' => 'text', 'text' => 'watch'],
        ], $message->wire());
    }

    public function testPlainOfUsesTextPart(): void
    {
        $message = UserMessage::of('plain');

        $this->assertArrayNotHasKey('content', $message->jsonSerialize());
        $this->assertCount(1, $message->parts);
        $this->assertInstanceOf(AskPart::class, $message->parts[0]);
        $this->assertSame('plain', $message->text());
        $this->assertSame('plain', $message->wire()[0]['text']);
    }

    public function testEmptyHasNoWireBlocks(): void
    {
        $this->assertNull(UserMessage::of('')->text());
        $this->assertNull((new UserMessage())->text());
        $this->assertSame([], UserMessage::of('')->wire());
        $this->assertSame([], (new UserMessage())->wire());
        $this->assertSame([], (new UserMessage([
            TextPart::of(''),
            new PinnedPart('skill:empty', ''),
        ]))->wire());
    }

    public function testMediaOnlyHasNullText(): void
    {
        $message = UserMessage::of('', [
            MediaPart::of('attachments/a.png', mime: 'image/png'),
        ]);

        $this->assertNull($message->text());
    }

    public function testTextFindsFirstTextPartAfterMedia(): void
    {
        $message = UserMessage::of('', [
            MediaPart::of('attachments/a.png', mime: 'image/png'),
            AskPart::of('caption'),
        ]);

        $this->assertSame('caption', $message->text());
    }

    public function testTextAfterMedia(): void
    {
        $message = UserMessage::of('蛇在哪?', [
            MediaPart::of('attachments/a.png', mime: 'image/png'),
            AskPart::of('贪吃蛇\n得分0'),
        ]);

        // Joined with a newline; the second part's `\n` is literal in the source string.
        $this->assertSame('蛇在哪?' . "\n" . '贪吃蛇\n得分0', $message->text());
        $this->assertCount(3, $message->parts);
    }

    public function testTextNullWhenOnlyImage(): void
    {
        $message = UserMessage::of('', [
            MediaPart::of('attachments/a.png', mime: 'image/png'),
        ]);

        $this->assertNull($message->text());
    }

    public function testTextJoinsEveryTextPartInOrder(): void
    {
        $message = UserMessage::of('', [
            AskPart::of('first'),
            MediaPart::of('attachments/a.png', mime: 'image/png'),
            AskPart::of('board text'),
            AskPart::of('second'),
        ]);

        $this->assertSame("first\nboard text\nsecond", $message->text());
    }

    public function testHasMedia(): void
    {
        $this->assertFalse(UserMessage::of('plain')->hasMedia());
        $this->assertTrue(UserMessage::of('', [
            AskPart::of('caption'),
            MediaPart::of('attachments/a.png', mime: 'image/png'),
        ])->hasMedia());
    }

    public function testCloneIsolatesParts(): void
    {
        $message = UserMessage::of('', [
            AskPart::of('a'),
        ]);
        $copy = clone $message;
        $copy->parts[0]->text = 'b';

        $this->assertSame('a', $message->parts[0]->text);
    }

    public function testOfFactories(): void
    {
        $this->assertSame('x', UserMessage::of('x')->wire()[0]['text']);
        $this->assertSame(UserMessage::Role, (new UserMessage())->role);
    }
}
