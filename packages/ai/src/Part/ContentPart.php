<?php

declare(strict_types=1);

namespace Switon\Ai\Part;

use JsonSerializable;

/**
 * One typed block stored in a message or tool-result part list.
 *
 * Historical tool media on the LLM wire is an encode-time text notice in `llm` (not stored here).
 * Storage uses `class`. {@see TextPart} is text for the model; {@see AskPart} is the reader-visible
 * subclass. Media and refusal stay on their own fields and are encoded by the provider.
 *
 * @see \Switon\Ai\Part\AskPart
 * @see \Switon\Ai\Part\TextPart
 * @see \Switon\Ai\Part\PinnedPart
 * @see \Switon\Ai\Part\MediaPart
 * @see \Switon\Ai\Part\RefusalPart
 */
abstract class ContentPart implements JsonSerializable
{
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['class' => static::class] + get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $part = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($part, $key)) {
                continue;
            }
            $part->$key = $value;
        }

        return $part;
    }
}
