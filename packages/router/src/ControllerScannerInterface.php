<?php

declare(strict_types=1);

namespace Switon\Routing;

/**
 * Contract for discovering controller classes from configured sources.
 *
 * Guidance: Use this for controller class discovery only; route and action metadata are resolved later.
 *
 * Road-signs:
 * - service contract for controller discovery
 * - default implementation: ControllerScanner
 * - consumers iterate getControllers()
 * - RequestMapping is the required marker attribute
 *
 * @see \Switon\Routing\ControllerScanner
 * @see \Switon\Routing\ControllerScannerInterface::getControllers()
 * @see \Switon\Routing\Attribute\RequestMapping
 * @see \Switon\Core\ClassScannerInterface
 */
interface ControllerScannerInterface
{
    /**
     * Returns controller class names marked with `#[RequestMapping]`.
     *
     * @return list<class-string>
     */
    public function getControllers(): array;
}
