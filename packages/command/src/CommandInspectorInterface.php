<?php

declare(strict_types=1);

namespace Switon\Command;

use ReflectionMethod;

/**
 * Contract for reading metadata from command classes.
 *
 * @see \Switon\Command\CommandInspector
 */
interface CommandInspectorInterface
{
    /**
     * Returns whether a command class is hidden from default list/help output.
     */
    public function isHiddenCommand(string $commandClassName): bool;

    /**
     * Returns the first human-readable summary line for a command class.
     */
    public function getCommandDescription(string $class): string;

    /**
     * @return array<string, string>
     */
    public function getActions(string $commandClassName, bool $includeHidden = false): array;

    /**
     * Returns the first human-readable summary line for one action method.
     */
    public function getMethodDescription(ReflectionMethod $rMethod): string;

    /**
     * Returns whether one action method has any CLI-bound options.
     */
    public function hasOptions(ReflectionMethod $rMethod): bool;

    /**
     * @return array<string, array{description: string, default: mixed, type: string}>
     */
    public function getOptions(ReflectionMethod $rMethod): array;

    /**
     * Returns AI-oriented doc text from the Tool attribute, if present.
     */
    public function getActionAiDoc(string $commandClassName, string $actionName): ?string;

    /**
     * @return array<string, array{description: string, default: mixed, type: string}>
     */
    public function getActionParameters(string $commandClassName, string $actionName): array;
}
