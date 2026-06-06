<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\PathAliasInterface;

use function array_slice;
use function basename;
use function count;
use function dirname;
use function get_included_files;
use function getenv;
use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Collect included PHP files for inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Core\PathAliasInterface
 */
class FilesCollector implements CollectorInterface, JsonSerializable
{
    #[Autowired] protected PathAliasInterface $pathAlias;

    public function boot(): void
    {
        // No initialization needed
    }

    public function collect(): array
    {
        $rootPath = str_replace('\\', '/', $this->pathAlias->resolve('@root'));
        $parentPath = str_replace('\\', '/', dirname($rootPath));
        $rootName = basename($rootPath);
        $homeDir = str_replace('\\', '/', getenv('HOME') ?: (getenv('USERPROFILE') ?: ''));

        $includedFiles = get_included_files();
        $limitedFiles = count($includedFiles) > 1000 ? array_slice($includedFiles, 0, 1000) : $includedFiles;

        $display = [];
        foreach ($limitedFiles as $file) {
            $normalized = str_replace('\\', '/', $file);

            if (str_starts_with($normalized, $rootPath)) {
                $display[] = $rootName . '/' . ltrim(substr($normalized, strlen($rootPath)), '/');
            } elseif (str_starts_with($normalized, $parentPath)) {
                $display[] = ltrim(substr($normalized, strlen($parentPath)), '/');
            } elseif ($homeDir && str_starts_with($normalized, $homeDir)) {
                $display[] = '~/' . ltrim(substr($normalized, strlen($homeDir)), '/');
            } else {
                $display[] = $normalized;
            }
        }

        return $display;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
