<?php

declare(strict_types=1);

namespace Switon\Http\Attribute;

use Attribute;

/** Maps the Swoole <code>close</code> event ({@see WsMapping::VERB_CLOSE}). */
#[Attribute(Attribute::TARGET_METHOD)]
class WsCloseMapping extends WsMapping
{
    public function getVerb(): string
    {
        return self::VERB_CLOSE;
    }
}
