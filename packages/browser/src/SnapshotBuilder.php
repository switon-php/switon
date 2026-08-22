<?php

declare(strict_types=1);

namespace Switon\Browser;

use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;
use function strlen;
use function strtolower;

/**
 * Builds a compact a11y snapshot plus an `eN` → `backendDOMNodeId` map from CDP AX trees.
 *
 * Guidance: pass the page's layout when it is available. A long page has far more elements than a
 * snapshot can carry, and what a person can see is worth showing first.
 *
 * @phpstan-type AxNode array<string, mixed>
 * @phpstan-type Box array{x: float, y: float, w: float, h: float}
 */
class SnapshotBuilder
{
    /** @var list<string> */
    protected const INTERACTIVE = [
        'button',
        'link',
        'textbox',
        'searchbox',
        'combobox',
        'listbox',
        'checkbox',
        'radio',
        'switch',
        'tab',
        'menuitem',
        'menuitemcheckbox',
        'menuitemradio',
        'option',
        'slider',
        'spinbutton',
        'treeitem',
    ];

    /** Page text the agent can read. These lines have no ref. */
    protected const READABLE = [
        'heading',
        'paragraph',
        'statictext',
        'status',
        'alert',
        'image',
        'caption',
        'label',
        'listitem',
    ];

    /** Lines in a snapshot by default. Enough for a dense screen, short of a wall of text. */
    protected const MAX_LINES = 200;

    /**
     * Characters of page text before the rest folds into one summary line.
     *
     * Prose is most of a snapshot's size. Controls never fold: each one stays clickable.
     */
    public const MAX_TEXT_CHARS = 4000;

    /**
     * @param array<string, mixed> $axResult `Accessibility.getFullAXTree` result
     * @param array<int, Box> $layout backendNodeId => box
     * @param int $viewportHeight Visible height; `0` disables viewport ordering
     * @param int $maxLines Line budget
     * @param int $maxTextChars Character budget for prose; controls are never folded
     *
     * @return array{text: string, refs: array<string, int>, offscreen: int}
     */
    public static function build(
        array $axResult,
        array $layout = [],
        int $viewportHeight = 0,
        int $maxLines = self::MAX_LINES,
        int $maxTextChars = self::MAX_TEXT_CHARS,
    ): array {
        $nodes = $axResult['nodes'] ?? null;
        if (!is_array($nodes)) {
            return ['text' => '(empty accessibility tree)', 'refs' => [], 'offscreen' => 0];
        }

        $title = '';
        [$byId, $parentOf] = self::index(array_values($nodes));
        /** @var list<array{body: string, interactive: bool, inViewport: bool, backendId: int}> $rows */
        $rows = [];

        foreach ($nodes as $node) {
            if (!is_array($node) || ($node['ignored'] ?? false) === true) {
                continue;
            }

            $role = strtolower(self::axString($node['role'] ?? null));
            if ($role === '') {
                continue;
            }

            $name = self::axString($node['name'] ?? null);
            $value = self::axString($node['value'] ?? null);
            if (($role === 'rootwebarea' || $role === 'document') && $title === '' && $name !== '') {
                $title = 'title "' . self::short($name) . '"';
                continue;
            }

            $interactive = in_array($role, self::INTERACTIVE, true);
            if (!$interactive && !in_array($role, self::READABLE, true)) {
                continue;
            }
            if (!$interactive && $name === '' && $value === '') {
                continue;
            }
            // A button already carries its label. The StaticText under it would repeat that label,
            // and a chat reply lives on StaticText because its parent paragraph has no name.
            if ($role === 'statictext' && self::ownedByAncestor($node, $name, $byId, $parentOf)) {
                continue;
            }

            $body = $role === 'statictext' ? 'text' : $role;
            if ($name !== '') {
                $body .= ' "' . self::short($name, $interactive ? 80 : 240) . '"';
            }
            if ($value !== '' && $value !== $name) {
                $body .= ' value="' . self::short($value) . '"';
            }

            if ($interactive && !self::hasBackendId($node)) {
                continue;
            }

            $rows[] = [
                'body' => $body,
                'interactive' => $interactive,
                'inViewport' => self::inViewport($node, $layout, $viewportHeight),
                'backendId' => $interactive ? (int) ($node['backendDOMNodeId'] ?? 0) : 0,
            ];
        }

        // Order: what a person can see first, in document order, then everything else. Truncation
        // then drops the least useful tail instead of an arbitrary middle.
        $visible = [];
        $hidden = [];
        foreach ($rows as $row) {
            if ($row['inViewport']) {
                $visible[] = $row;
            } else {
                $hidden[] = $row;
            }
        }

        $lines = [];
        $refs = [];
        $n = 0;
        $offscreen = 0;
        $textChars = 0;
        $foldedText = 0;
        foreach (array_merge($visible, $hidden) as $row) {
            if (!$row['inViewport']) {
                $offscreen++;
                // Text below the fold is the expensive part of a snapshot. The header count says it
                // is there; scroll brings it into view. A control stays, one short line, so it can
                // still be clicked.
                if (!$row['interactive']) {
                    continue;
                }
            }
            if ($row['interactive']) {
                $n++;
                $ref = 'e' . $n;
                $refs[$ref] = $row['backendId'];
                $lines[] = "[{$ref}] " . ($row['inViewport'] ? '' : '(offscreen) ') . $row['body'];

                continue;
            }
            // Prose is what makes a snapshot big. Keep the first screenful and count the rest, so a
            // page of forty paragraphs costs what its first few do. Controls above still listed.
            $textChars += strlen($row['body']);
            if ($textChars > $maxTextChars) {
                $foldedText++;

                continue;
            }
            $lines[] = $row['body'];
        }

        if ($foldedText > 0) {
            $lines[] = sprintf('(%d more text lines; scroll or narrow the page to read them)', $foldedText);
        }

        if ($title !== '') {
            array_unshift($lines, $title);
        }
        if ($lines === []) {
            return ['text' => '(empty page)', 'refs' => [], 'offscreen' => 0];
        }

        if (count($lines) > $maxLines) {
            $dropped = count($lines) - $maxLines;
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = '… ' . $dropped . ' more beyond the line budget';
        }

        return [
            'text' => implode("\n", $lines),
            'refs' => $refs,
            'offscreen' => $offscreen,
        ];
    }

    /**
     * @param list<mixed> $nodes Indexed AX nodes from Chrome, after `array_values()`.
     *
     * @return array{0: array<string, AxNode>, 1: array<string, string>}
     */
    protected static function index(array $nodes): array
    {
        $byId = [];
        $parentOf = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = $node['nodeId'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }
            $byId[$id] = $node;
            if (is_string($node['parentId'] ?? null) && $node['parentId'] !== '') {
                $parentOf[$id] = $node['parentId'];
            }
            $children = $node['childIds'] ?? null;
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $child) {
                if (is_string($child) && $child !== '') {
                    $parentOf[$child] = $id;
                }
            }
        }

        return [$byId, $parentOf];
    }

    /**
     * A control already names itself. Text inside it, or a copy of a named parent, adds nothing.
     *
     * @param AxNode $node
     * @param array<string, AxNode> $byId
     * @param array<string, string> $parentOf
     */    protected static function ownedByAncestor(array $node, string $name, array $byId, array $parentOf): bool
    {
        $id = $node['nodeId'] ?? null;
        for ($guard = 0; is_string($id) && isset($parentOf[$id]) && $guard < 40; $guard++) {
            $id = $parentOf[$id];
            $parent = $byId[$id] ?? null;
            if (!is_array($parent)) {
                return false;
            }
            $role = strtolower(self::axString($parent['role'] ?? null));
            if (in_array($role, self::INTERACTIVE, true)) {
                return true;
            }
            $parentName = self::axString($parent['name'] ?? null);
            if ($name !== '' && $parentName === $name && in_array($role, self::READABLE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node's box is inside the viewport.
     *
     * @param AxNode $node
     * @param array<int, array{x: float, y: float, w: float, h: float}> $layout
     */
    protected static function inViewport(array $node, array $layout, int $viewportHeight): bool
    {
        if ($viewportHeight <= 0 || $layout === []) {
            // No geometry to judge by: treat everything as visible so ordering changes nothing.
            return true;
        }

        $backendId = $node['backendDOMNodeId'] ?? null;
        if (!is_int($backendId)) {
            return true;
        }
        $box = $layout[$backendId] ?? null;
        if (!is_array($box)) {
            return true;
        }

        // A box partly on screen still counts: a person can see part of it and click it.
        return $box['y'] + $box['h'] > 0 && $box['y'] < $viewportHeight;
    }

    /** @param AxNode $node */
    protected static function hasBackendId(array $node): bool
    {
        $backendId = $node['backendDOMNodeId'] ?? null;

        return is_int($backendId) && $backendId > 0;
    }

    /** @param mixed $axValue */
    protected static function axString(mixed $axValue): string
    {
        if (is_string($axValue)) {
            return trim($axValue);
        }
        if (!is_array($axValue)) {
            return '';
        }
        $value = $axValue['value'] ?? null;
        if (is_string($value) || is_int($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        return '';
    }

    protected static function short(string $text, int $max = 80): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (strlen($text) <= $max) {
            return $text;
        }

        $cut = substr($text, 0, $max - 1);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut . '…';
    }
}
