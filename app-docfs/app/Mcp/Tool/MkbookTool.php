<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Mcp\ToolSchema;

/** MCP tool `mkbook`. */
class MkbookTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Create a git-maintained book.',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug ([A-Za-z\\d.-]+).',
                ],
            ],
            required: ['book'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, [
                'perm' => CredentialInterface::PERM_WRITE,
                'exists' => false,
            ]),
        ]]);
        $book = $arguments['book'];

        $dir = '@books/' . $book;
        $this->filesystem->mkdir($dir, 0775);
        $this->filesystem->write($dir . '/index.md', '');
        $this->filesystem->write($dir . '/AGENT.md', '');
        $this->gitInit($dir, 'Initialize book with index.md and AGENT.md');

        return [
            'book' => $book,
            'entries' => [
                $this->fileEntry($book, 'index.md', ''),
                $this->fileEntry($book, 'AGENT.md', ''),
            ],
        ];
    }
}
