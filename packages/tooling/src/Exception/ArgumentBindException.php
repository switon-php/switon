<?php

declare(strict_types=1);

namespace Switon\Tooling\Exception;

/**
 * Schema bind failed before the tool runs. `$errors` is one message per field path; children of a failed path are absent.
 *
 * @see \Switon\Tooling\ArgumentBinder::bind()
 */
class ArgumentBindException extends ArgumentException
{
    /** @var array<string, string> Field path => the one error for that path. */
    public array $errors = [];

    /**
     * @param array<string, string> $errors
     */
    public static function failed(array $errors): static
    {
        $lines = [];
        foreach ($errors as $path => $message) {
            $lines[] = $path . ': ' . $message;
        }

        $exception = new static(implode("\n", $lines));
        $exception->errors = $errors;

        return $exception;
    }
}
