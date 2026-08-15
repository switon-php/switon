<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * One incremental streaming fragment (not persisted; {@see Completion} lands on append).
 *
 * Guidance: providers fill wire fields and host sets `$at`; caller owns thread/completion/index.
 */
class StreamDelta
{
    /**
     * @param list<ToolCallDelta> $toolCallDeltas
     * @param array<string, mixed> $passback Merged into {@see \Switon\Ai\Message\AssistantMessage::$passback} on aggregate
     */
    public function __construct(
        public string      $id = '',
        public string      $content = '',
        /** `null` = omitted; `''` = present empty fragment. */
        public ?string     $reasoning = null,
        public ?string     $finishReason = null,
        public ?TokenUsage $usage = null,
        public array       $toolCallDeltas = [],
        public array       $passback = [],
        /** Refusal fragment; lands as {@see \Switon\Ai\Part\RefusalPart} on aggregate (not folded into `$content`). */
        public string      $refusal = '',
        /** Host receive time (unix seconds); `null` if unset. */
        public ?float      $at = null,
    ) {
    }
}
