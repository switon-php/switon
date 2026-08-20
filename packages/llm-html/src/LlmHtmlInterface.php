<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

/**
 * Convert noisy HTML into LLM-ready markdown or plain text.
 *
 * Callers decide whether the payload is HTML (e.g. via Content-Type). A cheap sniff
 * still passthroughs obvious non-HTML if mis-routed. Truncation is the caller's job.
 *
 * Road-signs:
 * - Roots: {@see ContentRootInterface} picks the content area (main/article/body)
 * - Clean: {@see DomCleanerInterface} strips scripts, nav, and noise
 * - Result: {@see LlmHtmlResult} carries content plus the strategy and {@see Cleanup} that won
 * - Consumer: {@see \Switon\Search\WebFetch} for readable page text
 */
interface LlmHtmlInterface
{
    /**
     * @param string $html   HTML document (or mis-routed text — may passthrough)
     * @param string $format markdown|text
     */
    public function convert(string $html, string $format = 'markdown'): LlmHtmlResult;
}
