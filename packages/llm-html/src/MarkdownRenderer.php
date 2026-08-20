<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMElement;
use DOMNode;

/**
 * DOM subtree → compact Markdown suitable for LLM context.
 *
 * Covers headings, lists (ul/ol), tables, blockquotes, code, images, and safe links.
 * `<em>` → italic; `<i>` stays plain (often presentational on the open web).
 */
class MarkdownRenderer implements MarkdownRendererInterface
{
    public function render(DOMNode $root): string
    {
        return $this->compactMarkdown($this->renderNode($root));
    }

    protected function renderNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            // Do not use \v — in PCRE it matches byte 0x85 inside UTF-8 Chinese.
            return preg_replace('/[ \t\f\r]+/', ' ', $node->textContent ?? '') ?? '';
        }

        $name = strtolower($node->nodeName);

        if ($name === 'table') {
            return $this->renderTable($node);
        }

        if ($name === 'img') {
            return $this->renderImage($node);
        }

        if ($name === 'blockquote') {
            return $this->renderBlockquote($node);
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->renderNode($child);
        }

        $text = trim($inner);
        if ($name === 'a' && $text !== '' && $node->attributes) {
            $hrefNode = $node->attributes->getNamedItem('href');
            $href = $hrefNode instanceof DOMNode ? trim($hrefNode->nodeValue ?? '') : '';
            if ($this->isRenderableHref($href)) {
                return "[{$text}]({$href})";
            }

            return $text;
        }

        return match ($name) {
            'h1' => $text === '' ? '' : "\n\n# {$text}\n",
            'h2' => $text === '' ? '' : "\n\n## {$text}\n",
            'h3' => $text === '' ? '' : "\n\n### {$text}\n",
            'h4', 'h5', 'h6' => $text === '' ? '' : "\n\n#### {$text}\n",
            'pre' => $this->renderPre($node),
            'code' => $this->renderCode($node, $text),
            'p' => $text === '' ? '' : "\n\n{$text}\n",
            'div', 'section', 'article', 'main', 'ul', 'ol', 'tr', 'thead', 'tbody', 'tfoot' => $text,
            'br' => "\n",
            'hr' => "\n\n---\n",
            'li' => $this->renderListItem($node, $text),
            'strong', 'b' => $text === '' ? '' : "**{$text}**",
            // <em> = stress emphasis; <i> is widely used as a presentational span (icons, temps).
            'em' => $text === '' ? '' : "*{$text}*",
            'i' => $text,
            'td', 'th' => $text,
            default => $inner,
        };
    }

    protected function renderListItem(DOMNode $node, string $text): string
    {
        if ($text === '') {
            return '';
        }

        $parent = $node->parentNode instanceof DOMNode
            ? strtolower($node->parentNode->nodeName)
            : '';

        if ($parent === 'ol' && $node->parentNode instanceof DOMNode) {
            $index = 0;
            foreach ($node->parentNode->childNodes as $sibling) {
                if (strtolower($sibling->nodeName) !== 'li') {
                    continue;
                }
                $index++;
                if ($sibling === $node) {
                    break;
                }
            }

            return "\n{$index}. {$text}";
        }

        return "\n- {$text}";
    }

    protected function renderBlockquote(DOMNode $node): string
    {
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->renderNode($child);
        }
        $text = trim($inner);
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\n/', $text) ?: [$text];
        $quoted = implode("\n", array_map(
            static fn (string $line): string => '> ' . ltrim($line),
            $lines,
        ));

        return "\n\n{$quoted}\n";
    }

    protected function renderImage(DOMNode $node): string
    {
        if (!$node instanceof DOMElement) {
            return '';
        }

        $alt = trim($node->getAttribute('alt'));
        $src = trim($node->getAttribute('src'));
        if ($alt === '') {
            return '';
        }

        if ($src !== '' && $this->isRenderableHref($src)) {
            return " ![{$alt}]({$src}) ";
        }

        return " {$alt} ";
    }

    protected function renderTable(DOMNode $node): string
    {
        if (!$node instanceof DOMElement) {
            return '';
        }

        $rows = [];
        foreach ($node->getElementsByTagName('tr') as $tr) {
            // Only direct-ish rows under this table (skip nested tables' rows via ancestor check).
            if (!$this->isRowOfTable($tr, $node)) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $child) {
                $cellName = strtolower($child->nodeName);
                if (!in_array($cellName, ['td', 'th'], true)) {
                    continue;
                }
                $cellText = trim(preg_replace('/\s+/u', ' ', $this->renderNode($child)) ?? '');
                $cells[] = str_replace('|', '\\|', $cellText);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $normalized = [];
        foreach ($rows as $row) {
            while (count($row) < $width) {
                $row[] = '';
            }
            $normalized[] = $row;
        }

        $header = $normalized[0];
        $out = "\n\n| " . implode(' | ', $header) . " |\n";
        $out .= '| ' . implode(' | ', array_fill(0, $width, '---')) . " |\n";
        for ($i = 1, $n = count($normalized); $i < $n; $i++) {
            $out .= '| ' . implode(' | ', $normalized[$i]) . " |\n";
        }

        return $out;
    }

    protected function isRowOfTable(DOMNode $tr, DOMElement $table): bool
    {
        $parent = $tr->parentNode;
        while ($parent instanceof DOMNode) {
            $name = strtolower($parent->nodeName);
            if ($name === 'table') {
                return $parent === $table;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    protected function isRenderableHref(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return false;
        }

        $lower = strtolower($href);

        return !str_starts_with($lower, 'javascript:')
            && !str_starts_with($lower, 'data:')
            && !str_starts_with($lower, 'mailto:')
            && $lower !== 'void(0)';
    }

    protected function renderCode(DOMNode $node, string $text): string
    {
        if ($text === '') {
            return '';
        }

        $parent = $node->parentNode instanceof DOMNode
            ? strtolower($node->parentNode->nodeName)
            : '';
        if ($parent === 'pre') {
            return $text;
        }

        if (in_array($parent, ['p', 'li', 'td', 'th', 'span', 'div', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            return '`' . $text . '`';
        }

        return "\n\n```\n{$text}\n```\n";
    }

    protected function renderPre(DOMNode $node): string
    {
        $text = rtrim($node->textContent ?? '', " \t");

        return $text === '' ? '' : "\n\n```\n{$text}\n```\n";
    }

    protected function compactMarkdown(string $text): string
    {
        $parts = preg_split('/(```[\s\S]*?```)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            if ($index % 2 === 1) {
                $out .= $part;
                continue;
            }

            $part = preg_replace('/[ \t]+$/m', '', $part) ?? $part;
            $part = preg_replace('/[ \t]+/', ' ', $part) ?? $part;
            // Split glued ATX (`text# Heading`); require non-whitespace before `#` so
            // list lines like `- # Title` stay intact (space lookbehind would false-split).
            $part = preg_replace('/(?<=[^\s#])(#{1,6} )/u', "\n\n$1", $part) ?? $part;
            $part = preg_replace('/\n[ \t]*\n/', "\n\n", $part) ?? $part;
            $part = preg_replace('/\n{3,}/', "\n\n", $part) ?? $part;
            $out .= $part;
        }

        return $this->normalizeReadableText($out);
    }

    protected function normalizeReadableText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
