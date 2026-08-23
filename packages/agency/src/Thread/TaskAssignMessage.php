<?php

declare(strict_types=1);

namespace Switon\Agency\Thread;

use Switon\Ai\Part\ContentPart;
use Switon\Ai\Part\AskPart;
use Switon\Ai\Message\UserMessage;

/**
 * Parent→worker assign brief ({@see TaskDeliverTool}). `$taskId` is app/transcript only.
 */
class TaskAssignMessage extends UserMessage
{
    /**
     * @param list<ContentPart> $parts
     */
    public function __construct(
        array         $parts = [],
        public string $taskId = '',
    ) {
        parent::__construct($parts);
    }

    /**
     * @param list<ContentPart> $parts
     */
    public static function of(string $text = '', array $parts = [], string $taskId = ''): static
    {
        if ($text !== '') {
            array_unshift($parts, AskPart::of($text));
        }

        return new static($parts, $taskId);
    }
}
