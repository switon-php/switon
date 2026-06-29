<?php

declare(strict_types=1);

namespace Switon\Dict\Attribute;

use Attribute;
use ReflectionProperty;
use Switon\Core\Attribute\Autowired;
use Switon\Dict\Dict;
use Switon\Dict\DictReaderInterface;
use Switon\Validating\AbstractConstraint;
use Switon\Validating\Validation;
use Switon\Validating\ValidatorInterface;

use function is_a;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;

/**
 * Dict-backed validation and property-input normalization boundary.
 *
 * Use on entity or DTO properties when one scalar input must map to a readable dict item value.
 * If <code>$type</code> names a <code>Dict</code> subclass, the constraint derives the type code from that class.
 * Otherwise <code>$type</code> is used as the literal dict type code.
 *
 * Guidance: Type-owned dict classes must resolve a non-empty type code via {@see Dict::typeCode()} or naming convention.
 * - DictReaderInterface
 * - ValidatorInterface
 * - Dict for type-owned dict codes
 *
 * @see \Switon\Dict\Dict
 * @see \Switon\Dict\DictReaderInterface
 * @see \Switon\Validating\ValidatorInterface
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DictValue extends AbstractConstraint
{
    #[Autowired] protected DictReaderInterface $dictReader;
    #[Autowired] protected ValidatorInterface $validator;

    public function __construct(
        public string $type,
        public ?bool  $builtin = null,
        ?string       $label = null,
        ?string       $message = null,
    ) {
        parent::__construct(label: $label, message: $message);
    }


    /**
     * @return array{0: string, 1: bool}
     */
    protected function resolveType(?string $sourceClass = null, ?string $field = null): array
    {
        if (is_a($this->type, Dict::class, true)) {
            $typeCode = $this->type::typeCode() ?? '';
            $builtin = $this->builtin ?? $this->type::builtin();
        } else {
            $typeCode = $this->type;
            $builtin = $this->builtin ?? true;
        }

        if ($typeCode !== '') {
            return [$typeCode, $builtin];
        }

        return ['', $builtin];
    }

    /**
     * Check whether the value should be looked up directly as a scalar dict key.
     */
    protected function isLookupScalar(mixed $value): bool
    {
        return is_bool($value) || is_int($value) || is_float($value);
    }

    /**
     * Normalize lookup input before dict item resolution.
     */
    protected function normalizeLookupValue(string|int|float|bool|null $value): string|int|float|bool|null
    {
        if ($this->isLookupScalar($value) || $value === null) {
            return $value;
        }

        return (string)$value;
    }

    /**
     * Validate a failing property value against the configured validator.
     */
    protected function validateValue(ReflectionProperty $property, string $typeCode, bool $builtin, mixed $value): void
    {
        $validation = $this->validator->beginValidate([]);
        $validation->field = $property->getName();
        $validation->value = $value;
        $validation->sourceClass = $property->getDeclaringClass()->getName();
        $validation->validate(new self($typeCode, $builtin));
        $this->validator->endValidate($validation);
    }

    /**
     * Validate whether the current scalar value maps to a readable dict item.
     */
    public function validate(Validation $validation): bool
    {
        [$typeCode, $builtin] = $this->resolveType($validation->sourceClass, $validation->field);

        if (!is_scalar($validation->value) && $validation->value !== null) {
            return false;
        }

        return $this->dictReader->getItem($typeCode, $this->normalizeLookupValue($validation->value), $builtin) !== null;
    }

    /**
     * Normalize a property input value to the stored dict item value when possible.
     */
    public function normalizeInput(ReflectionProperty $property, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        [$typeCode, $builtin] = $this->resolveType($property->getDeclaringClass()->getName(), $property->getName());

        if (!is_scalar($value)) {
            $this->validateValue($property, $typeCode, $builtin, $value);
            return $value;
        }

        if ($value === '' && $property->getType()?->allowsNull()) {
            return null;
        }

        $item = $this->dictReader->getItem(
            $typeCode,
            $this->normalizeLookupValue($value),
            $builtin
        );

        if ($item !== null) {
            return $item->itemValue;
        }

        $this->validateValue($property, $typeCode, $builtin, $value);

        return $value;
    }
}
