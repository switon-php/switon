<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMNode;

/**
 * DOM subtree → compact plain text (strip tags, keep paragraph breaks).
 */
class PlainTextRenderer implements PlainTextRendererInterface
{
    public function render(DOMNode $root): string
    {
        return $this->compactPlain($this->renderNode($root));
    }

    protected function renderNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/[ \t\f\r]+/', ' ', $node->textContent ?? '') ?? '';
        }

        $name = strtolower($node->nodeName);
        if (in_array($name, ['script', 'style', 'noscript'], true)) {
            return '';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->renderNode($child);
        }

        $text = trim($inner);

        return match ($name) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'pre', 'li', 'tr' => $text === '' ? '' : "\n\n{$text}\n",
            'br' => "\n",
            'hr' => "\n\n",
            'div', 'section', 'article', 'main', 'ul', 'ol', 'td', 'th', 'thead', 'tbody', 'tfoot' => $text,
            default => $inner,
        };
    }

    protected function compactPlain(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
