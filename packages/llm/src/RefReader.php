<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\Part\MediaPart;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\FilesystemInterface;
use Switon\Core\Path;
use Switon\Core\PathAliasInterface;

use function in_array;
use function max;
use function rtrim;
use function str_contains;
use function strstr;
use function strtolower;
use function trim;

/**
 * Default {@see RefReaderInterface}: scheme allowlist passthrough; relative path under datadir →
 * bytes; absolute path → bytes.
 *
 * Guidance: `$maxBytes` caps local reads; empty files unload.
 */
class RefReader implements RefReaderInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected PathAliasInterface $pathAlias;

    /** @var list<string> Schemes allowed after URL shape (`://`). */
    #[Autowired] protected array $schemes = ['https', 'http', 'mm_file'];
    /** Max local file bytes to load into memory (default 128 MiB). */
    #[Autowired] protected int $maxBytes = 134_217_728;

    public function resolve(MediaPart $part, string $datadir = ''): ?string
    {
        $ref = $part->ref;

        if (str_contains($ref, '://')) {
            $scheme = strtolower(strstr($ref, '://', true) ?: '');

            return in_array($scheme, $this->schemes, true) ? $ref : null;
        }

        return $this->readPath($ref, $datadir);
    }

    protected function readPath(string $ref, string $datadir): ?string
    {
        $ref = Path::normalize(Path::tilde($ref));

        if (Path::isRelative($ref)) {
            return $this->readUnderDatadir($ref, $datadir);
        }

        if (Path::isAbsolute($ref)) {
            return $this->readAbsolute($ref);
        }

        // Neither safe-relative nor absolute (`..`/`.` left over): no CWD fallback — one
        // process serves many projects, so CWD never names a readable root.
        return null;
    }

    protected function readUnderDatadir(string $ref, string $datadir): ?string
    {
        $datadir = trim($datadir);
        if ($datadir === '') {
            return null;
        }

        $root = $this->pathAlias->resolve(rtrim($datadir, '/'));
        $realFile = Path::resolve($root, $ref);
        if ($realFile === null || !$this->filesystem->isFile($realFile)) {
            return null;
        }

        return $this->readFileBytes($realFile);
    }

    protected function readAbsolute(string $ref): ?string
    {
        $realFile = $this->filesystem->realpath($ref);
        if ($realFile === null || !$this->filesystem->isFile($realFile)) {
            return null;
        }

        return $this->readFileBytes($realFile);
    }

    protected function readFileBytes(string $realFile): ?string
    {
        $size = $this->filesystem->size($realFile);
        if ($size === null || $size <= 0 || $size > max(1, $this->maxBytes)) {
            return null;
        }

        try {
            return $this->filesystem->read($realFile);
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
