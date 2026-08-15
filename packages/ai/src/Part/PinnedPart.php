<?php

declare(strict_types=1);

namespace Switon\Ai\Part;

use Switon\Ai\Exception;

/**
 * Text the fold keeps whole. `$id` selects the survivor; it is not on the wire.
 *
 * Guidance: construct with `new`. A later part with the same `$id` replaces the earlier one
 * and moves to the end. Only fold reads `$id`.
 */
class PinnedPart extends TextPart
{
    public function __construct(
        public string $id = '',
        string $text = '',
    ) {
        parent::__construct($text);
    }

    /** {@see TextPart::of()} binds the string to `$id`. This type has no one-argument factory. */
    public static function of(string $text): static
    {
        Exception::raise('Construct with new PinnedPart(id, text).', ['text' => $text]);
    }
}
