<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Throwable;

use function is_string;
use function rtrim;
use function str_ends_with;
use function str_starts_with;

/**
 * Default {@see ProfileDiscoveryInterface}: collect roots, then load each markdown file.
 *
 * Guidance: package agent roots come first, then {@see $paths}. Later roots replace the same id.
 */
class ProfileDiscovery implements ProfileDiscoveryInterface
{
    #[Autowired] protected ProfilesInterface $profiles;
    #[Autowired] protected SegmentsInterface $segments;
    #[Autowired] protected ComposerExtraInterface $composerExtra;
    #[Autowired] protected PathAliasInterface $pathAlias;
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * Flat profile directories (later overrides same id). Missing dirs are skipped.
     *
     * @var list<string>
     */
    #[Autowired] protected array $paths = [
        '@agency/agents',
        '@resources/agents',
        '~/.switon/agents',
    ];

    public function discover(): void
    {
        foreach ($this->roots() as $alias) {
            $dir = $this->resolve($alias);
            if ($dir === '') {
                continue;
            }

            foreach ($this->markdownFiles($dir) as $file) {
                $profile = $this->profiles->load($file);
                if ($profile !== null) {
                    $this->profiles->set($profile);
                }
            }

            $this->loadSegments($dir . '/segments');
        }
    }

    /**
     * Package agent roots first, then {@see $paths}. Later roots replace the same id.
     *
     * @return list<string>
     */
    protected function roots(): array
    {
        $extra = [];
        foreach ($this->composerExtra->collect('switon.agents') as $path) {
            if (is_string($path) && $path !== '') {
                $extra[] = $path;
            }
        }

        return [...$extra, ...$this->paths];
    }

    /** Publish every `<root>/segments/*.md` row; later roots replace the same id. */
    protected function loadSegments(string $dir): void
    {
        foreach ($this->filesystem->glob($dir . '/*.md') as $file) {
            $segment = $this->segments->load($file);
            if ($segment !== null) {
                $this->segments->set($segment);
            }
        }
    }

    protected function resolve(string $alias): string
    {
        if (!is_string($alias) || $alias === '') {
            return '';
        }

        try {
            $dir = rtrim($this->pathAlias->resolve($alias), '/');
        } catch (Throwable) {
            return '';
        }

        return $this->filesystem->isDir($dir) ? $dir : '';
    }

    /**
     * Recursive markdown discovery under `$dir`.
     * Skips directories named `segments` and directories whose names start with `_`.
     *
     * @return list<string>
     */
    protected function markdownFiles(string $dir): array
    {
        if (!$this->filesystem->isDir($dir)) {
            return [];
        }

        $files = [];
        foreach ($this->filesystem->list($dir) as $entry) {
            $path = $dir . '/' . $entry;
            if ($this->filesystem->isDir($path)) {
                if ($entry === 'segments' || str_starts_with($entry, '_')) {
                    continue;
                }
                foreach ($this->markdownFiles($path) as $file) {
                    $files[] = $file;
                }
                continue;
            }
            if (str_ends_with($entry, '.md') && $this->filesystem->isFile($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
