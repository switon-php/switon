<?php

declare(strict_types=1);

namespace Switon\Routing;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassScannerInterface;
use Switon\Routing\Attribute\RequestMapping;

/**
 * Discovers controller classes from configured glob paths.
 *
 * Delegates scanning to {@see ClassScannerInterface} with <code>#[RequestMapping]</code> as the class-level filter.
 *
 * Road-signs:
 * - <code>$paths</code> globs
 * - {@see ClassScannerInterface}
 * - {@see RequestMapping}
 *
 * @see \Switon\Routing\ControllerScannerInterface
 * @see \Switon\Core\ClassScannerInterface
 * @see \Switon\Routing\Attribute\RequestMapping
 */
class ControllerScanner implements ControllerScannerInterface
{
    #[Autowired] protected ClassScannerInterface $classScanner;

    /** @var array<string, string> */
    #[Autowired] protected array $paths = [
        '@app/Controller/*Controller.php' => 'App\\Controller\\*Controller',
        '@app/Areas/*/Controller/*Controller.php' => 'App\\Areas\\*\\Controller\\*Controller',
    ];

    /** @return list<class-string> */
    public function getControllers(): array
    {
        return $this->classScanner->scan($this->paths, RequestMapping::class);
    }
}
