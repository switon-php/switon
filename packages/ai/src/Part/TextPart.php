<?php

declare(strict_types=1);

namespace Switon\Ai\Part;

/**
 * Text the model receives. {@see AskPart} is the reader-visible ask; other subclasses stay off reader text.
 *
 * Guidance: `$text` is model text. {@see AskPart} is the reader ask. {@see PinnedPart} is kept whole by fold.
 */
class TextPart extends ContentPart
{
    public function __construct(public string $text = '')
    {
    }

    public static function of(string $text): static
    {
        return new static($text);
    }
}
