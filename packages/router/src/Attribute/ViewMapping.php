<?php

declare(strict_types=1);

namespace Switon\Routing\Attribute;

use Attribute;

/**
 * GET mapping with view-rendering marker support.
 *
 * Use when a page action should resolve as GET and participate in view rendering flow.
 * Routing behavior: ensures a GET route is available for the same pattern.
 * Path quick reference:
 * - <code>null</code> → method-derived path under class prefix
 * - <code>''</code> → class prefix only
 * - <code>'/absolute'</code> → absolute path (ignore class prefix)
 *
 * Full rules (including <code>'segment'</code> and array paths): <code>Mapping</code>.
 *
 * @see \Switon\Routing\Attribute\Mapping
 * @see \Switon\Routing\Attribute\Viewable
 * @see \Switon\Http\RequestHandler::hasViewMapping()
 * @see \Switon\Http\RequestHandler::shouldInvokeAction()
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ViewMapping extends Mapping implements Viewable
{
    public function getVerb(): string
    {
        return 'GET';
    }
}
