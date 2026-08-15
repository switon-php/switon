<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Core\Attribute\Autowired;

use function is_string;
use function str_contains;
use function strtolower;

/**
 * Default {@see ContextOverflowCheckerInterface}: `context_length_exceeded` or a message phrase.
 *
 * Built-in phrases stay in {@see self::BUILTIN}. Add more via `$needles`.
 * Bind another {@see ContextOverflowCheckerInterface} to replace this checker.
 */
class ContextOverflowChecker implements ContextOverflowCheckerInterface
{
    /** @var list<string> */
    protected const array BUILTIN = [
        'context length',
        'context_length',
        'maximum context',
        'exceeds the context window',
        'token limit',
        'prompt is too long',
        'input length',
        'input tokens',
        'too many tokens',
        'exceeded model token limit',
        'maximum prompt length',
        'request_too_large',
        'tokens 超过',
        '上下文',
        '提示词过长',
    ];

    /** @var list<string> */
    protected const array REJECTED = [
        'rate limit',
        'too many requests',
        'throttling',
        'tokens per min',
        'tokens per minute',
    ];

    /**
     * Extra overflow phrases beyond {@see self::BUILTIN}. Substring match, case-insensitive.
     *
     * @var list<string>
     */
    #[Autowired] protected array $needles = [];

    public function matches(?string $code, string $message): bool
    {
        if ($code === 'context_length_exceeded') {
            return true;
        }

        $text = strtolower($message);
        foreach (self::REJECTED as $needle) {
            if (str_contains($text, $needle)) {
                return false;
            }
        }
        foreach ($this->needles as $needle) {
            if (is_string($needle) && $needle !== '' && str_contains($text, strtolower($needle))) {
                return true;
            }
        }
        foreach (self::BUILTIN as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
