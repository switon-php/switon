<?php

declare(strict_types=1);

namespace Switon\Command\Attribute;

use Attribute;

/**
 * Marker for classes or methods hidden from default listings.
 *
 * Guidance: Consuming discovery/inspection code decides how hidden entries are filtered.
 *
 * @see \Switon\Command\CommandInspector::isHiddenCommand()
 * @see \Switon\Command\CommandInspector::getActions()
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Hidden
{
    public function __construct()
    {
    }
}
