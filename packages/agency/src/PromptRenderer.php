<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\AgentInterface;
use Switon\Ai\Exception;
use Switon\Ai\PromptRendererInterface;
use Switon\Ai\PromptVariablesInterface;
use Switon\Core\Attribute\Autowired;

use function array_key_exists;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function strlen;
use function substr;
use function trim;

/**
 * Interprets agent prompt templates: `{{key}}`, `{% if %}`, `{% for %}`, `{% table %}`,
 * `{% if name in list %}`, `{# comments #}`.
 *
 * `render()` places `$segments` before `$prompt`, then merges registered variables.
 *
 * Road-signs:
 * - interpret() strips comments, renderBlocks, substitutes {{ }} (recursive for nested blocks)
 * - render() calls normalizeBlankLines() once on the final string (skipped {% if %} leave stray gaps)
 * - renderBlocks() dispatches {% if %} / {% for %} / {% table %} via extractBlock()
 * - registerVariables() keeps {@see PromptVariablesInterface}; each render calls variables()
 * - lookup() resolves dotted paths: exact key first, then nested walk
 * - renderTable() synthesizes the Markdown separator when omitted
 * - {@see \Switon\Agency\Charters} passes this call's `$data`
 *
 * @see \Switon\Ai\PromptRendererInterface
 */
class PromptRenderer implements PromptRendererInterface
{
    protected const string WHITESPACE = " \t\r\n";

    protected const string TAG_PATTERN = '/\{%\s*(if|endif|for|endfor|table|endtable|\w+)(?:\s+(.*?))?\s*%\}/s';

    /** @var list<string> */
    protected const array BLOCK_TAGS = ['if', 'endif', 'for', 'endfor', 'table', 'endtable'];

    /** @var list<PromptVariablesInterface> */
    protected array $variables = [];

    public function registerVariables(PromptVariablesInterface $variables): void
    {
        $this->variables[] = $variables;
    }

    /**
     * @param list<string> $segments
     * @param array<string, mixed> $data
     */
    public function render(
        AgentInterface $agent,
        string $prompt = '',
        array $segments = [],
        array $data = [],
    ): string {
        $parts = [];
        foreach ($segments as $segment) {
            if (!is_string($segment)) {
                continue;
            }
            $segment = trim($segment);
            if ($segment !== '') {
                $parts[] = $segment;
            }
        }
        $prompt = trim($prompt);
        if ($prompt !== '') {
            $parts[] = $prompt;
        }

        $vars = $this->variables($agent);
        foreach ($data as $key => $value) {
            $vars[$key] = $value;
        }
        if ($parts === []) {
            return '';
        }

        return trim($this->normalizeBlankLines($this->interpret(implode("\n\n", $parts), $vars)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function variables(AgentInterface $agent): array
    {
        $vars = [];
        foreach ($this->variables as $variables) {
            foreach ($variables->variables($agent) as $key => $value) {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function interpret(string $template, array $data): string
    {
        if ($template === '') {
            return '';
        }

        $template = $this->stripComments($template);
        $rendered = $this->renderBlocks($template, $data);

        if (str_contains($rendered, '{{')) {
            $rendered = preg_replace_callback(
                '/\{\{\s*([\w.-]+)\s*\}\}/',
                fn (array $matches): string => $this->formatValue($this->lookup($data, $matches[1])) ?? $matches[0],
                $rendered,
            ) ?? $rendered;
        }

        return $rendered;
    }

    /** Collapse runs of 3+ newlines to one blank line — called once from `render()`. */
    protected function normalizeBlankLines(string $text): string
    {
        if (!str_contains($text, "\n\n\n")) {
            return $text;
        }

        return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    }

    protected function stripComments(string $template): string
    {
        if (!str_contains($template, '{#')) {
            return $template;
        }

        if (preg_match('/\{#(?!.*#\})/s', $template)) {
            Exception::raise('Unclosed {# comment.');
        }

        return preg_replace('/\{#.*?#\}/s', '', $template) ?? $template;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderBlocks(string $template, array $data): string
    {
        if (!str_contains($template, '{%')) {
            return $template;
        }

        $offset = 0;
        $length = strlen($template);
        $result = '';

        while ($offset < $length) {
            $tagStart = strpos($template, '{%', $offset);
            if ($tagStart === false) {
                $result .= substr($template, $offset);

                break;
            }

            $result .= substr($template, $offset, $tagStart - $offset);

            if (!preg_match(self::TAG_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE, $tagStart)) {
                Exception::raise('Unclosed template tag at offset {offset}.', ['offset' => $tagStart]);
            }

            $tagName = $matches[1][0];
            $tagEnd = $matches[0][1] + strlen($matches[0][0]);
            $args = trim($matches[2][0] ?? '');

            if ($tagName === 'if') {
                [$result, $offset] = $this->renderIf($template, $tagEnd, $args, $data, $result);

                continue;
            }

            if ($tagName === 'for') {
                [$result, $offset] = $this->renderFor($template, $tagEnd, $args, $data, $result);

                continue;
            }

            if ($tagName === 'table') {
                [$result, $offset] = $this->renderTable($template, $tagEnd, $args, $data, $result);

                continue;
            }

            if ($tagName === 'endif' || $tagName === 'endfor' || $tagName === 'endtable') {
                Exception::raise('Unexpected {% {tag} %} without matching opener.', ['tag' => $tagName]);
            }

            Exception::raise('Unknown template tag {% {tag} %}.', ['tag' => $tagName]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: int}
     */
    protected function renderIf(string $template, int $contentStart, string $condition, array $data, string $result): array
    {
        if ($condition === '') {
            Exception::raise('{% if %} requires a condition.');
        }

        [$body, $afterEnd] = $this->extractBlock($template, $contentStart, 'if', 'endif');

        if ($this->evaluateCondition($condition, $data)) {
            $result .= $this->interpret($body, $data);
        }

        return [$result, $afterEnd];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function evaluateCondition(string $condition, array $data): bool
    {
        if (preg_match('/^([\w.-]+)\s+in\s+([\w.-]+)$/', $condition, $matches)) {
            return $this->inList($matches[1], $matches[2], $data);
        }

        return $this->isTruthy($data, $condition);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function inList(string $needleKey, string $listPath, array $data): bool
    {
        $haystack = $this->lookup($data, $listPath);
        if (!is_array($haystack)) {
            return false;
        }

        return in_array($this->resolveInNeedle($needleKey, $data), $haystack, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function resolveInNeedle(string $key, array $data): string|int|float|bool
    {
        if (str_contains($key, '.')) {
            $value = $this->lookup($data, $key);
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                return $value;
            }
        } elseif (array_key_exists($key, $data)) {
            $value = $data[$key];
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                return $value;
            }
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: int}
     */
    protected function renderFor(string $template, int $contentStart, string $args, array $data, string $result): array
    {
        if (!preg_match('/^(\w+)\s+in\s+([\w.-]+)$/', $args, $matches)) {
            Exception::raise('Invalid {% for %} syntax: {args}.', ['args' => $args]);
        }

        [, $varName, $listPath] = $matches;

        [$body, $afterEnd] = $this->extractBlock($template, $contentStart, 'for', 'endfor');

        $list = $this->lookup($data, $listPath);
        if (is_array($list)) {
            foreach ($list as $item) {
                $scope = $data;
                $scope[$varName] = $item;
                $result .= $this->interpret($body, $scope);
            }
        }

        return [$result, $afterEnd];
    }

    /**
     * Markdown table: first non-empty line is header, last is row template; optional middle separator for previews.
     * Empty / non-array lists emit nothing.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: int}
     */
    protected function renderTable(string $template, int $contentStart, string $args, array $data, string $result): array
    {
        if (!preg_match('/^(\w+)\s+in\s+([\w.-]+)$/', $args, $matches)) {
            Exception::raise('Invalid {% table %} syntax: {args}.', ['args' => $args]);
        }

        [, $varName, $listPath] = $matches;

        [$body, $afterEnd] = $this->extractBlock($template, $contentStart, 'table', 'endtable');

        $list = $this->lookup($data, $listPath);
        if (!is_array($list) || $list === []) {
            return [$result, $afterEnd];
        }

        $lines = preg_split('/\R/', trim($body)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        $lineCount = count($lines);
        if ($lineCount < 2 || $lineCount > 3) {
            Exception::raise(
                '{% table %} body needs a header and row line (optional Markdown separator between them).',
            );
        }

        $header = trim($lines[0]);
        $rowTemplate = trim($lines[$lineCount - 1]);
        $separator = $lineCount === 3
            ? trim($lines[1])
            : $this->markdownSeparator($this->markdownColumnCount($header));

        $result .= $header . "\n" . $separator . "\n";
        foreach ($list as $item) {
            $scope = $data;
            $scope[$varName] = $item;
            $result .= rtrim($this->interpret($rowTemplate, $scope), self::WHITESPACE) . "\n";
        }

        return [$result, $afterEnd];
    }

    protected function markdownColumnCount(string $headerRow): int
    {
        $parts = explode('|', trim($headerRow));
        if ($parts !== [] && $parts[0] === '') {
            array_shift($parts);
        }
        if ($parts !== [] && $parts[array_key_last($parts)] === '') {
            array_pop($parts);
        }

        $count = count($parts);

        return $count > 0 ? $count : 1;
    }

    protected function markdownSeparator(int $columns): string
    {
        return '|' . implode('|', array_fill(0, $columns, '---')) . '|';
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected function extractBlock(string $template, int $offset, string $openTag, string $closeTag): array
    {
        $depth = 1;
        $bodyStart = $offset;
        $search = $offset;

        while (true) {
            if (!preg_match(self::TAG_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE, $search)) {
                Exception::raise('Unclosed {% {tag} %} block.', ['tag' => $openTag]);
            }

            $tagName = $matches[1][0];
            $tagStart = $matches[0][1];
            $tagEnd = $tagStart + strlen($matches[0][0]);

            if (!in_array($tagName, self::BLOCK_TAGS, true)) {
                Exception::raise('Unknown template tag {% {tag} %}.', ['tag' => $tagName]);
            }

            if ($tagName === $openTag) {
                $depth++;
            } elseif ($tagName === $closeTag) {
                $depth--;
                if ($depth === 0) {
                    return [substr($template, $bodyStart, $tagStart - $bodyStart), $tagEnd];
                }
            }

            $search = $tagEnd;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function isTruthy(array $data, string $name): bool
    {
        if (str_contains($name, '.')) {
            $value = $this->lookup($data, $name);

            return $value !== null && (bool)$value;
        }

        if (!array_key_exists($name, $data)) {
            return false;
        }

        return (bool)$data[$name];
    }

    /**
     * Resolve `a.b.c`: exact key first, then nested walk (remaining path as one key, else one segment).
     *
     * @param array<string, mixed> $data
     */
    protected function lookup(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        if (!str_contains($key, '.')) {
            return null;
        }

        $segments = explode('.', $key);
        $current = $data;

        for ($index = 0, $count = count($segments); $index < $count; $index++) {
            if (!is_array($current)) {
                return null;
            }

            $rest = implode('.', array_slice($segments, $index));
            if (array_key_exists($rest, $current)) {
                return $current[$rest];
            }

            $segment = $segments[$index];
            if (!array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    protected function formatValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return null;
    }
}
