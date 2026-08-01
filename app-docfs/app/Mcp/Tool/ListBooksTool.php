<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use Switon\Mcp\ToolSchema;

/** MCP tool `list_books` — list ACL-visible book names. */
class ListBooksTool extends DocfsTool
{
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'List book names on the bookshelf.',
            properties: [],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        $acl = $this->credential->payload()['books'];
        if (isset($acl['*'])) {
            $books = [];
            foreach ($this->filesystem->directories('@books') as $path) {
                $books[] = basename($path);
            }
        } else {
            $books = array_keys($acl);
        }

        $entries = [];
        foreach ($books as $book) {
            $entries[] = ['book' => $book];
        }

        return ['entries' => $entries];
    }
}
