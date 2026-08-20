<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Switon\Core\Attribute\Autowired;

use function in_array;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function ltrim;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function substr;

/**
 * Default {@see LlmHtmlInterface}: clean → root → markdown|text.
 *
 * Assumes the caller routed HTML here. Cheap sniff still passthroughs obvious non-HTML.
 * Truncation is the caller's responsibility. Does not throw for weak pages.
 */
class LlmHtml implements LlmHtmlInterface
{
    /** Ids that name a content root. Any other id stays an ordinary block. */
    private const array CONTENT_IDS = ['content', 'main', 'main-content', 'mainContent', 'primary'];

    #[Autowired] protected DomCleanerInterface $cleaner;
    #[Autowired] protected ContentRootInterface $contentRoot;
    #[Autowired] protected MarkdownRendererInterface $markdownRenderer;
    #[Autowired] protected PlainTextRendererInterface $plainTextRenderer;

    /** Non-body root at or above this score counts as main content. */
    #[Autowired] protected float $mainScore = 48.0;
    /** Trusted landmark must reach this length to stay cleaned. */
    #[Autowired] protected int $earlyStopMin = 800;
    /** Trusted landmark at or below this length stays cleaned. */
    #[Autowired] protected int $earlyStopMax = 10000;
    /** Pruned pass collapsed when shorter than this. */
    #[Autowired] protected int $collapseMin = 1000;
    /** Pruned pass collapsed when shorter than this fraction of the cleaned pass. */
    #[Autowired] protected float $collapseRatio = 0.25;

    public function convert(string $html, string $format = 'markdown'): LlmHtmlResult
    {
        $format = $this->normalizeFormat($format);

        if (!$this->looksLikeHtml($html)) {
            return new LlmHtmlResult(
                content: $html,
                strategy: 'passthrough',
                usedMainContent: false,
                cleanup: Cleanup::Original,
                format: $format,
            );
        }

        $cleaned = $this->runPass($html, prune: false, format: $format);

        if ($this->shouldEarlyStop($cleaned)) {
            return $this->toResult($cleaned, Cleanup::Cleaned, $format);
        }

        $pruned = $this->runPass($html, prune: true, format: $format);
        if ($this->prunedCollapsed($cleaned['length'], $pruned['length'])) {
            return $this->toResult($cleaned, Cleanup::Cleaned, $format);
        }

        return $this->toResult($pruned, Cleanup::Pruned, $format);
    }

    /**
     * Drop script/style blocks before DOM parse (case-insensitive, DOTALL).
     * libxml often spills broken script bodies into text nodes; remove them first.
     */
    protected function stripScriptAndStyle(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        return $html;
    }

    /**
     * Safety valve: document-level tags only. Avoids `<div>` false positives in MD/code.
     */
    protected function looksLikeHtml(string $payload): bool
    {
        $sample = ltrim($payload);
        if ($sample === '') {
            return false;
        }

        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            $sample = ltrim(substr($sample, 3));
        }

        $sample = preg_replace('/^(?:<!--.*?-->\s*)+/s', '', $sample) ?? $sample;

        return preg_match('/^<(?:!doctype\s+html|html|head|body|script)\b/i', $sample) === 1;
    }

    protected function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return $format === 'text' ? 'text' : 'markdown';
    }

    /**
     * @return array{
     *   content: string,
     *   title: string,
     *   description: string,
     *   strategy: string,
     *   usedMain: bool,
     *   length: int,
     *   trusted: bool
     * }
     */
    protected function runPass(string $html, bool $prune, string $format): array
    {
        // libxml's HTML parser often spills broken <script>/<style> bodies into text nodes;
        // strip them before parse so cleaner does not fight leaked JS/CSS.
        $html = $this->stripScriptAndStyle($html);

        $errors = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            $xpath = new DOMXPath($dom);

            $this->cleaner->cleanMust($xpath);
            $this->cleaner->cleanSmart($xpath);
            if ($prune) {
                $this->cleaner->cleanOptional($xpath);
                $this->cleaner->cleanSmart($xpath);
            }

            $title = trim((string)($xpath->evaluate('string(//title)') ?: ''))
                ?: trim((string)($xpath->evaluate('string(//meta[@property="og:title"]/@content)') ?: ''));
            $description = trim((string)($xpath->evaluate('string(//meta[@name="description"]/@content)') ?: ''))
                ?: trim((string)($xpath->evaluate('string(//meta[@property="og:description"]/@content)') ?: ''));

            $root = $this->contentRoot->select($xpath, smartPrune: true);
            $strategy = 'body';
            $usedMain = false;
            $trusted = false;

            if ($root instanceof DOMNode) {
                $strategy = $this->describeStrategy($root);
                $score = $this->contentRoot->score($root);
                $trusted = $this->isTrustedStrategy($strategy);
                $usedMain = $strategy !== 'body' && (
                    $score >= $this->mainScore
                    || $trusted
                );
            }

            $content = '';
            if ($root instanceof DOMNode) {
                $content = $format === 'text'
                    ? $this->plainTextRenderer->render($root)
                    : $this->markdownRenderer->render($root);
            }

            return [
                'content' => $content,
                'title' => $title,
                'description' => $description,
                'strategy' => $strategy,
                'usedMain' => $usedMain,
                'length' => mb_strlen($content),
                'trusted' => $trusted,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
    }

    /**
     * @param array{content: string, title: string, description: string, strategy: string, usedMain: bool, length: int, trusted: bool} $pass
     */
    protected function shouldEarlyStop(array $pass): bool
    {
        if ($pass['length'] <= 0) {
            return false;
        }

        if ($pass['trusted']
            && $pass['length'] >= $this->earlyStopMin
            && $pass['length'] <= $this->earlyStopMax
        ) {
            return true;
        }

        return false;
    }

    protected function prunedCollapsed(int $cleanedLen, int $prunedLen): bool
    {
        if ($cleanedLen <= 0) {
            return true;
        }

        if ($prunedLen < $this->collapseMin) {
            return true;
        }

        return $prunedLen < (int) floor($cleanedLen * $this->collapseRatio);
    }

    /**
     * @param array{content: string, title: string, description: string, strategy: string, usedMain: bool, length: int, trusted: bool} $pass
     */
    protected function toResult(array $pass, Cleanup $cleanup, string $format): LlmHtmlResult
    {
        return new LlmHtmlResult(
            content: $pass['content'],
            title: $pass['title'],
            description: $pass['description'],
            strategy: $pass['strategy'],
            usedMainContent: $pass['usedMain'],
            cleanup: $cleanup,
            format: $format,
        );
    }

    protected function isTrustedStrategy(string $strategy): bool
    {
        if (in_array($strategy, ['article', 'main', 'role:main'], true)) {
            return true;
        }

        return str_starts_with($strategy, 'id:')
            && in_array(substr($strategy, 3), self::CONTENT_IDS, true);
    }

    protected function describeStrategy(DOMNode $root): string
    {
        $name = strtolower($root->nodeName);
        if (in_array($name, ['article', 'main'], true)) {
            return $name;
        }

        if ($root instanceof DOMElement) {
            $role = $root->getAttribute('role');
            if ($role === 'main') {
                return 'role:main';
            }
            $id = $root->getAttribute('id');
            if (in_array($id, self::CONTENT_IDS, true)) {
                return 'id:' . $id;
            }
            $class = trim($root->getAttribute('class'));
            if ($class !== '') {
                return 'dense';
            }
        }

        return $name === 'body' ? 'body' : 'dense';
    }
}
