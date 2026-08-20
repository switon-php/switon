<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

/**
 * Result of an HTML → LLM-oriented conversion.
 *
 * `$cleanup`: {@see Cleanup} — original, cleaned, or pruned.
 * `$format`: markdown|text (passthrough keeps caller format request).
 */
class LlmHtmlResult
{
    public function __construct(
        public string $content,
        public string $title = '',
        public string $description = '',
        public string $strategy = 'body',
        public bool $usedMainContent = false,
        public Cleanup $cleanup = Cleanup::Cleaned,
        public string $format = 'markdown',
    ) {
    }
}
