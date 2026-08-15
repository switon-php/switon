<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

use Switon\Llm\Exception;

use function is_array;
use function is_string;
use function trim;

/** API call terminal response with a non-ok `code`. */
class ApiFailedException extends Exception
{
    public string $reason;

    /** @var array<string, mixed> */
    public array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $reason, array $details = [])
    {
        $this->reason = $reason;
        $this->details = $details;

        parent::__construct($reason, ['details' => $details]);
    }

    /**
     * @param array<string, mixed> $arguments Terminal tool envelope (`code` / `msg` / `data`).
     */
    public static function fromArguments(array $arguments): self
    {
        $text = $arguments['msg'] ?? $arguments['message'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            return new self('API failure requires a non-empty msg.');
        }

        $details = $arguments['data'] ?? $arguments['details'] ?? [];
        if (!is_array($details)) {
            $details = [];
        }

        return new self(trim($text), $details);
    }
}
