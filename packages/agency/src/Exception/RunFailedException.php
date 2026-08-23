<?php

declare(strict_types=1);

namespace Switon\Agency\Exception;

use Switon\Agency\Exception as AgentException;
use Switon\Llm\Exception\HttpStatusException;
use Throwable;
use Exception;

use function mb_strcut;
use function strlen;
use function trim;

/** Operational run failure wrapper and wire message formatter. */
class RunFailedException extends AgentException
{
    public function __construct(string $message, ?Exception $previous = null)
    {
        parent::__construct($message, [], 0, $previous);
    }

    public static function from(Throwable $error): self
    {
        $previous = $error instanceof Exception ? $error : null;

        return new self(self::format($error), $previous);
    }

    public static function format(Throwable $error): string
    {
        if ($error instanceof RunCancelledException) {
            return $error->interruptMessage();
        }

        if ($error instanceof HttpStatusException) {
            return $error->interruptMessage();
        }

        $detail = trim($error->getMessage());
        $head = $error::class;
        if ($detail !== '') {
            $head .= ': ' . $detail;
        }

        return strlen($head) > 2048 ? mb_strcut($head, 0, 2048) : $head;
    }
}
