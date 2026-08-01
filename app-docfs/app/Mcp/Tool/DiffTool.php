<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Strings;
use Switon\Mcp\ToolSchema;

/** MCP tool `diff`. */
class DiffTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Git unified diff: book + rev (base) + optional rev2 (default HEAD). Optional file scopes the diff. Resolved commit/commit2 are 7-char abbrev.',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Optional flat .md filename; omit for whole book.',
                ],
                'rev' => [
                    'type' => 'string',
                    'description' => 'Base (older) revision.',
                ],
                'rev2' => [
                    'type' => 'string',
                    'description' => 'Newer revision; default HEAD.',
                    'default' => 'HEAD',
                ],
            ],
            required: ['book', 'rev'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $file = $arguments['file'] ?? '';
        $rev = $arguments['rev'];
        $rev2 = $arguments['rev2'];

        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, ['perm' => CredentialInterface::PERM_READ]),
        ]]);
        $book = $arguments['book'];
        if ($file !== '') {
            if (!Strings::isSlug($file) || !str_ends_with($file, '.md')) {
                InvalidArgumentException::raise('Invalid file name');
            }
        }

        $repo = '@books/' . $book;
        if (!$this->filesystem->isDir($repo . '/.git')) {
            InvalidArgumentException::raise('Book has no git repo: {book}', ['book' => $book]);
        }

        if ($rev === '') {
            InvalidArgumentException::raise('rev is required');
        }
        if ($rev2 === '') {
            $rev2 = 'HEAD';
        }
        $this->git->assertSafeRev($rev);
        $this->git->assertSafeRev($rev2);

        $left = $this->git->resolveRev($repo, $rev);
        $right = $this->git->resolveRev($repo, $rev2);

        $args = ['diff', '--no-color', $left, $right];
        if ($file !== '') {
            $args[] = '--';
            $args[] = $file;
        }

        $diff = $this->git->run($repo, $args, false);
        $max = 200_000;
        $truncated = strlen($diff) > $max;
        if ($truncated) {
            $diff = substr($diff, 0, $max);
        }

        $out = [
            'book' => $book,
            'rev' => $rev,
            'rev2' => $rev2,
            'commit' => $this->git->abbrev($left),
            'commit2' => $this->git->abbrev($right),
            'diff' => $diff,
            'truncated' => $truncated,
        ];
        if ($file !== '') {
            $out['file'] = $file;
        }

        return $out;
    }
}
