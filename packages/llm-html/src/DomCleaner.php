<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Tiered DOM cleanup for LLM-oriented HTML preparation.
 *
 * - must: toxic payloads, forms/buttons (layout forms demoted), hard class/id chrome
 * - optional: structural landmarks (nav/aside/outer header/footer) when over budget
 * - smart: evidence-based drops (link-heavy soft tokens, hreflang farms)
 */
class DomCleaner implements DomCleanerInterface
{
    /**
     * Always detach when class/id matches (chrome / ads). Applied by the must pass.
     *
     * @var list<string>
     */
    protected array $hardNoiseTokens = [
        'sidebar', 'side-bar',
        'ad', 'ads', 'advert', 'advertisement', 'adsense', 'ad-slot', 'adslot',
        'banner', 'breadcrumb', 'breadcrumbs',
        'cookie', 'cookies', 'cookie-banner', 'gdpr', 'cookieconsent',
        'menu', 'menubar', 'navbar', 'nav-bar', 'navigation', 'nav-menu',
        'masthead', 'site-header', 'page-header', 'site-footer', 'page-footer',
        'newsletter', 'subscribe', 'signup', 'sign-up', 'login', 'sign-in',
        'popup', 'modal', 'overlay',
        'widget', 'widgets', 'toolbar', 'pagination', 'pager', 'toc-nav',
        'disqus', 'comment', 'comments', 'comment-list',
        'disclaimer', 'disclaimers', 'legal-notice', 'legal-disclaimer',
        'risk-tip', 'risk-warning', 'risk-disclosure', 'investment-disclaimer',
    ];

    /**
     * Detach only when link-farm-like. Smart tier.
     *
     * @var list<string>
     */
    protected array $softNoiseTokens = [
        'header', 'footer',
        'promo', 'promotion',
        'related', 'related-posts', 'recommend', 'recommended',
        'share', 'social', 'social-share', 'sharing',
        'sponsor', 'sponsored',
        'consent',
    ];

    public function clean(DOMXPath $xpath): void
    {
        $this->cleanMust($xpath);
        $this->cleanOptional($xpath);
        $this->cleanSmart($xpath);
    }

    public function cleanMust(DOMXPath $xpath): void
    {
        $this->removeByQueries($xpath, [
            '//script',
            '//style',
            '//noscript',
            '//svg',
            '//canvas',
            '//template',
            '//iframe',
            '//object',
            '//embed',
            '//button',
        ]);

        // Forms: drop widgets; demote page-wrapping forms (ASP.NET-style) to div — Trafilatura-like.
        $this->demoteOrRemoveForms($xpath);

        $this->removeByQueries($xpath, [
            '//*[@hidden]',
            '//*[@aria-hidden="true"]',
            '//*[contains(translate(@style,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"display:none")]',
            '//*[contains(translate(@style,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"visibility:hidden")]',
        ]);

        // Hard class/id chrome (ads, cookie walls, subscribe modules) — not page landmarks.
        $this->removeNoiseByClassOrId($xpath, $this->hardNoiseTokens, soft: false);
    }

    /**
     * Drop small interactive forms; keep layout wrappers that hold most of the page text.
     */
    protected function demoteOrRemoveForms(DOMXPath $xpath): void
    {
        $body = $xpath->query('//body');
        $bodyNode = ($body !== false && $body->length > 0) ? $body->item(0) : null;
        $bodyLen = $bodyNode instanceof DOMNode
            ? mb_strlen(trim(preg_replace('/\s+/u', ' ', $bodyNode->textContent ?? '') ?? ''))
            : 0;

        $forms = $xpath->query('//form');
        if ($forms === false) {
            return;
        }

        foreach (iterator_to_array($forms) as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }

            $formLen = mb_strlen(trim(preg_replace('/\s+/u', ' ', $form->textContent ?? '') ?? ''));
            if ($bodyLen > 0 && $formLen > ($bodyLen / 2)) {
                $this->demoteElementToDiv($form);
                continue;
            }

            $this->detach($form);
        }
    }

    protected function demoteElementToDiv(DOMElement $element): void
    {
        $doc = $element->ownerDocument;
        if ($doc === null || !$element->parentNode instanceof DOMNode) {
            return;
        }

        $div = $doc->createElement('div');
        while ($element->firstChild !== null) {
            $div->appendChild($element->firstChild);
        }
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            $div->setAttribute($attr->name, $attr->value);
        }
        $element->parentNode->replaceChild($div, $element);
    }

    public function cleanOptional(DOMXPath $xpath): void
    {
        // Structural landmarks only — skipped when trusted keep-set already fits budget.
        $this->removeByQueries($xpath, [
            '//nav',
            '//aside',
            // Keep header/footer inside article|main (in-article titles / notes).
            '//header[not(ancestor::article or ancestor::main)]',
            '//footer[not(ancestor::article or ancestor::main)]',
        ]);
    }

    public function cleanSmart(DOMXPath $xpath): void
    {
        $this->removeNoiseByClassOrId($xpath, $this->softNoiseTokens, soft: true);
        $this->pruneHreflangFarms($xpath);
    }

    /**
     * Drop language-switcher / translation link clusters (hreflang-heavy, no headings).
     */
    protected function pruneHreflangFarms(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//*[count(.//a[@hreflang]) >= 4]');
        if ($nodes === false || $nodes->length === 0) {
            return;
        }

        /** @var list<DOMElement> $candidates */
        $candidates = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = strtolower($node->nodeName);
            if (in_array($name, ['html', 'body', 'article', 'main'], true)) {
                continue;
            }
            if ($this->hasHeading($node)) {
                continue;
            }
            if (!$this->isHreflangFarm($node)) {
                continue;
            }
            $candidates[] = $node;
        }

        $this->detachDeepestFirst($candidates);
    }

    protected function isHreflangFarm(DOMElement $node): bool
    {
        $hreflang = 0;
        $links = 0;
        foreach ($node->getElementsByTagName('a') as $anchor) {
            $links++;
            if ($anchor->hasAttribute('hreflang')) {
                $hreflang++;
            }
        }

        if ($hreflang < 4) {
            return false;
        }

        if (($hreflang / $links) < 0.5) {
            return false;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
        $len = mb_strlen($text);

        return ($len / max(1, $links)) < 40;
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

    /**
     * @param list<DOMElement> $candidates
     */
    protected function detachDeepestFirst(array $candidates): void
    {
        usort(
            $candidates,
            static function (DOMElement $a, DOMElement $b): int {
                return self::nodeDepth($b) <=> self::nodeDepth($a);
            },
        );

        foreach ($candidates as $node) {
            if ($node->parentNode instanceof DOMNode) {
                $this->detach($node);
            }
        }
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

    /**
     * @param list<string> $queries
     */
    protected function removeByQueries(DOMXPath $xpath, array $queries): void
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }

            foreach (iterator_to_array($nodes) as $node) {
                $this->detach($node);
            }
        }
    }

    /**
     * @param list<string> $tokens
     */
    protected function removeNoiseByClassOrId(DOMXPath $xpath, array $tokens, bool $soft): void
    {
        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = 'contains(concat(" ", translate(normalize-space(@class), "ABCDEFGHIJKLMNOPQRSTUVWXYZ_", "abcdefghijklmnopqrstuvwxyz-"), " "), " ' . $token . ' ")';
            $parts[] = 'contains(concat(" ", translate(normalize-space(@id), "ABCDEFGHIJKLMNOPQRSTUVWXYZ_", "abcdefghijklmnopqrstuvwxyz-"), " "), " ' . $token . ' ")';
        }

        $nodes = $xpath->query('//*[' . implode(' or ', $parts) . ']');
        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $name = strtolower($node->nodeName);
            if (in_array($name, ['article', 'main'], true)) {
                continue;
            }

            if ($soft && !$this->isLinkHeavy($node)) {
                continue;
            }

            $this->detach($node);
        }
    }

    protected function isLinkHeavy(DOMElement $node): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
        $len = mb_strlen($text);
        if ($len < 1) {
            return true;
        }

        $linkTextLen = 0;
        $linkCount = 0;
        foreach ($node->getElementsByTagName('a') as $anchor) {
            $linkCount++;
            $linkTextLen += mb_strlen(trim(preg_replace('/\s+/u', ' ', $anchor->textContent ?? '') ?? ''));
        }

        $linkDensity = $linkTextLen / $len;

        return $linkDensity >= 0.35
            || ($linkCount >= 4 && ($len / max(1, $linkCount)) < 30);
    }

    protected function detach(mixed $node): void
    {
        if ($node instanceof DOMNode && $node->parentNode instanceof DOMNode) {
            $node->parentNode->removeChild($node);
        }
    }
}
