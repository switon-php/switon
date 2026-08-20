<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMNode;

/** DOM subtree → plain text for LLM context (no markdown markers). */
interface PlainTextRendererInterface
{
    public function render(DOMNode $root): string;
}
