<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Strings;
use Switon\Mcp\ToolSchema;

use function preg_match;
use function str_contains;
use function str_ends_with;

/** MCP tool `grep` — search flat `*.md` in one book. */
class GrepTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Search flat .md in one book. Returns matches[{file,sha,hits[{line,text}]}].',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'PCRE pattern (no delimiters).',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Optional root .md name or glob (concepts-*.md). No directories.',
                ],
            ],
            required: ['book', 'pattern'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $pattern = $arguments['pattern'];
        $file = $arguments['file'] ?? '';

        if ($pattern === '') {
            InvalidArgumentException::raise('pattern is required');
        }

        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, ['perm' => CredentialInterface::PERM_READ]),
        ]]);
        $book = $arguments['book'];

        $regex = "\x01" . $pattern . "\x01u";
        if (@preg_match($regex, '') === false) {
            InvalidArgumentException::raise('Invalid pattern');
        }

        $repo = '@books/' . $book;
        $glob = $file !== '' ? $file : '*.md';
        if ($file !== '' && !$this->isRootMdGlob($file)) {
            InvalidArgumentException::raise('Invalid file name');
        }
        $files = [];
        foreach ($this->filesystem->glob($repo . '/' . $glob) as $path) {
            $name = basename($path);
            if (!str_ends_with($name, '.md') || !Strings::isSlug($name)) {
                continue;
            }
            $files[] = $name;
        }
        sort($files);
        if ($file !== '' && $files === []) {
            FileNotFoundException::raise('not found');
        }

        $matches = [];
        $hitCount = 0;
        $limit = 200;
        $truncated = false;
        foreach ($files as $fileRel) {
            $raw = $this->filesystem->read($repo . '/' . $fileRel);
            $lines = Strings::rawLines($raw);
            $hits = [];
            foreach ($lines as $i => $text) {
                if (preg_match($regex, $text) !== 1) {
                    continue;
                }
                $hits[] = [
                    'line' => $i + 1,
                    'text' => $text,
                ];
                $hitCount++;
                if ($hitCount >= $limit) {
                    $truncated = true;
                    break;
                }
            }
            if ($hits !== []) {
                $matches[] = [
                    'file' => $fileRel,
                    'sha' => $this->git->abbrev($this->git->hashObject($raw)),
                    'hits' => $hits,
                ];
            }
            if ($truncated) {
                break;
            }
        }

        $out = [
            'book' => $book,
            'pattern' => $pattern,
            'matches' => $matches,
            'truncated' => $truncated,
        ];
        if ($file !== '') {
            $out['file'] = $file;
        }

        return $out;
    }

    private function isRootMdGlob(string $file): bool
    {
        return !str_contains($file, '/')
            && !str_contains($file, '\\')
            && !str_contains($file, '**')
            && !str_contains($file, '..')
            && preg_match('/^[A-Za-z\d.*?-]+\.md$/', $file) === 1;
    }
}
