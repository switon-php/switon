<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMXPath;

/**
 * Tiered noise removal before content selection.
 *
 * must = always (payloads, forms/buttons, hard class/id chrome);
 * optional = structural landmarks when over budget;
 * smart = evidence-based (soft tokens, hreflang).
 */
interface DomCleanerInterface
{
    /** Full cleanup: must + optional + smart. */
    public function clean(DOMXPath $xpath): void;

    /** Always-on: toxic payloads, interactive chrome, hard class/id tokens. */
    public function cleanMust(DOMXPath $xpath): void;

    /** Budget-gated: nav/aside/outer header/footer. */
    public function cleanOptional(DOMXPath $xpath): void;

    /** Evidence-based: soft tokens, hreflang farms. */
    public function cleanSmart(DOMXPath $xpath): void;
}
