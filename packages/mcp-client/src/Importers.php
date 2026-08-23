<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\FilesystemInterface;
use Switon\McpClient\Importer\ImporterInterface;

use function is_a;

/**
 * Default {@see ImportersInterface}: path → class string; {@see import()} matches on resolved path.
 */
class Importers implements ImportersInterface
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected FilesystemInterface $filesystem;

    /** @var array<string, class-string<ImporterInterface>> */
    protected array $importers = [];

    public function all(): array
    {
        return $this->importers;
    }

    public function set(string $path, string $class): void
    {
        if ($path === '' || $class === '') {
            Exception::raise('MCP importer path and class must be non-empty.');
        }
        if (!is_a($class, ImporterInterface::class, true)) {
            Exception::raise('MCP importer "{class}" must implement ImporterInterface.', ['class' => $class]);
        }
        $this->importers[$path] = $class;
    }

    public function has(string $path): bool
    {
        if (isset($this->importers[$path])) {
            return true;
        }
        $resolved = $this->filesystem->resolve($path);
        foreach ($this->importers as $registered => $_) {
            if ($this->filesystem->resolve($registered) === $resolved) {
                return true;
            }
        }

        return false;
    }

    public function import(string $path): array
    {
        $class = $this->importers[$path] ?? null;
        if ($class === null) {
            $resolved = $this->filesystem->resolve($path);
            foreach ($this->importers as $registered => $candidate) {
                if ($this->filesystem->resolve($registered) === $resolved) {
                    $class = $candidate;
                    break;
                }
            }
        }
        if ($class === null) {
            Exception::raise('No MCP importer for "{path}".', ['path' => $path]);
        }

        /** @var ImporterInterface $importer */
        $importer = $this->container->get($class);

        return $importer->import($path);
    }
}
