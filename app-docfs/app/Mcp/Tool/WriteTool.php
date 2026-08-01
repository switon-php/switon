<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Strings;
use Switon\Mcp\ToolResult;
use Switon\Mcp\ToolSchema;

/** MCP tool `write`. */
class WriteTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Write whole Markdown (.md). Binary assets → HTTP POST /books/{book}/assets. sha if exists. git_message = commit subject.',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Flat .md filename.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Full file contents.',
                ],
                'git_message' => [
                    'type' => 'string',
                    'description' => 'Git commit subject (why/what; no filename; max 100).',
                ],
                'sha' => [
                    'type' => 'string',
                    'description' => 'Expected current content sha; required if file exists.',
                ],
            ],
            required: ['book', 'file', 'content', 'git_message'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $file = $arguments['file'];
        $content = $arguments['content'];
        $sha = ($arguments['sha'] ?? '') !== '' ? $arguments['sha'] : null;

        if (!Strings::isSlug($file) || !str_ends_with($file, '.md')) {
            InvalidArgumentException::raise('Invalid file name');
        }
        $arguments = $this->validator->validate($arguments, ['book' => [
            $this->maker->make(Book::class, ['perm' => CredentialInterface::PERM_WRITE]),
        ]]);
        $book = $arguments['book'];

        $path = '@books/' . $book . '/' . $file;
        $exists = $this->filesystem->exists($path);

        if ($exists) {
            $current = $this->filesystem->read($path);
            $currentSha = $this->git->hashObject($current);
            if ($sha === null) {
                InvalidArgumentException::raise('sha required when file exists');
            }
            if (!$this->git->shaMatches($currentSha, $sha)) {
                return new ToolResult([
                    'error' => 'sha_mismatch',
                    'file' => $file,
                    'sha' => $this->git->abbrev($currentSha),
                ], true);
            }
        }

        $this->filesystem->write($path, $content);
        $this->gitCommit('@books/' . $book, $this->gitMessage($arguments));

        return $this->fileEntry($book, $file, $content);
    }
}
