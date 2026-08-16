<?php

declare(strict_types=1);

namespace Switon\Tooling;

use SplFileInfo;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Path;

use function fclose;
use function fopen;
use function fread;
use function fseek;
use function ltrim;
use function max;
use function mb_strcut;
use function min;
use function preg_replace;
use function random_bytes;
use function rtrim;
use function str_starts_with;
use function strlen;
use function trim;

use const SEEK_SET;

/**
 * Default {@see SpillerInterface}: oversized body under a datadir → inline text or recall preview.
 */
class Spiller implements SpillerInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * Line appended once when a collector hits a byte ceiling — whole-MiB caps read as
     * `32 MiB`, others as a byte count.
     */
    public static function ceilingExceeded(int $ceilingBytes): string
    {
        $bytes = max(1, $ceilingBytes);
        $mib = 1024 * 1024;
        $size = $bytes % $mib === 0 ? intdiv($bytes, $mib) . ' MiB' : $bytes . ' bytes';

        return "\n[output exceeded {$size} ceiling; remaining bytes discarded]";
    }

    /**
     * In-memory truncation for a thread with no datadir. Nothing is written and there is no recall pointer.
     */
    public static function clipped(string $body, int $budgetKiB, int $keep): string
    {
        $budget = max(1, $budgetKiB) * 1024;
        $size = strlen($body);
        if ($size <= $budget) {
            return $body;
        }

        $edge = max(1, min(max(1, $keep), intdiv($size - 1, 2)));
        $head = mb_strcut($body, 0, $edge);
        $tail = mb_strcut($body, $size - $edge);
        $omitted = max(0, $size - strlen($head) - strlen($tail));

        return $head . "\n...[truncated: {$omitted} bytes]...\n" . $tail;
    }

    /** Model-wire preview: head, omitted count, recall pointer, tail. */
    protected function truncated(string $head, string $tail, int $omitted, string $recall): string
    {
        if (!str_starts_with($recall, '@thread/')) {
            $recall = '@thread/' . ltrim($recall, '/');
        }

        return $head . "\n...[truncated: {$omitted} bytes; recall: {$recall}]...\n" . $tail;
    }

    public function spill(
        string             $datadir,
        string             $file,
        string|SplFileInfo $data,
        int                $budgetKiB,
        int                $keep,
    ): string {
        $datadir = rtrim($datadir, '/');
        if ($datadir === '') {
            Exception::raise('Spill datadir must not be empty.');
        }

        $file = Path::normalize($file);
        if (!Path::isRelative($file)) {
            Exception::raise('Path "{path}" must be a relative path.', ['path' => $file]);
        }

        $absolute = $datadir . '/' . $file;
        $budget = max(1, $budgetKiB) * 1024;
        $keep = max(1, $keep);

        $source = $data instanceof SplFileInfo ? $data->getPathname() : null;

        // Target already holds the full body (e.g. compact re-preview): never overwrite.
        // Applies to both inline and file sources, so a spill can never clobber an earlier body.
        if ($this->filesystem->exists($absolute) && !$this->isSamePath($source, $absolute)) {
            if ($source !== null) {
                $this->filesystem->delete($source);
            }

            return $this->spillFile($absolute, $absolute, $file, $budget, $keep);
        }

        if ($source !== null) {
            return $this->spillFile($source, $absolute, $file, $budget, $keep);
        }

        $size = strlen($data);
        if ($size <= $budget) {
            return $data;
        }

        $this->filesystem->write($absolute, $data);

        return $this->previewOf($data, $size, $keep, $file);
    }

    /** Head and tail on character boundaries. */
    protected function previewOf(string $body, int $size, int $keep, string $recall): string
    {
        [$headLen, $tailLen] = $this->edges($size, $keep);
        $head = mb_strcut($body, 0, $headLen);
        $tail = mb_strcut($body, $size - $tailLen);

        return $this->truncated($head, $tail, max(0, $size - strlen($head) - strlen($tail)), $recall);
    }

    /**
     * Head and tail byte lengths. The budget decides whether to spill; each edge is `min(keep, half)`.
     *
     * @return array{0: int<1, max>, 1: int<1, max>}
     */
    protected function edges(int $size, int $keep): array
    {
        $edge = max(1, min($keep, intdiv($size - 1, 2)));

        return [$edge, $edge];
    }

    /** Whether `$source` and `$absolute` name the same file (source may be null for inline spills). */
    protected function isSamePath(?string $source, string $absolute): bool
    {
        if ($source === null) {
            return false;
        }

        return $this->filesystem->resolve($source) === $this->filesystem->resolve($absolute);
    }

    protected function spillFile(
        string $path,
        string $absolute,
        string $recall,
        int    $budget,
        int    $keep,
    ): string {
        if ($path === '' || !$this->filesystem->exists($path)) {
            Exception::raise('Spill file "{file}" is missing.', ['file' => $path]);
        }

        $same = $this->isSamePath($path, $absolute);

        $size = $this->filesystem->size($path) ?? 0;
        if ($size <= $budget) {
            $body = $this->filesystem->read($path);
            if (!$same) {
                $this->filesystem->delete($path);
            }

            return $body;
        }

        if (!$same) {
            $this->filesystem->move($path, $absolute);
        }

        return $this->previewFile($absolute, $recall, $size, $keep);
    }

    /** Same preview as {@see previewOf()} without loading the whole file. */
    protected function previewFile(string $absolute, string $recall, int $size, int $keep): string
    {
        [$headLen, $tailLen] = $this->edges($size, $keep);
        $head = mb_strcut($this->readSlice($absolute, 0, $headLen + 4), 0, $headLen);
        $tail = mb_strcut($this->readSlice($absolute, $size - $tailLen - 3, $tailLen + 3), 3);

        return $this->truncated($head, $tail, max(0, $size - strlen($head) - strlen($tail)), $recall);
    }

    /**
     * Read one slice. {@see FilesystemInterface} has no seek, so this opens the resolved path.
     *
     * @param int<1, max> $length `fread` rejects 0.
     */
    protected function readSlice(string $path, int $offset, int $length): string
    {
        $absolute = $this->filesystem->resolve($path);
        // Silence the native stream warning; the raised exception carries the diagnostic.
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            Exception::raise('Spill preview cannot open "{file}".', ['file' => $absolute]);
        }
        try {
            fseek($handle, $offset, SEEK_SET);

            return (string)fread($handle, $length);
        } finally {
            fclose($handle);
        }
    }
}
