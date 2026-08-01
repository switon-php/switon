<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Mcp\Exception\InvalidParamsException;

use function array_key_exists;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;

/**
 * Default MCP argument binder: defaults → required → string/integer coerce.
 *
 * @see ArgumentBinderInterface
 */
class ArgumentBinder implements ArgumentBinderInterface
{
    public function bind(ToolSchema $schema, array $arguments): array
    {
        $input = $schema->definition['inputSchema'] ?? [];
        $properties = is_array($input['properties'] ?? null) ? $input['properties'] : [];
        $required = is_array($input['required'] ?? null) ? $input['required'] : [];

        foreach ($properties as $name => $property) {
            if (!array_key_exists($name, $arguments) && is_array($property) && array_key_exists('default', $property)) {
                $arguments[$name] = $property['default'];
            }
        }

        foreach ($required as $name) {
            if (!isset($arguments[$name])) {
                InvalidParamsException::raise('{name} is required', ['name' => $name]);
            }
        }

        foreach ($properties as $name => $property) {
            if (!array_key_exists($name, $arguments) || $arguments[$name] === null || !is_array($property)) {
                continue;
            }
            $type = $property['type'] ?? null;
            $value = $arguments[$name];
            if ($type === 'string') {
                $arguments[$name] = is_string($value)
                    ? $value
                    : ((is_int($value) || is_float($value))
                        ? (string) $value
                        : InvalidParamsException::raise('{name} must be a string', ['name' => $name]));
            } elseif ($type === 'integer') {
                $arguments[$name] = is_int($value)
                    ? $value
                    : ((is_string($value) && preg_match('#^[+\-]?\d+$#', $value) === 1)
                        ? (int) $value
                        : InvalidParamsException::raise('{name} must be an integer', ['name' => $name]));
            }
        }

        return $arguments;
    }
}
