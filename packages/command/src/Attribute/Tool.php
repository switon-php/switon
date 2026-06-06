<?php

declare(strict_types=1);

namespace Switon\Command\Attribute;

use Attribute;

/**
 * Marks a discoverable command action as an AI tool.
 *
 * Guidance: Use on public action methods only; discovery renders the `doc` text into AI-facing output.
 *
 * Road-signs:
 * - command action discovery
 * - AI-facing synopsis
 * - `#[Hidden]` for exclusion
 *
 * @see \Switon\Command\CommandInspector::getActionAiDoc()
 * @see \Switon\Command\Attribute\Hidden
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Tool
{
    public function __construct(public string $doc)
    {
    }
}
