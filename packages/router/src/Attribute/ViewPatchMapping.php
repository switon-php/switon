<?php

declare(strict_types=1);

namespace Switon\Routing\Attribute;

use Attribute;

/**
 * PATCH mapping with additional view-rendering marker support.
 *
 * Use when a PATCH action should handle PATCH and also expose view-rendering route metadata.
 * Routing behavior: ensures both GET and PATCH routes exist for the same pattern.
 * Path quick reference:
 * - <code>null</code> → method-derived path under class prefix
 * - <code>''</code> → class prefix only
 * - <code>'/absolute'</code> → absolute path (ignore class prefix)
 *
 * Full rules (including <code>'segment'</code> and array paths): <code>Mapping</code>.
 *
 * @see \Switon\Routing\Attribute\Mapping
 * @see \Switon\Routing\Attribute\Viewable
 * @see \Switon\Http\RequestHandler::hasViewable()
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ViewPatchMapping extends PatchMapping implements Viewable
{
}
