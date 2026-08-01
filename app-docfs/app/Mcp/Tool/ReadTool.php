<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Strings;
use Switon\Mcp\ToolSchema;

/** MCP tool `read`. */
class ReadTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Read Markdown (.md); optional rev. Returns lines[]. Binary assets → HTTP GET /books/{book}/assets/{uuid.ext}.',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Flat .md filename (e.g. index.md).',
                ],
                'rev' => [
                    'type' => 'string',
                    'description' => 'Git commit; omit for working tree.',
                ],
            ],
            required: ['book', 'file'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $file = $arguments['file'];
        $rev = $arguments['rev'] ?? '';

        if (!Strings::isSlug($file) || !str_ends_with($file, '.md')) {
            InvalidArgumentException::raise('Invalid file name');
        }
        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, ['perm' => CredentialInterface::PERM_READ]),
        ]]);
        $book = $arguments['book'];

        $repo = '@books/' . $book;

        if ($rev !== '') {
            $raw = $this->git->showFile($repo, $rev, $file);

            return [
                'file' => $file,
                'size' => strlen($raw),
                'sha' => $this->git->abbrev($this->git->hashObject($raw)),
                'rev' => $rev,
                'commit' => $this->git->abbrev($this->git->resolveRev($repo, $rev)),
                'lines' => Strings::rawLines($raw),
            ];
        }

        $path = $repo . '/' . $file;
        if (!$this->filesystem->exists($path)) {
            FileNotFoundException::raise('not found');
        }

        $raw = $this->filesystem->read($path);
        $entry = $this->fileEntry($book, $file, $raw);
        $head = $this->git->head($repo);
        if ($head !== null) {
            $entry['commit'] = $this->git->abbrev($head);
        }
        $entry['lines'] = Strings::rawLines($raw);

        return $entry;
    }
}
