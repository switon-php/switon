<?php

declare(strict_types=1);

namespace Switon\Command\Attribute;

use Attribute;

/**
 * Declares explicit short-option alias for one command action parameter.
 *
 * Guidance: Use on action parameters only; the alias must be a single visible character.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class ShortOption
{
    public function __construct(
        public string $letter,
    ) {
    }
}
