<?php

declare(strict_types=1);

namespace App\Docfs;

use App\Validating\Attribute\Book;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\MakerInterface;
use Switon\Core\Strings;
use Switon\Validating\ValidatorInterface;

use function pathinfo;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtok;
use function substr;
use function trim;

/**
 * Book blob store under `assets/{uuid`.{ext}} (Git commit on put/rm).
 *
 * HTTP upload uses this; MCP does not carry asset bytes.
 */
final class AssetStore
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected CredentialInterface $credential;
    #[Autowired] protected Git $git;
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected ValidatorInterface $validator;

    /**
     * @return array{file: string, original: string, size: int, mtime: int, sha: string}
     */
    public function put(string $book, string $originalName, string $raw, string $gitMessage): array
    {
        $book = $this->requireBook($book, CredentialInterface::PERM_WRITE);
        $ext = $this->extensionFromOriginalName($originalName);
        $stored = Strings::uuid() . '.' . $ext;
        $rel = 'assets/' . $stored;
        $message = $this->normalizeGitMessage($gitMessage);

        $dir = '@books/' . $book . '/assets';
        if (!$this->filesystem->isDir($dir)) {
            $this->filesystem->mkdir($dir, 0775);
        }

        $file = $dir . '/' . $stored;
        if ($this->filesystem->exists($file)) {
            RuntimeException::raise('Asset id collision, retry put');
        }

        $this->filesystem->write($file, $raw);
        $this->commit($book, $message);

        $entry = $this->fileEntry($book, $rel, $raw);
        $entry['original'] = $originalName;

        return $entry;
    }

    /**
     * @return array{file: string, size: int, mtime: int, sha: string, content: string}
     */
    public function get(string $book, string $storedName): array
    {
        $book = $this->requireBook($book, CredentialInterface::PERM_READ);
        $rel = $this->assetRelFromStoredName($storedName);
        $path = '@books/' . $book . '/' . $rel;
        if (!$this->filesystem->exists($path)) {
            FileNotFoundException::raise('not found');
        }

        $raw = $this->filesystem->read($path);
        $entry = $this->fileEntry($book, $rel, $raw);
        $entry['content'] = $raw;

        return $entry;
    }

    /**
     * @return array{file: string, deleted: true}
     */
    public function rm(string $book, string $storedName, ?string $sha, string $gitMessage): array
    {
        $book = $this->requireBook($book, CredentialInterface::PERM_WRITE);
        $rel = $this->assetRelFromStoredName($storedName);
        $path = '@books/' . $book . '/' . $rel;
        if (!$this->filesystem->exists($path)) {
            FileNotFoundException::raise('not found');
        }

        $current = $this->filesystem->read($path);
        $currentSha = $this->git->hashObject($current);
        if ($sha === null || $sha === '') {
            InvalidArgumentException::raise('sha required when deleting an asset');
        }
        if (!$this->git->shaMatches($currentSha, $sha)) {
            ShaMismatchException::mismatch($rel, $this->git->abbrev($currentSha));
        }

        $this->filesystem->delete($path);
        $this->commit($book, $this->normalizeGitMessage($gitMessage));

        return [
            'file' => $rel,
            'deleted' => true,
        ];
    }

    public function normalizeGitMessage(string $raw): string
    {
        $line = trim(strtok(str_replace(["\r\n", "\r"], "\n", $raw), "\n") ?: '');
        if ($line === '') {
            InvalidArgumentException::raise('git_message required');
        }
        if (strlen($line) > 100) {
            InvalidArgumentException::raise('git_message too long (max 100)');
        }

        return $line;
    }

    private function requireBook(string $book, string $perm): string
    {
        $validated = $this->validator->validate(['book' => $book], [
            'book' => [$this->maker->make(Book::class, ['perm' => $perm])],
        ]);

        return $validated['book'];
    }

    private function commit(string $book, string $message): void
    {
        $payload = $this->credential->payload();
        $this->git->commit('@books/' . $book, $message, $payload['name'], $payload['email']);
    }

    /** @return array{file: string, size: int, mtime: int, sha: string} */
    private function fileEntry(string $book, string $rel, string $raw): array
    {
        $path = '@books/' . $book . '/' . $rel;

        return [
            'file' => $rel,
            'size' => strlen($raw),
            'mtime' => $this->filesystem->mtime($path),
            'sha' => $this->git->abbrev($this->git->hashObject($raw)),
        ];
    }

    private function extensionFromOriginalName(string $name): string
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            InvalidArgumentException::raise('Asset name must be a bare filename (no path)');
        }
        if (!Strings::isSlug($name)) {
            InvalidArgumentException::raise('Invalid asset name: {name}', ['name' => $name]);
        }
        if (!str_contains($name, '.')) {
            InvalidArgumentException::raise('Asset name must include an extension');
        }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !preg_match('/^[a-z\d]{1,16}$/', $ext)) {
            InvalidArgumentException::raise('Invalid asset extension');
        }
        if ($ext === 'md') {
            InvalidArgumentException::raise('Asset must not be .md (use write/read for Markdown)');
        }

        return $ext;
    }

    private function assetRelFromStoredName(string $name): string
    {
        if (str_starts_with($name, 'assets/')) {
            $name = substr($name, strlen('assets/'));
        }
        if ($name === '' || !Strings::isSlug($name) || str_ends_with($name, '.md')) {
            InvalidArgumentException::raise('Invalid stored asset name');
        }
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z\d]{1,16}$/i',
            $name
        )) {
            InvalidArgumentException::raise('Stored asset name must be {uuid}.{ext}');
        }

        return 'assets/' . $name;
    }
}
