<?php

declare(strict_types=1);

namespace Switon\Dict;

use ReflectionParameter;
use Switon\Binding\ScalarResolverInterface;
use Switon\Binding\ValueResolverInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Dict\Attribute\DictValue;
use Switon\Dict\Exception\DictItemNotFoundException;
use Switon\Dict\Exception\DictTypeCodeRequiredException;
use Switon\Dict\Exception\DictTypeNotFoundException;
use Switon\Dict\Exception\UnsupportedDictParameterTypeException;
use Switon\Validating\Exception\ValidateFailedException;
use Switon\Validating\ValidatorInterface;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Resolves Dict parameters from request input and type-code conventions.
 *
 * Guidance: Keep generic Dict resolution type-owned; override Dict::typeCode() only when the class name should not map to the final dict type code.
 *
 * Road-signs:
 * - ValueResolverInterface
 * - ScalarResolverInterface
 * - DictReaderInterface
 * - default dict item
 *
 * @see \Switon\Dict\Dict
 * @see \Switon\Dict\DictReaderInterface
 * @see \Switon\Dict\Attribute\DictValue
 */
class DictResolver implements ValueResolverInterface
{
    #[Autowired] protected DictReaderInterface $dictReader;
    #[Autowired] protected ScalarResolverInterface $scalarResolver;
    #[Autowired] protected ValidatorInterface $validator;

    /**
     * Resolve one Dict parameter from the current input source.
     *
     * Returns null only when the parameter allows null and the incoming value is missing.
     *
     * @throws UnsupportedDictParameterTypeException
     * @throws DictTypeCodeRequiredException
     * @throws DictTypeNotFoundException
     * @throws DictItemNotFoundException
     * @throws ValidateFailedException
     */
    public function resolve(ReflectionParameter $parameter, string $type): ?Dict
    {
        if (!is_a($type, Dict::class, true)) {
            UnsupportedDictParameterTypeException::raise(
                'DictResolver only supports "{expected}" subclasses, got "{actual}".',
                ['expected' => Dict::class, 'actual' => $type]
            );
        }

        /** @var class-string<Dict> $type */
        $typeCode = $this->resolveTypeCode($parameter, $type);
        $builtin = $type::builtin();
        $dictType = $this->dictReader->getType($typeCode, $builtin);
        if (!$dictType instanceof DictType) {
            DictTypeNotFoundException::raise(
                'Dict type "{typeCode}" does not exist or is disabled.',
                ['typeCode' => $typeCode]
            );
        }

        $rawValue = $this->resolveRawValue($parameter);
        if ($this->isMissingRawValue($rawValue) && $parameter->allowsNull()) {
            return null;
        }

        $item = $this->isMissingRawValue($rawValue)
            ? $this->dictReader->getDefaultItem($typeCode, $builtin)
            : $this->dictReader->getItem($typeCode, $rawValue, $builtin);

        if (!$item instanceof DictEntry) {
            $this->validateValue($parameter, $typeCode, $builtin, $rawValue);

            DictItemNotFoundException::raise(
                'Dict parameter "{parameter}" did not resolve for type "{typeCode}".',
                ['parameter' => $parameter->getName(), 'typeCode' => $typeCode]
            );
        }

        return $type::of($typeCode, $item->itemCode, $item->itemName, $item->itemValue);
    }

    /**
     * Resolve the dict type code for the concrete Dict subclass.
     *
     * @param class-string<Dict> $type
     */
    protected function resolveTypeCode(ReflectionParameter $parameter, string $type): string
    {
        $typeCode = $type::typeCode();
        if ($typeCode !== null && $typeCode !== '') {
            return $typeCode;
        }

        DictTypeCodeRequiredException::raise(
            'Unable to derive dict type for "{class}::{parameter}". Override {type}::typeCode().',
            [
                'class' => $parameter->getDeclaringClass()?->getName() ?? '',
                'parameter' => $parameter->getName(),
                'type' => $type,
            ]
        );
    }

    /**
     * Resolve the raw scalar input value for a dict parameter.
     */
    protected function resolveRawValue(ReflectionParameter $parameter): string|int|float|bool|null
    {
        $value = $this->scalarResolver->resolve($parameter, 'string');

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Check whether the current value should fall back to the default dict item.
     */
    protected function isMissingRawValue(string|int|float|bool|null $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Validate a failing dict value against the configured validator.
     */
    protected function validateValue(
        ReflectionParameter        $parameter,
        string                     $typeCode,
        bool                       $builtin,
        string|int|float|bool|null $value
    ): void {
        $validation = $this->validator->beginValidate([]);
        $validation->field = $parameter->getName();
        $validation->value = $value;
        $validation->sourceClass = $parameter->getDeclaringClass()?->getName();
        $validation->validate(new DictValue($typeCode, $builtin));
        $this->validator->endValidate($validation);
    }
}
