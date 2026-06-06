<?php

declare(strict_types=1);

namespace Switon\Routing\Attribute;

use Attribute;

/**
 * Maps PUT requests to a controller action.
 *
 * Use when the action should only handle HTTP PUT.
 * Path quick reference:
 * - <code>null</code> → method-derived path under class prefix
 * - <code>''</code> → class prefix only
 * - <code>'/absolute'</code> → absolute path (ignore class prefix)
 *
 * Full rules (including <code>'segment'</code> and array paths): <code>Mapping</code>.
 *
 * @see \Switon\Routing\Attribute\Mapping
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PutMapping extends Mapping
{
    public function getVerb(): string
    {
        return 'PUT';
    }
}
