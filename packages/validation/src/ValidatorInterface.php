<?php

declare(strict_types=1);

namespace Switon\Validating;

/**
 * Defines the validation contract for values and field sets.
 *
 * Use when application or framework code must validate field sets or open manual validation sessions.
 *
 * Road-signs:
 * - validate(): caller-facing entrypoint for field maps
 * - beginValidate() / endValidate(): framework-internal session boundary
 * - formatMessage(): message-template rendering
 *
 * @see \Switon\Validating\Validator
 * @see \Switon\Validating\ValidatorInterface::beginValidate()
 * @see \Switon\Validating\ValidatorInterface::endValidate()
 * @see \Switon\Validating\Validation
 * @see \Switon\Validating\Validation::validate()
 * @see \Switon\Validating\ConstraintInterface
 * @see \Switon\Validating\Attribute\ArrayOf Array element typing for typed-input binding
 * @see \Switon\Validating\Exception\ValidateFailedException
 * @see \Switon\Validating\Exception\ValidateFailedException::raiseForValidationFailed()
 * @see \Switon\Http\RequestBodyResolver::resolve() Main consumer: typed-input population + attribute-driven validation
 * @see \Switon\Orm\AbstractEntityManager Entity save validation
 */
interface ValidatorInterface
{
    /**
     * Validate selected fields on `$source` (progressive).
     *
     * Unconstrained fields are returned unchanged. Constrained fields are checked
     * (and may be normalized). Array sources yield arrays; object sources are updated
     * in place and returned as the same instance.
     *
     * Missing constrained fields are not injected unless a constraint sets a value
     * (e.g. {@see \Switon\Validating\Attribute\Defaults}).
     *
     * @param array<string, mixed>|object $source Source data
     * @param array<string, ConstraintInterface|list<ConstraintInterface>> $constraints Field constraints
     *
     * @return ($source is array ? array<string, mixed> : object)
     *
     * @throws \Switon\Validating\Exception\ValidateFailedException When validation fails for any field
     */
    public function validate(array|object $source, array $constraints): array|object;

    /**
     * Begin a validation session.
     *
     * Intended for framework-internal use (for example request-body binding and entity persistence).
     * Application code should prefer `validate()`.
     * Callers MUST pair this with `endValidate()` so collected errors are finalized.
     *
     * @param array<string, mixed>|object $source Source data
     *
     * @return Validation Validation object for manual validation
     */
    public function beginValidate(array|object $source): Validation;

    /**
     * End a validation session.
     *
     * Intended for framework-internal use. Call after `beginValidate()` to finalize
     * validation and throw if the session collected errors.
     *
     * @param Validation $validation Validation object
     */
    public function endValidate(Validation $validation): void;

    /**
     * Format validation error message.
     *
     * Intended for framework-internal use from the validation session/context layer.
     *
     * @param string $message Constraint FQCN (template key) or custom message template
     * @param array<string, mixed> $placeholders Placeholder values for message formatting
     *
     * @return string Formatted message
     */
    public function formatMessage(string $message, array $placeholders = []): string;
}
