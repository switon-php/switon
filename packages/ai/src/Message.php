<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;
use Switon\Ai\Part\ContentPart;

use function implode;

/**
 * Abstract chat message; construct typed subclasses only.
 *
 * `$role` is the provider wire role, not a class switch; read the subclass when identity matters.
 *
 * Guidance: three projections, kept apart — {@see self::wire()} is what the provider receives
 * (text parts, possibly carrying model-only material), {@see self::text()} is what a reader
 * sees, `jsonSerialize()` is what storage keeps. Rewriters (compaction) and projections read
 * {@see self::text()}; only provider encoders read {@see self::wire()}.
 *
 * Storage uses `class`; load via `$data['class']::decode($data)`.
 *
 * @see \Switon\Ai\Message\UserMessage
 * @see \Switon\Ai\Message\AssistantMessage
 * @see \Switon\Ai\Message\ToolMessage
 * @see \Switon\Ai\Signal
 */
abstract class Message implements Letter, JsonSerializable
{
    /** @param array<string, mixed> $attributes Never on the provider wire. */
    public function __construct(
        public string $role,
        public array  $attributes = [],
    ) {
    }

    /**
     * @return list<array{type: 'text', text: string}>
     */
    abstract public function wire(): array;

    /**
     * Reader-facing text: `text` values from {@see self::wire()} joined, or null when none are set.
     * Rewriters and projections read this instead of type-testing subclasses; subclasses override
     * when their readable form differs from the wire form.
     */
    public function text(): ?string
    {
        $lines = [];
        foreach ($this->wire() as $block) {
            $lines[] = $block['text'];
        }
        $text = implode("\n", $lines);

        return $text === '' ? null : $text;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['class' => static::class] + get_object_vars($this);
    }

    /**
     * Storage rows are trusted `jsonSerialize()` output; malformed rows are storage defects.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $message = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($message, $key)) {
                continue;
            }
            $message->$key = $value;
        }

        return $message;
    }

    /**
     * Polymorphic `$parts` rows: each row names its own class.
     *
     * Guidance: subclasses holding a `$parts` list call this before {@see self::decode()}.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ContentPart>
     */
    protected static function decodeParts(array $rows): array
    {
        $parts = [];
        foreach ($rows as $row) {
            /** @var class-string<ContentPart> $class */
            $class = $row['class'];
            $parts[] = $class::decode($row);
        }

        return $parts;
    }
}
