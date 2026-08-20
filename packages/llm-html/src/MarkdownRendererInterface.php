<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMNode;

/** Render a DOM subtree to Markdown. */
interface MarkdownRendererInterface
{
    public function render(DOMNode $root): string;
}
