<?php

declare(strict_types=1);

namespace Switon\Http\Attribute;

use Attribute;

/** Maps the Swoole <code>message</code> event ({@see WsMapping::VERB_MESSAGE}). */
#[Attribute(Attribute::TARGET_METHOD)]
class WsMessageMapping extends WsMapping
{
    public function getVerb(): string
    {
        return self::VERB_MESSAGE;
    }
}
