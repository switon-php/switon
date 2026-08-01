<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Strings;
use Switon\Mcp\ToolSchema;

/** MCP tool `log`. */
class LogTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Git log for a book or one file. Returns commits[{commit,time,message}] (commit = 7-char abbrev). limit default 20 max 100.',
            properties: [
                'book' => [
                    'type' => 'string',
                    'description' => 'Book slug.',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Optional flat .md filename; omit for whole book.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max commits (default 20, max 100).',
                    'default' => 20,
                ],
            ],
            required: ['book'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $file = $arguments['file'] ?? '';
        $limit = $arguments['limit'];

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

        $limit = max(1, min(100, $limit));
        $args = ['log', '-n', (string) $limit, '--format=%H%x09%cI%x09%s'];
        if ($file !== '') {
            $args[] = '--follow';
            $args[] = '--';
            $args[] = $file;
        }

        $stdout = $this->git->run($repo, $args, false);
        $commits = [];
        foreach (Strings::rawLines(rtrim($stdout, "\r\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 3);
            if (count($parts) < 3) {
                continue;
            }
            $commits[] = [
                'commit' => $this->git->abbrev($parts[0]),
                'time' => $parts[1],
                'message' => $parts[2],
            ];
        }

        $out = [
            'book' => $book,
            'commits' => $commits,
        ];
        if ($file !== '') {
            $out['file'] = $file;
        }

        return $out;
    }
}
