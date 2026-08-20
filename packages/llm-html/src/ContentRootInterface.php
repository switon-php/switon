<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMNode;
use DOMXPath;

/** Choose the DOM root that best represents page copy for an LLM. */
interface ContentRootInterface
{
    /**
     * @param bool $smartPrune When true, drop in-root link farms / hreflang chrome after pick.
     */
    public function select(DOMXPath $xpath, bool $smartPrune = true): ?DOMNode;

    /** Extraction quality score for `select()` result (0 = weak / body-tier). */
    public function score(DOMNode $node): float;
}
