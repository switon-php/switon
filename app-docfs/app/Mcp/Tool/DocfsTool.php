<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\FilesystemInterface;
use Switon\Core\MakerInterface;
use Switon\Mcp\AbstractTool;
use Switon\Validating\ValidatorInterface;

/**
 * Docfs MCP tool base on {@see AbstractTool}: {@see FilesystemInterface} + `@books`, ACL via JWT; git via {@see Git}.
 *
 * Tools take explicit `book`; docs are flat `file.md`. Blobs under `assets/` via HTTP API.
 * Argument presence/defaults: MCP {@see \Switon\Mcp\ArgumentBinderInterface}.
 * Schema cache: use inherited <code>$this->schema ??= new ToolSchema(...)</code>.
 */
abstract class DocfsTool extends AbstractTool
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected CredentialInterface $credential;
    #[Autowired] protected Git $git;
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected ValidatorInterface $validator;

    /** Commit with git author from JWT `name`/`email`. */
    protected function gitCommit(string $dir, string $message): void
    {
        $payload = $this->credential->payload();
        $this->git->commit($dir, $message, $payload['name'], $payload['email']);
    }

    /** Init + first commit with git author from JWT `name`/`email`. */
    protected function gitInit(string $dir, string $message): void
    {
        $payload = $this->credential->payload();
        $this->git->init($dir, $message, $payload['name'], $payload['email']);
    }

    /**
     * LLM-supplied git subject (`git_message`) for mutating tools.
     * First line only; trim; max 100 chars; do not echo the file path.
     */
    protected function gitMessage(array $arguments): string
    {
        $raw = $arguments['git_message'] ?? null;
        if (!is_string($raw)) {
            InvalidArgumentException::raise('git_message required');
        }
        $line = trim(strtok(str_replace(["\r\n", "\r"], "\n", $raw), "\n") ?: '');
        if ($line === '') {
            InvalidArgumentException::raise('git_message required');
        }
        if (strlen($line) > 100) {
            InvalidArgumentException::raise('git_message too long (max 100)');
        }

        return $line;
    }

    /** @return array{file: string, size: int, mtime: int, sha: string} */
    protected function fileEntry(string $book, string $rel, ?string $raw = null): array
    {
        $path = '@books/' . $book . '/' . $rel;
        $raw ??= $this->filesystem->read($path);

        return [
            'file' => $rel,
            'size' => strlen($raw),
            'mtime' => $this->filesystem->mtime($path),
            'sha' => $this->git->abbrev($this->git->hashObject($raw)),
        ];
    }
}
