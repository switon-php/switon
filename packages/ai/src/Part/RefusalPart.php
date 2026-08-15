<?php

declare(strict_types=1);

namespace Switon\Ai\Part;

/**
 * Provider refusal block, not ordinary reply text. Adapters encode `$refusal` explicitly,
 * the same way they encode {@see MediaPart}.
 */
class RefusalPart extends ContentPart
{
    public function __construct(public string $refusal = '')
    {
    }

    public static function of(string $refusal): static
    {
        return new static($refusal);
    }
}
