<?php

declare(strict_types=1);

namespace Switon\Tooling;

use stdClass;
use Switon\Ai\ToolSchema;
use Switon\Core\Json;
use Switon\Tooling\Exception\ArgumentBindException;

use function array_is_list;
use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function str_starts_with;

/**
 * Binds one tool schema: defaults, then required, type, and value constraints.
 *
 * Guidance: one field path fails once and its children are skipped; an unknown keyword is skipped, not rejected.
 *
 * @see ArgumentBinderInterface
 */
class ArgumentBinder implements ArgumentBinderInterface
{
    /** @var array<string, string> */
    protected array $errors = [];

    public function bind(ToolSchema $schema, array $arguments): array
    {
        $input = $schema->definition['inputSchema'] ?? [];
        if (!is_array($input)) {
            return $arguments;
        }

        $this->errors = [];
        $this->applyDefaults($input, $arguments);
        $this->checkNode($input, $arguments, '');
        if ($this->errors !== []) {
            throw ArgumentBindException::failed($this->errors);
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed> $instance
     */
    protected function applyDefaults(array $schema, array &$instance): void
    {
        foreach ($this->properties($schema) as $name => $property) {
            // A missing or explicit-null argument takes the schema default.
            if (($instance[$name] ?? null) === null && array_key_exists('default', $property)) {
                $instance[$name] = $property['default'];
            }

            if (isset($instance[$name]) && is_array($instance[$name])) {
                $this->applyDefaults($property, $instance[$name]);
            }
        }

        $items = $this->itemsSchema($schema);
        if ($items === null || !array_is_list($instance)) {
            return;
        }

        foreach ($instance as &$item) {
            if (is_array($item)) {
                $this->applyDefaults($items, $item);
            }
        }
        unset($item);
    }

    /**
     * @param array<string, mixed> $schema
     */
    protected function checkNode(array $schema, mixed $instance, string $path): void
    {
        if ($path !== '' && array_key_exists($path, $this->errors)) {
            return;
        }

        if ($path !== '' && $instance === null) {
            return;
        }

        if ($path !== '' && !$this->typeSatisfied($instance, $schema['type'] ?? null)) {
            $this->fail($path, '{field} type invalid', ['field' => $path]);

            return;
        }

        if ($path !== '') {
            $this->rejectConstraints($path, $instance, $schema);
            if (array_key_exists($path, $this->errors)) {
                return;
            }
        }

        if (!is_array($instance)) {
            return;
        }

        $this->rejectClosed($schema, $instance, $path);
        $this->rejectProperties($schema, $instance, $path);
        $this->rejectItems($schema, $instance, $path);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed> $instance
     */
    protected function rejectProperties(array $schema, array $instance, string $path): void
    {
        $properties = $this->properties($schema);
        $required = [];
        $listed = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($listed as $name) {
            if (is_string($name) && $name !== '' && !str_starts_with($name, '.')) {
                $required[$name] = true;
            }
        }

        $names = array_keys($properties);
        foreach ($required as $name => $_) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        foreach ($names as $name) {
            $child = $this->childPath($path, $name);
            if (array_key_exists($child, $this->errors)) {
                continue;
            }

            $property = $properties[$name] ?? [];
            $present = array_key_exists($name, $instance);
            if (!$present || ($instance[$name] === null && !$this->allowsNull($property))) {
                if (isset($required[$name])) {
                    $this->fail($child, '{field} required', ['field' => $child]);
                }
                continue;
            }

            if ($instance[$name] === null) {
                continue;
            }

            $this->checkNode($property, $instance[$name], $child);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed> $instance
     */
    protected function rejectItems(array $schema, array $instance, string $path): void
    {
        $items = $this->itemsSchema($schema);
        if ($items === null || !array_is_list($instance)) {
            return;
        }

        foreach ($instance as $index => $item) {
            if ($item === null) {
                continue;
            }

            $this->checkNode($items, $item, $this->childPath($path, (string)$index));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function fail(string $path, string $template, array $context): void
    {
        if ($path === '' || array_key_exists($path, $this->errors)) {
            return;
        }

        $message = $template;
        foreach ($context as $key => $value) {
            $text = Json::text($value);
            $message = str_replace('{' . $key . '}', $text, $message);
        }
        $this->errors[$path] = $message;
    }

    protected function childPath(string $path, string $name): string
    {
        return $path === '' ? $name : $path . '.' . $name;
    }

    /**
     * @param array<string, mixed> $property
     */
    protected function rejectConstraints(string $path, mixed $value, array $property): void
    {
        if (array_key_exists($path, $this->errors)) {
            return;
        }

        if (array_key_exists('const', $property) && !$this->sameJsonValue($value, $property['const'])) {
            $this->fail($path, '{field} must be {expected}', [
                'field' => $path,
                'expected' => Json::stringify($property['const']),
            ]);

            return;
        }

        if (isset($property['enum']) && is_array($property['enum']) && !$this->enumContains($property['enum'], $value)) {
            $this->fail($path, '{field} must be one of {choices}', [
                'field' => $path,
                'choices' => $property['enum'],
            ]);

            return;
        }

        $type = $property['type'] ?? null;
        $types = is_string($type) ? [$type] : (is_array($type) && array_is_list($type) ? $type : []);

        if (is_string($value) && $this->listsType($types, 'string')) {
            $length = mb_strlen($value, 'UTF-8');
            if (isset($property['minLength']) && is_int($property['minLength']) && $length < $property['minLength']) {
                $this->fail($path, '{field} shorter than {min}', [
                    'field' => $path,
                    'min' => $property['minLength'],
                ]);

                return;
            }
            if (isset($property['maxLength']) && is_int($property['maxLength']) && $length > $property['maxLength']) {
                $this->fail($path, '{field} longer than {max}', [
                    'field' => $path,
                    'max' => $property['maxLength'],
                ]);

                return;
            }
        }

        if ((is_int($value) || is_float($value)) && ($this->listsType($types, 'integer') || $this->listsType($types, 'number') || $types === [])) {
            if (isset($property['minimum']) && is_numeric($property['minimum']) && $value < $property['minimum']) {
                $this->fail($path, '{field} below {bound}', [
                    'field' => $path,
                    'bound' => $property['minimum'],
                ]);

                return;
            }
            if (isset($property['maximum']) && is_numeric($property['maximum']) && $value > $property['maximum']) {
                $this->fail($path, '{field} above {bound}', [
                    'field' => $path,
                    'bound' => $property['maximum'],
                ]);

                return;
            }
            if (isset($property['exclusiveMinimum']) && is_numeric($property['exclusiveMinimum'])
                && $value <= $property['exclusiveMinimum']) {
                $this->fail($path, '{field} must be greater than {bound}', [
                    'field' => $path,
                    'bound' => $property['exclusiveMinimum'],
                ]);

                return;
            }
            if (isset($property['exclusiveMaximum']) && is_numeric($property['exclusiveMaximum'])
                && $value >= $property['exclusiveMaximum']) {
                $this->fail($path, '{field} must be less than {bound}', [
                    'field' => $path,
                    'bound' => $property['exclusiveMaximum'],
                ]);

                return;
            }
        }

        if (is_array($value) && ($this->listsType($types, 'array') || $types === [])) {
            if (isset($property['minItems']) && is_int($property['minItems']) && count($value) < $property['minItems']) {
                $this->fail($path, '{field} has fewer than {min} items', [
                    'field' => $path,
                    'min' => $property['minItems'],
                ]);

                return;
            }
            if (isset($property['maxItems']) && is_int($property['maxItems']) && count($value) > $property['maxItems']) {
                $this->fail($path, '{field} has more than {max} items', [
                    'field' => $path,
                    'max' => $property['maxItems'],
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed> $instance
     */
    protected function rejectClosed(array $schema, array $instance, string $path): void
    {
        if (($schema['additionalProperties'] ?? null) !== false) {
            return;
        }
        if (isset($schema['patternProperties']) || isset($schema['unevaluatedProperties']) || array_is_list($instance)) {
            return;
        }

        $properties = $this->properties($schema);
        foreach ($instance as $key => $_) {
            if (is_string($key) && str_starts_with($key, '.')) {
                continue;
            }
            $name = is_string($key) ? $key : (string)$key;
            if (!array_key_exists($name, $properties)) {
                $child = $this->childPath($path, $name);
                $this->fail($child, '{field} is not allowed', ['field' => $child]);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, array<string, mixed>>
     */
    protected function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return [];
        }

        $out = [];
        foreach ($properties as $name => $property) {
            if ((!is_string($name) && !is_int($name)) || !is_array($property)) {
                continue;
            }
            $name = (string)$name;
            if ($name === '' || str_starts_with($name, '.')) {
                continue;
            }
            $out[$name] = $property;
        }

        return $out;
    }

    /**
     * A single item schema. A list of schemas (tuple / `prefixItems` style) is left unchecked.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|null
     */
    protected function itemsSchema(array $schema): ?array
    {
        $items = $schema['items'] ?? null;
        if (!is_array($items) || array_is_list($items)) {
            return null;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $property
     */
    protected function allowsNull(array $property): bool
    {
        if ($this->typeIncludesNull($property['type'] ?? null)) {
            return true;
        }
        if (array_key_exists('const', $property) && $property['const'] === null) {
            return true;
        }
        if (isset($property['enum']) && is_array($property['enum']) && in_array(null, $property['enum'], true)) {
            return true;
        }

        foreach (['oneOf', 'anyOf'] as $combiner) {
            if (!isset($property[$combiner]) || !is_array($property[$combiner])) {
                continue;
            }
            foreach ($property[$combiner] as $branch) {
                if (!is_array($branch) || $this->allowsNull($branch) || !$this->branchKnown($branch)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $branch
     */
    protected function branchKnown(array $branch): bool
    {
        if (!array_key_exists('type', $branch)) {
            return isset($branch['enum']) || array_key_exists('const', $branch);
        }

        $type = $branch['type'];
        if (is_string($type)) {
            return $this->knownType($type);
        }
        if (!is_array($type) || !array_is_list($type) || $type === []) {
            return false;
        }

        foreach ($type as $part) {
            if (!is_string($part) || !$this->knownType($part)) {
                return false;
            }
        }

        return true;
    }

    protected function typeIncludesNull(mixed $type): bool
    {
        if ($type === 'null') {
            return true;
        }

        return is_array($type) && in_array('null', $type, true);
    }

    protected function typeSatisfied(mixed $value, mixed $type): bool
    {
        if ($type === null || $type === '' || $type === []) {
            return true;
        }
        if (is_string($type)) {
            return !$this->knownType($type) || $this->argumentMatchesType($value, $type);
        }
        if (!is_array($type) || !array_is_list($type)) {
            return true;
        }

        foreach ($type as $part) {
            if (!is_string($part) || !$this->knownType($part)) {
                return true;
            }
            if ($this->argumentMatchesType($value, $part)) {
                return true;
            }
        }

        return false;
    }

    protected function knownType(string $type): bool
    {
        return match ($type) {
            'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' => true,
            default => false,
        };
    }

    /**
     * @param list<mixed> $types
     */
    protected function listsType(array $types, string $type): bool
    {
        if ($types === []) {
            return false;
        }

        return in_array($type, $types, true);
    }

    /**
     * @param array<mixed> $enum Enum values; only values are compared, keys are irrelevant.
     */
    protected function enumContains(array $enum, mixed $value): bool
    {
        foreach ($enum as $candidate) {
            if ($this->sameJsonValue($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    protected function sameJsonValue(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))
            && is_finite((float)$left) && is_finite((float)$right)
        ) {
            return (float)$left === (float)$right;
        }

        return false;
    }

    protected function argumentMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_array($value) || $value instanceof stdClass,
            'null' => $value === null,
            default => true,
        };
    }
}
