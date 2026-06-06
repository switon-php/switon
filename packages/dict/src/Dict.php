<?php

declare(strict_types=1);

namespace Switon\Dict;

use Switon\Binding\Attribute\ResolvedBy;
use Switon\Core\ClassName;

use function explode;
use function implode;
use function ltrim;
use function str_contains;

/**
 * Dictionary action-parameter boundary.
 *
 * Use on action parameters when one input value must resolve to a valid dict item.
 * The default type code is derived from the class name unless a subclass overrides it.
 *
 * Road-signs:
 * - action param Dict
 * - DictResolver
 * - DictValue
 * - getValue / __toString
 *
 * @see \Switon\Dict\DictResolver
 * @see \Switon\Dict\Attribute\DictValue
 *
 * @phpstan-consistent-constructor
 *
 * @see \Switon\Binding\Attribute\ResolvedBy
 */
#[ResolvedBy(DictResolver::class)]
class Dict
{
    protected string $typeCode = '';
    protected string $itemCode = '';
    protected string $itemName = '';
    protected string $value = '';

    /**
     * Return the type code inferred from the concrete class name.
     */
    public static function typeCode(): ?string
    {
        $class = ltrim(static::class, '\\');
        if (str_contains($class, '\\Areas\\')) {
            [, $class] = explode('\\Areas\\', $class, 2);
            return ClassName::dotId('Areas\\' . $class, ['Areas\\']);
        }

        $parts = explode('\\', $class);
        if (count($parts) >= 2) {
            array_shift($parts);
        }
        if (($parts[0] ?? '') === 'Dict') {
            array_shift($parts);
        }

        return ClassName::dotId(implode('\\', $parts));
    }

    /**
     * Return whether this dict type belongs to the built-in dict set.
     */
    public static function builtin(): bool
    {
        return true;
    }

    /**
     * Create a resolved dict value object.
     */
    public static function of(string $typeCode, string $itemCode, string $itemName, string $value): static
    {
        $dict = new static();
        $dict->typeCode = $typeCode;
        $dict->itemCode = $itemCode;
        $dict->itemName = $itemName;
        $dict->value = $value;
        return $dict;
    }

    public function getTypeCode(): string
    {
        return $this->typeCode;
    }

    public function getItemCode(): string
    {
        return $this->itemCode;
    }

    public function getItemName(): string
    {
        return $this->itemName;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
