<?php

declare(strict_types=1);

namespace Switon\Http\Attribute;

use Attribute;

/** Maps the Swoole <code>open</code> event ({@see WsMapping::VERB_OPEN}). */
#[Attribute(Attribute::TARGET_METHOD)]
class WsOpenMapping extends WsMapping
{
    public function getVerb(): string
    {
        return self::VERB_OPEN;
    }
}
