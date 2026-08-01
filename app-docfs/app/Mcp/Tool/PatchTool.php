<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Strings;
use Switon\Mcp\ToolResult;
use Switon\Mcp\ToolSchema;

use function is_float;
use function is_int;
use function is_string;
use function preg_match;

/**
 * MCP tool `patch` — one file, ordered `ops[]`, one commit.
 *
 * Line numbers for `replace_lines` apply to the buffer after prior ops
 * (prefer reverse line order when stacking several line edits).
 */
class PatchTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Mechanical multi-op patch on one .md (ops[] sequential; one sha + one commit). Binary assets → HTTP POST /books/{book}/assets.',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Flat .md filename.',
                ],
                'sha' => [
                    'type' => 'string',
                    'description' => 'Expected current content sha.',
                ],
                'git_message' => [
                    'type' => 'string',
                    'description' => 'Git commit subject (why/what; no filename; max 100).',
                ],
                'ops' => [
                    'type' => 'array',
                    'description' => 'Ordered edits on this file. Each item: replace_span {op,old,new,count?} or replace_lines {op,start_line,end_line,content}.',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'op' => [
                                'type' => 'string',
                                'enum' => ['replace_lines', 'replace_span'],
                            ],
                            'start_line' => ['type' => 'integer'],
                            'end_line' => ['type' => 'integer'],
                            'content' => ['type' => 'string'],
                            'old' => ['type' => 'string'],
                            'new' => ['type' => 'string'],
                            'count' => [
                                'type' => 'integer',
                                'description' => 'replace_span max replacements; default 1; 0 = all.',
                            ],
                        ],
                        'required' => ['op'],
                    ],
                ],
            ],
            required: ['book', 'file', 'sha', 'git_message', 'ops'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $file = $arguments['file'];
        $sha = $arguments['sha'];
        $ops = $arguments['ops'];

        if (!Strings::isSlug($file) || !str_ends_with($file, '.md')) {
            InvalidArgumentException::raise('Invalid file name');
        }
        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, ['perm' => CredentialInterface::PERM_WRITE]),
        ]]);
        $book = $arguments['book'];

        if (!is_array($ops) || $ops === [] || array_is_list($ops) === false) {
            InvalidArgumentException::raise('ops must be a non-empty list');
        }

        $path = '@books/' . $book . '/' . $file;
        if (!$this->filesystem->exists($path)) {
            FileNotFoundException::raise('not found');
        }

        $current = $this->filesystem->read($path);
        $currentSha = $this->git->hashObject($current);
        if (!$this->git->shaMatches($currentSha, $sha)) {
            return new ToolResult([
                'error' => 'sha_mismatch',
                'file' => $file,
                'sha' => $this->git->abbrev($currentSha),
            ], true);
        }

        $next = $current;
        foreach ($ops as $i => $opArgs) {
            if (!is_array($opArgs)) {
                InvalidArgumentException::raise('ops[{i}] must be an object', ['i' => (string) $i]);
            }
            $op = $this->opString($opArgs, 'op', $i);
            $next = match ($op) {
                'replace_lines' => $this->replaceLines(
                    $next,
                    $this->opInt($opArgs, 'start_line', $i, 0),
                    $this->opInt($opArgs, 'end_line', $i, 0),
                    $this->opString($opArgs, 'content', $i),
                ),
                'replace_span' => $this->replaceSpan(
                    $next,
                    $this->opString($opArgs, 'old', $i),
                    $this->opString($opArgs, 'new', $i),
                    array_key_exists('count', $opArgs) && $opArgs['count'] !== null
                        ? $this->opInt($opArgs, 'count', $i)
                        : 1,
                ),
                default => InvalidArgumentException::raise('Unknown op at ops[{i}]: {op}', [
                    'i' => (string) $i,
                    'op' => $op,
                ]),
            };
        }

        $this->filesystem->write($path, $next);
        $this->gitCommit('@books/' . $book, $this->gitMessage($arguments));

        return $this->fileEntry($book, $file, $next);
    }

    /**
     * @param array<string, mixed> $op
     */
    private function opString(array $op, string $field, int $index): string
    {
        $value = $op[$field] ?? '';
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        InvalidArgumentException::raise('ops[{i}].{field} must be a string', [
            'i' => (string) $index,
            'field' => $field,
        ]);
    }

    /**
     * @param array<string, mixed> $op
     */
    private function opInt(array $op, string $field, int $index, ?int $fallback = null): int
    {
        if (!array_key_exists($field, $op) || $op[$field] === null) {
            return $fallback ?? InvalidArgumentException::raise('ops[{i}].{field} must be an integer', [
                'i' => (string) $index,
                'field' => $field,
            ]);
        }

        $value = $op[$field];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('#^[+\-]?\d+$#', $value) === 1) {
            return (int) $value;
        }

        InvalidArgumentException::raise('ops[{i}].{field} must be an integer', [
            'i' => (string) $index,
            'field' => $field,
        ]);
    }

    private function replaceLines(string $raw, int $startLine, int $endLine, string $replacement): string
    {
        if ($startLine < 1 || $endLine < $startLine) {
            InvalidArgumentException::raise('Invalid line range');
        }

        $lines = Strings::rawLines($raw);
        $count = count($lines);
        if ($startLine > $count) {
            InvalidArgumentException::raise('start_line beyond end of file');
        }
        $endLine = min($endLine, $count);

        $before = array_slice($lines, 0, $startLine - 1);
        $after = array_slice($lines, $endLine);
        $insert = Strings::rawLines($replacement);
        if ($replacement !== '' && str_ends_with($replacement, "\n") && $insert !== [] && end($insert) === '') {
            array_pop($insert);
        }

        $merged = array_merge($before, $insert, $after);

        return implode("\n", $merged) . (str_ends_with($raw, "\n") || $raw === '' ? "\n" : '');
    }

    private function replaceSpan(string $raw, string $old, string $new, int $count): string
    {
        if ($old === '') {
            InvalidArgumentException::raise('old must not be empty');
        }

        if ($count === 0) {
            return str_replace($old, $new, $raw);
        }

        $pos = 0;
        $out = $raw;
        for ($i = 0; $i < $count; $i++) {
            $at = strpos($out, $old, $pos);
            if ($at === false) {
                if ($i === 0) {
                    InvalidArgumentException::raise('old not found');
                }
                break;
            }
            $out = substr_replace($out, $new, $at, strlen($old));
            $pos = $at + strlen($new);
        }

        return $out;
    }
}
