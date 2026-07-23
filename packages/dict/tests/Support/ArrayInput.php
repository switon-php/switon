<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Support;

use Switon\Core\InputInterface;

use function array_key_exists;

/**
 * Named input bag for action binding tests.
 */
final class ArrayInput implements InputInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string|int $name, mixed $default = null): mixed
    {
        return $this->values[$name] ?? $default;
    }

    public function has(string|int $name): bool
    {
        return array_key_exists($name, $this->values);
    }
}
