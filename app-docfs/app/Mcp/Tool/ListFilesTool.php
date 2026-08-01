<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Core\Strings;
use Switon\Mcp\ToolSchema;

/** MCP tool `list_files` — list all files + shas in one book. */
class ListFilesTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'List all files under a book with content shas (docs + assets).',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
            ],
            required: ['book'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, ['perm' => CredentialInterface::PERM_READ]),
        ]]);
        $book = $arguments['book'];

        $head = $this->git->head('@books/' . $book);

        return [
            'book' => $book,
            'commit' => $head !== null ? $this->git->abbrev($head) : null,
            'entries' => $this->listFiles($book),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listFiles(string $book): array
    {
        $repo = '@books/' . $book;
        if (!$this->filesystem->isDir($repo . '/.git')) {
            return $this->walkFiles($book);
        }

        $out = [];
        $stdout = $this->git->run($repo, ['ls-tree', '-r', 'HEAD'], false);
        foreach (Strings::rawLines(trim($stdout)) as $line) {
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^\d+ (\w+) ([0-9a-f]+)\t(.+)$/', $line, $m)) {
                continue;
            }
            if ($m[1] !== 'blob') {
                continue;
            }
            $fileRel = $m[3];
            $file = $repo . '/' . $fileRel;
            $out[] = [
                'file' => $fileRel,
                'size' => $this->filesystem->size($file),
                'mtime' => $this->filesystem->mtime($file),
                'sha' => $this->git->abbrev($m[2]),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function walkFiles(string $book, string $rel = ''): array
    {
        $out = [];
        $dir = '@books/' . $book . ($rel !== '' ? '/' . $rel : '');
        if (!$this->filesystem->isDir($dir)) {
            return [];
        }
        foreach ($this->filesystem->list($dir) as $name) {
            if ($name === '.git') {
                continue;
            }
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            $child = '@books/' . $book . '/' . $childRel;
            if ($this->filesystem->isDir($child)) {
                foreach ($this->walkFiles($book, $childRel) as $entry) {
                    $out[] = $entry;
                }
            } else {
                $out[] = $this->fileEntry($book, $childRel);
            }
        }

        return $out;
    }
}
