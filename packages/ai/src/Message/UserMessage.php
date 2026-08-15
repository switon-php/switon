<?php

declare(strict_types=1);

namespace Switon\Ai\Message;

use Switon\Ai\Part\AskPart;
use Switon\Ai\Part\ContentPart;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Part\TextPart;

use function implode;

/**
 * `$parts` is the canonical representation. `wire()` emits {@see TextPart} (including {@see AskPart}
 * and {@see PinnedPart}); media and refusal encode separately. {@see self::text()} is the
 * reader ask: every {@see AskPart} joined.
 */
class UserMessage extends \Switon\Ai\Message
{
    public const string Role = 'user';

    /**
     * @param list<ContentPart> $parts
     */
    public function __construct(public array $parts = [])
    {
        parent::__construct(self::Role);
    }

    /**
     * @param list<ContentPart> $parts
     */
    public static function of(string $text, array $parts = []): static
    {
        if ($text !== '') {
            array_unshift($parts, AskPart::of($text));
        }

        return new static($parts);
    }

    /**
     * {@see TextPart} blocks, including {@see AskPart} and {@see PinnedPart}. Empty text is not a block.
     * Media and refusal stay off this list.
     *
     * @return list<array{type: 'text', text: string}>
     */
    public function wire(): array
    {
        $blocks = [];
        foreach ($this->parts as $part) {
            if ($part instanceof TextPart && $part->text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $part->text];
            }
        }

        return $blocks;
    }

    /**
     * Every {@see AskPart} in order, joined. Other {@see TextPart} subclasses are model-only.
     */
    public function text(): ?string
    {
        $lines = [];
        foreach ($this->parts as $part) {
            if ($part instanceof AskPart) {
                $lines[] = $part->text;
            }
        }
        $text = implode("\n", $lines);

        return $text === '' ? null : $text;
    }

    /** Whether `$parts` carries any {@see MediaPart} — the ask attaches an image/video/file. */
    public function hasMedia(): bool
    {
        foreach ($this->parts as $part) {
            if ($part instanceof MediaPart) {
                return true;
            }
        }

        return false;
    }

    public function __clone(): void
    {
        $parts = [];
        foreach ($this->parts as $part) {
            $parts[] = clone $part;
        }
        $this->parts = $parts;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $data['parts'] = self::decodeParts($data['parts']);

        return parent::decode($data);
    }
}
