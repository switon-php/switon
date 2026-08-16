<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Generator;
use Switon\Ai\AgentInterface;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Path;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;
use Switon\Tooling\Exception\ArgumentInvalidValueException;

use function array_merge;
use function array_slice;
use function count;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function implode;
use function ltrim;
use function max;
use function min;
use function pathinfo;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * Tool `read`. Text lines are `L{n}:`. png, jpeg, gif, and webp come back as {@see MediaPart}.
 *
 * Guidance: svg is text; video and audio stay on {@see MediaTool}. Page further text with `next.offset`.
 *
 * @see MediaTool
 */
class ReadTool extends AbstractTool
{
    #[Autowired] protected int $maxBytes = 65536;
    #[Autowired] protected int $maxLines = 2000;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Read a file from the local filesystem. Text lines are prefixed with L{n}: (1-indexed); when more remains, continue with next.offset. PNG/JPEG/GIF/WebP are returned as visual media (offset/limit ignored).',
            properties: [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute path, or @thread/ under this thread directory.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Start line (1-indexed). Negative counts from end (-1 = last line).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max lines for this page (optional; host also caps by bytes).',
                    'minimum' => 1,
                ],
            ],
            required: ['path'],
        );
    }

    /**
     * Absolute path stays. `@thread` and `@thread/` resolve under this thread's datadir.
     *
     * Guidance: the datadir alias is expanded here; `fopen` does not expand aliases.
     */
    protected function resolveReadPath(string $path, AgentInterface $agent): string
    {
        $normalized = Path::normalize($path);
        if ($normalized === '@thread' || str_starts_with($normalized, '@thread/')) {
            $relative = $normalized === '@thread' ? '' : ltrim(substr($normalized, strlen('@thread/')), '/');

            return $this->filesystem->resolve($this->threadFile($relative, $agent));
        }

        return Path::absolute($path);
    }

    /** `@thread/` under this thread's datadir. `..` stays rejected. */
    protected function threadFile(string $relative, AgentInterface $agent): string
    {
        $root = $agent->thread()->datadir;
        if ($root === null) {
            Exception::raise('Tool requires a thread directory (no agent host).');
        }
        if ($relative === '' || $relative === '.') {
            return $root;
        }

        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            Exception::raise('Relative path must not contain "..".');
        }

        return Path::normalize($root . '/' . $relative);
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        unset($call);
        $path = $this->resolveReadPath($arguments['path'], $agent);
        if (!$this->filesystem->isFile($path)) {
            Exception::raise('File not found.');
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };
        if ($mime !== null) {
            return new ToolResult('', parts: [
                MediaPart::of($path, mime: $mime),
            ]);
        }

        $offset = $arguments['offset'] ?? null;
        $limit = $arguments['limit'] ?? null;
        $windowed = $offset !== null || $limit !== null;

        return $this->readWindow(
            $path,
            (int)($offset ?? 1),
            (int)($limit ?? $this->maxLines),
            $windowed,
        );
    }

    /**
     * Do not echo request offset/limit — those are already in the tool call.
     *
     * @return array{
     *     content: string,
     *     total_lines?: int,
     *     next?: array{offset: int}
     * }
     */
    protected function readWindow(string $path, int $offset, int $limit, bool $windowed): array
    {
        $limit = min($limit, $this->maxLines);

        if ($offset < 0) {
            $page = $this->pageFromEnd($path, $offset, $limit);
            $offset = $page['start'];
        } else {
            $page = $this->streamPage($path, $offset, $limit, $windowed);
        }

        if (!$windowed && $page['total_lines'] === 0) {
            return [
                'content' => '',
            ];
        }

        if ($page['total_lines'] === 0 || $offset < 1 || $offset > $page['total_lines']) {
            ArgumentInvalidValueException::raise('{field} value invalid', ['field' => 'offset']);
        }

        $result = [
            'content' => $this->formatNumberedLines($page['lines'], $offset),
        ];

        if ($windowed) {
            $result['total_lines'] = $page['total_lines'];
        }

        if ($page['hasMore']) {
            $result['next'] = ['offset' => $page['nextOffset']];
        }

        return $result;
    }

    /**
     * Stream from `$offset` until `$limit` or `$maxBytes`.
     * Cuts only between lines. A first line alone over budget is still returned so paging advances.
     *
     * @return array{lines: list<string>, hasMore: bool, nextOffset: int, total_lines: int}
     */
    protected function streamPage(
        string $path,
        int    $offset,
        int    $limit,
        bool   $needTotal,
    ): array {
        $budget = max(1, $this->maxBytes);
        $lines = [];
        $bytes = 0;
        $lineNo = 0;
        $pageFull = false;
        $hasMore = false;

        $iterator = $this->iterateLines($path);
        while ($iterator->valid()) {
            $line = $iterator->current();
            $iterator->next();
            $lineNo++;

            if ($lineNo < $offset) {
                continue;
            }

            if (!$pageFull) {
                $add = strlen($line) + ($lines === [] ? 0 : 1);
                if ($lines !== [] && ($bytes + $add) > $budget) {
                    $hasMore = true;
                    if ($needTotal) {
                        while ($iterator->valid()) {
                            $iterator->next();
                            $lineNo++;
                        }
                    }
                    break;
                }

                $lines[] = $line;
                $bytes += $add;

                if (count($lines) >= $limit || (count($lines) === 1 && $bytes > $budget)) {
                    $pageFull = true;
                }
                continue;
            }

            $hasMore = true;
            if ($needTotal) {
                while ($iterator->valid()) {
                    $iterator->next();
                    $lineNo++;
                }
            }
            break;
        }

        $totalLines = $lineNo;

        return [
            'lines' => $lines,
            'hasMore' => $hasMore,
            'nextOffset' => $offset + count($lines),
            'total_lines' => $totalLines,
        ];
    }

    /**
     * One pass from the end: keep the last `-$offset` lines, then apply `$limit` and the byte budget.
     *
     * @return array{lines: list<string>, hasMore: bool, nextOffset: int, total_lines: int, start: int}
     */
    protected function pageFromEnd(string $path, int $offset, int $limit): array
    {
        $tailSize = -$offset;
        $total = 0;
        $ring = [];
        $cursor = 0;
        foreach ($this->iterateLines($path) as $line) {
            $total++;
            if (count($ring) < $tailSize) {
                $ring[] = $line;
                continue;
            }
            $ring[$cursor] = $line;
            $cursor = ($cursor + 1) % $tailSize;
        }

        if ($total === 0 || $tailSize > $total) {
            ArgumentInvalidValueException::raise('{field} value invalid', ['field' => 'offset']);
        }

        if ($cursor !== 0) {
            $ring = array_merge(array_slice($ring, $cursor), array_slice($ring, 0, $cursor));
        }

        $start = $total - $tailSize + 1;
        [$lines, $hasMore] = $this->takePage($ring, $limit);

        return [
            'lines' => $lines,
            'hasMore' => $hasMore,
            'nextOffset' => $start + count($lines),
            'total_lines' => $total,
            'start' => $start,
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: list<string>, 1: bool}
     */
    protected function takePage(array $lines, int $limit): array
    {
        $budget = max(1, $this->maxBytes);
        $kept = [];
        $bytes = 0;
        $pageFull = false;
        foreach ($lines as $line) {
            if ($pageFull) {
                return [$kept, true];
            }

            $add = strlen($line) + ($kept === [] ? 0 : 1);
            if ($kept !== [] && ($bytes + $add) > $budget) {
                return [$kept, true];
            }

            $kept[] = $line;
            $bytes += $add;
            if (count($kept) >= $limit || (count($kept) === 1 && $bytes > $budget)) {
                $pageFull = true;
            }
        }

        return [$kept, false];
    }

    /**
     * Yield lines with Strings::lines semantics (LF/CRLF/CR; trailing newline does not add empty line).
     *
     * @return Generator<int, string>
     */
    protected function iterateLines(string $path): Generator
    {
        $handle = $this->openFile($path);
        $buffer = '';
        $pendingCr = false;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                if ($pendingCr) {
                    if ($chunk[0] === "\n") {
                        $chunk = substr($chunk, 1);
                    }
                    $pendingCr = false;
                }

                $chunk = str_replace("\r\n", "\n", $chunk);
                if (str_ends_with($chunk, "\r")) {
                    $pendingCr = true;
                    $chunk = substr($chunk, 0, -1);
                }
                $chunk = str_replace("\r", "\n", $chunk);
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    yield substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                }
            }

            if ($buffer !== '') {
                yield $buffer;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    protected function openFile(string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            Exception::raise('Failed to read file.');
        }

        return $handle;
    }

    /**
     * @param list<string> $lines
     */
    protected function formatNumberedLines(array $lines, int $startLine): string
    {
        $out = [];
        $n = $startLine;
        foreach ($lines as $line) {
            $out[] = 'L' . $n . ': ' . $line;
            $n++;
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

}
