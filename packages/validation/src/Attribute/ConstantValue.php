<?php

declare(strict_types=1);

namespace Switon\Validating\Attribute;

use Attribute;
use ReflectionClass;
use ReflectionException;
use Switon\Validating\AbstractConstraint;
use Switon\Validating\Validation;

use function is_object;
use function str_starts_with;
use function strtoupper;

/**
 * Validation attribute for constant-backed value membership.
 *
 * Use when a field value must match one of the target class constants resolved by field prefix.
 *
 * Guidance: Provide an explicit <code>$class</code> or an object/array source with <code>sourceClass</code>; prefix defaults to the field name.
 *
 * @see \Switon\Validating\AbstractConstraint
 * @see \Switon\Validating\Validation
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ConstantValue extends AbstractConstraint
{
    /**
     * @param string|null $prefix Constant prefix name (defaults to field name)
     * @param string|null $message Custom error message
     * @param class-string|null $class Constant class (defaults to current source object)
     */
    public function __construct(
        public ?string $prefix = null,
        public ?string $message = null,
        public ?string $class = null,
    ) {
        parent::__construct(message: $message);
    }

    public function validate(Validation $validation): bool
    {
        $rClass = $this->resolveConstantClass($validation);
        if ($rClass === null) {
            return false;
        }

        $prefix = strtoupper($this->prefix ?? $validation->field) . '_';
        $matched = false;

        foreach ($rClass->getConstants() as $name => $value) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $matched = true;
            if ($value === $validation->value) {
                return true;
            }
        }

        if (!$matched) {
            return false;
        }

        return false;
    }

    /**
     * @return ReflectionClass<object>|null
     */
    protected function resolveConstantClass(Validation $validation): ?ReflectionClass
    {
        $class = $this->class;

        if ($class === null) {
            if (is_object($validation->source)) {
                $class = $validation->source::class;
            } elseif (($validation->sourceClass ?? '') !== '') {
                $class = $validation->sourceClass;
            } else {
                return null;
            }
        }

        try {
            return new ReflectionClass($class);
        } catch (ReflectionException) {
            return null;
        }
    }
}
