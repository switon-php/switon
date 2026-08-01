<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassName;
use Switon\Core\ClassScannerInterface;
use Switon\Core\Naming;

/**
 * Discovers {@see ToolInterface} classes under configured globs into {@see ToolsInterface}.
 *
 * Default: <code>@app/Mcp/Tool/*Tool.php</code>. Name = snake(short class without <code>Tool</code>).
 *
 * @see \Switon\Mcp\ToolDiscoveryInterface
 */
class ToolDiscovery implements ToolDiscoveryInterface
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected ClassScannerInterface $classScanner;
    #[Autowired] protected ToolsInterface $tools;

    /**
     * Glob → FQCN template. Empty array disables scan. Missing dirs / no matches are a no-op.
     *
     * @var array<string, string>
     */
    #[Autowired] protected array $files = [
        '@app/Mcp/Tool/*Tool.php' => 'App\\Mcp\\Tool\\*Tool',
    ];

    public function discover(): void
    {
        if ($this->files === []) {
            return;
        }

        foreach ($this->classScanner->scan($this->files, null, ToolInterface::class) as $className) {
            $rClass = new ReflectionClass($className);
            if ($rClass->isAbstract() || $rClass->isInterface()) {
                continue;
            }

            $name = Naming::snake(ClassName::short($className, 'Tool'));
            if ($name === '' || $this->tools->has($name)) {
                continue;
            }

            /** @var ToolInterface $tool */
            $tool = $this->container->get($className);
            $this->tools->add($name, $tool);
        }
    }
}
