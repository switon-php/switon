<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Switon\Beacon\ClassIndexInterface;
use Switon\Beacon\Exception\ClassIndexNotFoundException;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use ReflectionClass;

use function array_filter;
use function array_slice;
use function basename;
use function explode;
use function implode;
use function str_ends_with;
use function str_replace;
use function strrpos;
use function substr;

/**
 * @see \Switon\Beacon\Command\ClassCommand
 */
class PathCommand
{
    #[Autowired] protected ClassIndexInterface $classIndex;
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * Map file path to FQCN(s) in that file; output JSON path and fqcns
     */
    #[Hidden, Tool('Map file path to FQCN(s) in that file. Match by last 2 path segments; Returns JSON: path, fqcns[].')]
    public function fqcnAction(string $path): int
    {
        $path = trim($path);
        $suffix = $this->pathSuffixAtMostTwo($path);
        if ($suffix === '') {
            $this->console->writeLn(Json::stringify(['path' => $path, 'fqcns' => []]));
            return 0;
        }

        try {
            $all = $this->classIndex->all();
        } catch (ClassIndexNotFoundException $e) {
            $this->console->writeLn(Json::stringify(['error' => 'class index unavailable', 'message' => $e->getMessage()]));
            return 1;
        }

        $expectShort = null;
        if (str_ends_with(str_replace('\\', '/', $path), '.php')) {
            $expectShort = basename($path, '.php');
        }

        $fqcns = [];
        foreach ($all as $fqcn) {
            if ($expectShort !== null && $this->shortName($fqcn) !== $expectShort) {
                continue;
            }
            $file = $this->getClassFile($fqcn);
            if ($file !== null && str_ends_with($file, $suffix)) {
                $fqcns[] = $fqcn;
            }
        }

        $this->console->writeLn(Json::stringify(['path' => $path, 'fqcns' => $fqcns]));
        return 0;
    }

    protected function getClassFile(string $fqcn): ?string
    {
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            return null;
        }
        $file = (new ReflectionClass($fqcn))->getFileName();
        if ($file === false) {
            return null;
        }
        return str_replace('\\', '/', $this->filesystem->realpath($file) ?? $file);
    }

    protected function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }

    /** Path with at most 2 segments; all matching fqcns are returned, AI picks. */
    protected function pathSuffixAtMostTwo(string $path): string
    {
        $norm = str_replace('\\', '/', trim($path));
        $parts = array_filter(explode('/', $norm));
        $take = array_slice($parts, -2);
        return implode('/', $take);
    }
}
