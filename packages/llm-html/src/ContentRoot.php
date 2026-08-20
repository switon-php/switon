<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Prefer semantic roots; otherwise score blocks by text density, link density, and tag weights.
 *
 * Scores semantic candidates and picks the best. Optional smart prune drops in-root chrome
 * (link farms / hreflang) after pick — gated by the cleanup pass.
 */
class ContentRoot implements ContentRootInterface
{
    protected float $minScore = 40.0;

    public function select(DOMXPath $xpath, bool $smartPrune = true): ?DOMNode
    {
        $best = $this->pickBestSemantic($xpath);
        if ($best instanceof DOMNode) {
            if ($smartPrune) {
                $this->pruneChromeDescendants($best);
            }

            return $best;
        }

        $body = $xpath->query('//body');
        $bodyNode = ($body !== false && $body->length > 0) ? $body->item(0) : null;
        if (!$bodyNode instanceof DOMNode) {
            return null;
        }

        $dense = $this->pickDensestBlock($bodyNode);
        if ($dense instanceof DOMNode) {
            if ($smartPrune) {
                $this->pruneChromeDescendants($dense);
            }

            return $dense;
        }

        if ($smartPrune) {
            $this->pruneDirectLinkFarms($bodyNode);
            $this->pruneChromeDescendants($bodyNode);
        }

        return $bodyNode;
    }

    public function score(DOMNode $node): float
    {
        return $this->contentScore($node);
    }

    protected function pickBestSemantic(DOMXPath $xpath): ?DOMNode
    {
        /** @var list<DOMNode> $candidates */
        $candidates = [];
        $seen = [];

        foreach ([
            '//article',
            '//main',
            '//*[@role="main"]',
            '//*[@id="content" or @id="main" or @id="main-content" or @id="mainContent" or @id="primary"]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " article ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " post-body ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " post_content ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " entry-content ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
        ] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMNode || !$this->isViableSemanticRoot($node)) {
                    continue;
                }
                $id = spl_object_id($node);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $candidates[] = $node;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $winner = $candidates[0];
        $bestScore = $this->rankingScore($winner);
        $bestLen = $this->plainLen($winner);

        for ($i = 1, $n = count($candidates); $i < $n; $i++) {
            $candidate = $candidates[$i];
            $score = $this->rankingScore($candidate);
            $len = $this->plainLen($candidate);
            if ($score > $bestScore || ($score === $bestScore && $len < $bestLen)) {
                $winner = $candidate;
                $bestScore = $score;
                $bestLen = $len;
            }
        }

        return $winner;
    }

    /**
     * Semantic landmarks are trusted unless they are tiny or link-farm-like.
     */
    protected function isViableSemanticRoot(DOMNode $node): bool
    {
        $len = $this->plainLen($node);
        if ($len < 12) {
            return false;
        }

        $stats = $this->linkStats($node);
        $avgLink = $this->avgCharsPerLink($stats);
        $isLongLinkCatalog = $avgLink >= 80.0 && $stats['count'] >= 4;
        if ($stats['density'] > 0.45 && !$isLongLinkCatalog) {
            return false;
        }

        if ($len < 40) {
            return $stats['density'] <= 0.30;
        }

        return $this->contentScore($node) >= $this->minScore;
    }

    protected function rankingScore(DOMNode $node): float
    {
        $score = $this->contentScore($node);
        if ($score > 0.0) {
            return $score;
        }

        $stats = $this->linkStats($node);
        $len = $this->plainLen($node);

        return $len * (1.0 - $stats['density']) * (1.0 - $stats['density']);
    }

    protected function pickDensestBlock(DOMNode $body): ?DOMNode
    {
        if (!$body instanceof DOMElement) {
            return null;
        }

        /** @var list<array{node: DOMElement, score: float, len: int}> $ranked */
        $ranked = [];
        foreach (['div', 'section', 'main', 'article', 'td'] as $tag) {
            foreach ($body->getElementsByTagName($tag) as $el) {
                $score = $this->contentScore($el);
                if ($score < $this->minScore) {
                    continue;
                }
                $len = $this->plainLen($el);
                $ranked[] = ['node' => $el, 'score' => $score, 'len' => $len];
            }
        }

        if ($ranked === []) {
            return null;
        }

        usort(
            $ranked,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['len'] <=> $b['len'],
        );

        $best = $ranked[0];
        $floor = $best['score'] * 0.75;
        foreach ($ranked as $candidate) {
            if ($candidate['score'] < $floor) {
                break;
            }
            if ($candidate['len'] < $best['len']) {
                $best = $candidate;
            }
        }

        return $best['node'];
    }

    /**
     * After root pick: drop link-farm / hreflang descendants that sit inside trusted landmarks.
     *
     * Semantic roots (main/article) often still embed chrome (language lists, rail links).
     */
    protected function pruneChromeDescendants(DOMNode $root): void
    {
        if (!$root instanceof DOMElement) {
            return;
        }

        /** @var list<DOMElement> $candidates */
        $candidates = [];
        foreach (['ul', 'ol', 'div', 'section', 'menu'] as $tag) {
            foreach (iterator_to_array($root->getElementsByTagName($tag)) as $el) {
                if (!$el instanceof DOMElement || $el->isSameNode($root)) {
                    continue;
                }
                if ($this->isChromeFarm($el)) {
                    $candidates[] = $el;
                }
            }
        }

        usort(
            $candidates,
            static function (DOMElement $a, DOMElement $b): int {
                return self::nodeDepth($b) <=> self::nodeDepth($a);
            },
        );

        foreach ($candidates as $el) {
            if ($el->parentNode instanceof DOMNode) {
                $el->parentNode->removeChild($el);
            }
        }
    }

    /**
     * Body-only: drop direct children that are mostly links (portal chrome shells).
     */
    protected function pruneDirectLinkFarms(DOMNode $body): void
    {
        if (!$body instanceof DOMElement) {
            return;
        }

        foreach (iterator_to_array($body->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->nodeName);
            if (in_array($name, ['article', 'main'], true)) {
                continue;
            }

            $stats = $this->linkStats($child);
            $len = $this->plainLen($child);
            $pCount = $child->getElementsByTagName('p')->length;
            $avgLink = $this->avgCharsPerLink($stats);

            // Long-title catalogs are content; short-link chrome is not.
            if ($avgLink >= 80.0) {
                continue;
            }

            $isFarm = $stats['density'] >= 0.45
                || ($stats['count'] >= 5 && $pCount === 0 && $len < 200);

            if ($isFarm && $child->parentNode instanceof DOMNode) {
                $child->parentNode->removeChild($child);
            }
        }
    }

    /**
     * Link-dense chrome: language switchers (hreflang) or large short-link lists.
     *
     * Catalogs with long per-link titles (Trafilatura-like) are kept as content.
     */
    protected function isChromeFarm(DOMElement $node): bool
    {
        $name = strtolower($node->nodeName);
        if (in_array($name, ['article', 'main'], true)) {
            return false;
        }

        if ($this->hasHeading($node)) {
            return false;
        }

        $stats = $this->linkStats($node);
        $len = $this->plainLen($node);
        $pCount = $node->getElementsByTagName('p')->length;
        $avgLink = $this->avgCharsPerLink($stats);

        // Long per-link titles ⇒ editorial catalog / TOC cards, not a nav farm.
        if ($avgLink >= 80.0) {
            return false;
        }

        $hreflang = 0;
        foreach ($node->getElementsByTagName('a') as $anchor) {
            if ($anchor->hasAttribute('hreflang')) {
                $hreflang++;
            }
        }

        if ($hreflang >= 4 && $stats['count'] > 0 && ($hreflang / $stats['count']) >= 0.5) {
            return ($len / max(1, $stats['count'])) < 40;
        }

        // Keep large prose blocks and short intentional lists (citations, TOC).
        if ($len >= 800 || $pCount >= 2 || $stats['count'] < 8) {
            return false;
        }

        return $stats['density'] >= 0.60
            || ($pCount === 0 && ($len / max(1, $stats['count'])) < 22);
    }

    protected function hasHeading(DOMElement $node): bool
    {
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            if ($node->getElementsByTagName($tag)->length > 0) {
                return true;
            }
        }

        return false;
    }

    protected static function nodeDepth(DOMNode $node): int
    {
        $depth = 0;
        $cursor = $node->parentNode;
        while ($cursor instanceof DOMNode) {
            $depth++;
            $cursor = $cursor->parentNode;
        }

        return $depth;
    }

    protected function contentScore(DOMNode $node): float
    {
        $len = $this->plainLen($node);
        if ($len < 40) {
            return 0.0;
        }

        $stats = $this->linkStats($node);
        $avgLink = $this->avgCharsPerLink($stats);
        $isLongLinkCatalog = $avgLink >= 80.0 && $stats['count'] >= 4;

        // Short-link farms are never roots; long-title catalogs may still score.
        if ($stats['density'] > 0.45 && !$isLongLinkCatalog) {
            return 0.0;
        }

        if ($stats['count'] >= 4 && ($len / max(1, $stats['count'])) < 22) {
            return 0.0;
        }

        $pCount = 0;
        $hCount = 0;
        $blockquoteCount = 0;
        $preCount = 0;
        $liCount = 0;

        if ($node instanceof DOMElement) {
            $pCount = $node->getElementsByTagName('p')->length;
            $blockquoteCount = $node->getElementsByTagName('blockquote')->length;
            $preCount = $node->getElementsByTagName('pre')->length;
            $liCount = $node->getElementsByTagName('li')->length;
            foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $h) {
                $hCount += $node->getElementsByTagName($h)->length;
            }
        }

        $density = $len * (1.0 - $stats['density']) * (1.0 - $stats['density']);
        if ($isLongLinkCatalog) {
            // Catalog pages are mostly links; soften the density square penalty.
            $density = $len * (1.0 - min(0.85, $stats['density']));
        }

        $bonus = min(10, $pCount) * 28.0
            + min(6, $hCount) * 18.0
            + min(4, $blockquoteCount) * 22.0
            + min(3, $preCount) * 25.0
            - min(20, $liCount) * 4.0
            - min(25, $stats['count']) * 2.5;

        if ($node instanceof DOMElement) {
            $classId = strtolower(trim($node->getAttribute('class') . ' ' . $node->getAttribute('id')));
            if (preg_match('/\b(content|article|post|entry|story|main-copy)\b/', $classId) === 1) {
                $bonus += 60.0;
            }
            if (preg_match('/\b(menu|nav|widget|rail|sidebar|footer|header|comment)\b/', $classId) === 1) {
                $bonus -= 80.0;
            }
            $tag = strtolower($node->nodeName);
            if (in_array($tag, ['article', 'main', 'section'], true)) {
                $bonus += 35.0;
            }
        }

        return max(0.0, $density + $bonus);
    }

    /**
     * @return array{density: float, count: int, text: int}
     */
    protected function linkStats(DOMNode $node): array
    {
        $len = max(1, $this->plainLen($node));
        $linkTextLen = 0;
        $linkCount = 0;

        if ($node instanceof DOMElement) {
            foreach ($node->getElementsByTagName('a') as $anchor) {
                $linkCount++;
                $linkTextLen += $this->plainLen($anchor);
            }
        }

        return [
            'density' => min(1.0, $linkTextLen / $len),
            'count' => $linkCount,
            'text' => $linkTextLen,
        ];
    }

    /**
     * @param array{density: float, count: int, text: int} $stats
     */
    protected function avgCharsPerLink(array $stats): float
    {
        if ($stats['count'] < 1) {
            return 0.0;
        }

        return $stats['text'] / $stats['count'];
    }

    protected function plainLen(DOMNode $node): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');

        return mb_strlen($text);
    }
}
