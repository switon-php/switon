<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;
use Switon\Ai\Part\ContentPart;
use Throwable;

/**
 * One tool call output: handler return in `$content`, or a `Throwable` on failure.
 * `wire()` serializes a failure to `{class, message}`.
 */
class ToolResult implements JsonSerializable
{
    /** True when the call failed. The kind stays on the exception class. */
    public bool $isError = false;

    /**
     * @param array<string, mixed> $attributes Local/UI metadata; never sent on the provider wire.
     * @param list<ContentPart> $parts
     * @param ?string $recall Relative datadir path for the full spilled body; null when inline.
     */
    public function __construct(
        public mixed       $content = '',
        public string      $id = '',
        public string      $name = '',
        public ?ToolTiming $timing = null,
        public array       $attributes = [],
        public array       $parts = [],
        public ?string     $recall = null,
    ) {
        if ($this->content instanceof Throwable) {
            $this->isError = true;
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<ContentPart> $parts
     */
    public static function of(
        ToolCall    $call,
        mixed       $content = '',
        ?ToolTiming $timing = null,
        array       $attributes = [],
        array       $parts = [],
    ): static {
        return new static($content, $call->id, $call->name, $timing, $attributes, $parts);
    }

    /** An exception becomes `{class, message}`. Anything else, including a decoded failure, is returned as-is. */
    public function wire(): mixed
    {
        if ($this->content instanceof Throwable) {
            return [
                'class' => $this->content::class,
                'message' => $this->content->getMessage(),
            ];
        }

        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = ['class' => static::class] + get_object_vars($this);
        $data['content'] = $this->wire();

        return $data;
    }

    public function __clone(): void
    {
        if ($this->timing !== null) {
            $this->timing = clone $this->timing;
        }
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
        if ($data['timing'] !== null) {
            $data['timing'] = ToolTiming::decode($data['timing']);
        }

        $data['parts'] = self::decodeParts($data['parts']);

        $result = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($result, $key)) {
                continue;
            }
            $result->$key = $value;
        }

        return $result;
    }

    /**
     * Polymorphic `$parts` rows: each row names its own class.
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
