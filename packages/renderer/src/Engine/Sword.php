<?php

declare(strict_types=1);

namespace Switon\Rendering\Engine;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Engine\Sword\Compiler;
use Switon\Rendering\EngineInterface;
use Switon\Rendering\Frames;

use function extract;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Compiled renderer for `.sword` templates.
 *
 * Use when templates should be compiled to PHP and cached under `@runtime/sword`.
 *
 * Road-signs:
 * - getCompiledFile() for source => compiled path resolution
 * - render() for compiled template execution
 */
class Sword implements EngineInterface
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected Compiler $swordCompiler;
    #[Autowired] protected PathAliasInterface $pathAlias;
    #[Autowired] protected FilesystemInterface $filesystem;

    /** @var array<string, string> */
    protected array $compiled = [];

    /**
     * Resolves and refreshes the compiled PHP file for one `.sword` source template.
     */
    public function getCompiledFile(string $source): string
    {
        $root = $this->pathAlias->resolve('@root');
        if (str_starts_with($source, $root)) {
            $compiled = '@runtime/sword' . substr($source, strlen($root));
        } else {
            $compiled = "@runtime/sword/$source";
            if (DIRECTORY_SEPARATOR === '\\') {
                $compiled = str_replace(':', '_', $compiled);
            }
        }

        $compiled = $this->pathAlias->resolve($compiled);

        if ($this->app->isDebug()
            || !$this->filesystem->exists($compiled)
            || ($this->filesystem->mtime($source) ?? 0) > ($this->filesystem->mtime($compiled) ?? 0)
        ) {
            $this->swordCompiler->compileFile($source, $compiled);
        }

        return $compiled;
    }

    /**
     * Executes the compiled PHP template for one `.sword` source file.
     */
    public function render(string $file, Frames $frames): void
    {
        $__frames = $frames;
        extract($frames->all(), EXTR_SKIP);

        $this->compiled[$file] ??= $this->getCompiledFile($file);

        require $this->compiled[$file];
    }
}
