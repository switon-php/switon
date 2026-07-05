<?php

declare(strict_types=1);

namespace Switon\Command;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attributes;
use Switon\Core\Naming;
use Switon\Core\Reflections;

use function class_exists;
use function count;
use function get_class_methods;
use function html_entity_decode;
use function implode;
use function ksort;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_starts_with;
use function strip_tags;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Extracts command, action, and option metadata from reflection and PHPDoc.
 *
 * Guidance: Treat this as a read-only metadata layer; execution stays in CLI handler code.
 *
 * @see \Switon\Command\CommandInspectorInterface
 * @see \Switon\Command\Attribute\Tool
 * @see \Switon\Command\Attribute\Hidden
 */
class CommandInspector implements CommandInspectorInterface
{
    public function isHiddenCommand(string $commandClassName): bool
    {
        if (!class_exists($commandClassName)) {
            return false;
        }

        $reflection = new ReflectionClass($commandClassName);
        return Attributes::has($reflection, Hidden::class);
    }

    public function getCommandDescription(string $class): string
    {
        if (!class_exists($class)) {
            return '';
        }

        $rClass = new ReflectionClass($class);

        if (($comment = $rClass->getDocComment()) === false) {
            return '';
        }

        $lines = preg_split('#[\r\n]+#', $comment, 3);
        if (($description = $lines[1] ?? null) === null) {
            return '';
        }

        $description = trim($description, " \t\n\r\0\x0B*");
        $description = $this->sanitizeDocText($description);
        if (str_starts_with($description, 'Class') || str_starts_with($description, '@')) {
            return '';
        }

        return $description;
    }

    public function getActions(string $commandClassName, bool $includeHidden = false): array
    {
        if (!class_exists($commandClassName)) {
            return [];
        }

        $rClass = new ReflectionClass($commandClassName);

        $actions = [];
        foreach (get_class_methods($commandClassName) as $method) {
            if (preg_match('#^(.*)Action$#', $method, $match) !== 1) {
                continue;
            }

            $rMethod = $rClass->getMethod($method);
            if (!$includeHidden && Attributes::has($rMethod, Hidden::class)) {
                continue;
            }

            $action = $match[1];
            $actions[$action] = $this->getMethodDescription($rMethod);
        }

        ksort($actions);

        return $actions;
    }

    public function getMethodDescription(ReflectionMethod $rMethod): string
    {
        foreach (preg_split('#[\r\n]+#', $rMethod->getDocComment() ?: '') ?: [] as $line) {
            $line = trim($line, "\t /*\r\n");
            if (!$line) {
                continue;
            }
            if (!str_starts_with($line, '@')) {
                return $this->sanitizeDocText($line);
            }
        }
        $name = $rMethod->getName();
        if (preg_match('#^(.*)Action$#', $name, $m) === 1) {
            return Naming::kebab($m[1]);
        }
        return Naming::kebab($name);
    }

    public function hasOptions(ReflectionMethod $rMethod): bool
    {
        foreach ($rMethod->getParameters() as $rParameter) {
            if (self::isOptionType($rParameter->getType())) {
                return true;
            }
        }
        return false;
    }

    public function getOptions(ReflectionMethod $rMethod): array
    {
        $options = [];
        $defaultValues = [];

        foreach ($rMethod->getParameters() as $rParameter) {
            $name = $rParameter->getName();

            if ($rParameter->isDefaultValueAvailable()) {
                $defaultValues[$name] = $rParameter->getDefaultValue();
            }

            $rType = $rParameter->getType();
            if (self::isOptionType($rType)) {
                $options[$name] = [
                    'description' => '',
                    'default' => $defaultValues[$name] ?? null,
                    'type' => self::optionTypeName($rType),
                ];
            }
        }

        foreach (preg_split('#[\r\n]+#', $rMethod->getDocComment() ?: '') ?: [] as $line) {
            $line = trim($line, "\t /*\r\n");
            if (!str_contains($line, '@param')) {
                continue;
            }

            $parts = preg_split('#\s+#', $line, 4);
            if ($parts === false || count($parts) < 3 || $parts[0] !== '@param') {
                continue;
            }

            $name = substr($parts[2], 1);
            if (!isset($options[$name])) {
                continue;
            }

            $options[$name]['description'] = isset($parts[3]) ? $this->sanitizeDocText($parts[3]) : '';
        }

        return $options;
    }

    public function getActionAiDoc(string $commandClassName, string $actionName): ?string
    {
        if (!class_exists($commandClassName)) {
            return null;
        }

        $methodName = $actionName . 'Action';
        try {
            $rClass = new ReflectionClass($commandClassName);
            if (!$rClass->hasMethod($methodName)) {
                return null;
            }
            $rMethod = $rClass->getMethod($methodName);
        } catch (ReflectionException) {
            return null;
        }

        $instance = Attributes::first($rMethod, Tool::class);
        if ($instance === null) {
            return null;
        }
        $s = trim($instance->doc);
        return $s !== '' ? $s : null;
    }

    public function getActionParameters(string $commandClassName, string $actionName): array
    {
        if (!class_exists($commandClassName)) {
            return [];
        }

        $methodName = $actionName . 'Action';
        try {
            $rClass = new ReflectionClass($commandClassName);
            if (!$rClass->hasMethod($methodName)) {
                return [];
            }
            $rMethod = $rClass->getMethod($methodName);
        } catch (ReflectionException) {
            return [];
        }

        return $this->getOptions($rMethod);
    }

    protected function sanitizeDocText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);
        return rtrim($text, ".。 \t\n\r\0\x0B");
    }

    /**
     * Whether a parameter type is CLI-bound (untyped / builtin / builtin-union), not a DI class type.
     *
     * Shared with CLI Handler and help rendering so option detection stays consistent.
     */
    public static function isOptionType(?ReflectionType $rType): bool
    {
        if ($rType === null) {
            return true;
        }

        if (Reflections::getTypeNames($rType) === []) {
            return false;
        }

        if ($rType instanceof ReflectionNamedType) {
            return $rType->isBuiltin();
        }

        if ($rType instanceof ReflectionUnionType) {
            foreach ($rType->getTypes() as $member) {
                if (!$member instanceof ReflectionNamedType || !$member->isBuiltin()) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** Declared type label for help/metadata (`mixed` when undeclared). */
    public static function optionTypeName(?ReflectionType $rType): string
    {
        if ($rType === null) {
            return 'mixed';
        }

        $names = Reflections::getTypeNames($rType);

        return $names === [] ? 'mixed' : implode('|', $names);
    }
}
