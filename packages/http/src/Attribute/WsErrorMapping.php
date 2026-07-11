<?php

declare(strict_types=1);

namespace Switon\Http\Attribute;

use Attribute;

/** Maps the Swoole connection <code>error</code> event ({@see WsMapping::VERB_ERROR}). Optional; ignored when absent. */
#[Attribute(Attribute::TARGET_METHOD)]
class WsErrorMapping extends WsMapping
{
    public function getVerb(): string
    {
        return self::VERB_ERROR;
    }
}
