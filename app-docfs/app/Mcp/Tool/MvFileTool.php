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

/**
 * MCP tool `mv_file` — rename or delete a flat `.md`.
 *
 * On rename: also rewrite in-book references to the old filename (flat `*.md` only;
 * cross-book links ignored). Same commit as the move.
 */
class MvFileTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Rename or delete a flat .md. On rename: auto-rewrites in-book Markdown refs (old.md→to) in the same commit — do NOT manually re-patch those links. Cross-book links untouched. Delete does not rewrite refs.',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Source flat .md (not index.md / AGENT.md).',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'New flat .md name; omit or empty to delete. Rename auto-updates in-book links to this name.',
                ],
                'sha' => [
                    'type' => 'string',
                    'description' => 'Expected content sha.',
                ],
                'git_message' => [
                    'type' => 'string',
                    'description' => 'Commit subject (why/what; max 100).',
                ],
            ],
            required: ['book', 'file', 'sha', 'git_message'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $file = $arguments['file'];
        $to = ($arguments['to'] ?? '') !== '' ? $arguments['to'] : null;
        $sha = $arguments['sha'];

        if (!Strings::isSlug($file) || !str_ends_with($file, '.md')) {
            InvalidArgumentException::raise('Invalid file name');
        }
        if ($file === 'index.md' || $file === 'AGENT.md') {
            InvalidArgumentException::raise('Cannot delete/rename required book file');
        }
        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, ['perm' => CredentialInterface::PERM_WRITE]),
        ]]);
        $book = $arguments['book'];

        $src = '@books/' . $book . '/' . $file;
        if (!$this->filesystem->exists($src)) {
            FileNotFoundException::raise('not found');
        }

        $current = $this->filesystem->read($src);
        $currentSha = $this->git->hashObject($current);
        if (!$this->git->shaMatches($currentSha, $sha)) {
            return new ToolResult([
                'error' => 'sha_mismatch',
                'file' => $file,
                'sha' => $this->git->abbrev($currentSha),
            ], true);
        }

        if ($to === null) {
            $this->filesystem->delete($src);
            $this->gitCommit('@books/' . $book, $this->gitMessage($arguments));

            return [
                'file' => $file,
                'to' => null,
                'deleted' => true,
            ];
        }

        if (!Strings::isSlug($to) || !str_ends_with($to, '.md')) {
            InvalidArgumentException::raise('Invalid file name');
        }
        if ($to === 'index.md' || $to === 'AGENT.md') {
            InvalidArgumentException::raise('Cannot replace required book file: {rel}', ['rel' => $to]);
        }
        if ($to === $file) {
            InvalidArgumentException::raise('file and to are the same');
        }

        $dst = '@books/' . $book . '/' . $to;
        if ($this->filesystem->exists($dst)) {
            InvalidArgumentException::raise('Destination exists: {to}', ['to' => $to]);
        }

        $this->filesystem->move($src, $dst);
        $rewritten = $this->rewriteInBookRefs($book, $file, $to);
        $this->gitCommit('@books/' . $book, $this->gitMessage($arguments));

        return [
            'file' => $file,
            'to' => $to,
            'moved' => true,
            'links_updated' => true,
            'note' => 'In-book Markdown refs to the old filename were rewritten in this commit; do not re-patch those links.',
            'rewritten' => $rewritten,
        ];
    }

    /**
     * Replace whole-token `$from` with `$to` in every flat `*.md` under the book.
     * Token boundaries follow path-segment charset `[A-Za-z\d.-]` so `foo.md` does not hit `not-foo.md`.
     *
     * @return list<array{file: string, sha: string}>
     */
    private function rewriteInBookRefs(string $book, string $from, string $to): array
    {
        $repo = '@books/' . $book;
        $pattern = '/(?<![A-Za-z\d.-])' . preg_quote($from, '/') . '(?![A-Za-z\d.-])/';
        $rewritten = [];

        foreach ($this->filesystem->glob($repo . '/*.md') as $path) {
            $rel = basename($path);
            $raw = $this->filesystem->read($repo . '/' . $rel);
            $updated = preg_replace($pattern, $to, $raw, -1, $count);
            if (!is_string($updated) || $count < 1) {
                continue;
            }
            $this->filesystem->write($repo . '/' . $rel, $updated);
            $rewritten[] = [
                'file' => $rel,
                'sha' => $this->git->abbrev($this->git->hashObject($updated)),
            ];
        }

        usort($rewritten, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        return $rewritten;
    }
}
