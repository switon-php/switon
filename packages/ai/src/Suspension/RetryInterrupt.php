<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Turn;
use Switon\Core\ClockInterface;

/** Turn suspend: recoverable step failure — resume with {@see RetryContinuation}. */
class RetryInterrupt extends Interrupt
{
    public const string REASON_LLM_FAILED = 'llm_failed';
    public const string REASON_TOOL_ARGS_INVALID = 'tool_args_invalid';
    /** Context window overflow after compact/trim stages exhausted. */
    public const string REASON_CONTEXT_OVERFLOW = 'context_overflow';
    /** Process restarted while a readonly tool batch was still open. */
    public const string REASON_SESSION_RESTARTED = 'session_restarted';

    public function __construct(
        public string $reason = self::REASON_LLM_FAILED,
        public string $detail = '',
    )
    {
    }

    public function resume(AgentInterface $agent, Turn $turn, Continuation $mail, ContainerInterface $container): void
    {
        /** @var RetryContinuation $mail */

        // Anything but an explicit accept is a reject — a typo'd decision must not re-drive the LLM.
        if ($mail->decision === RetryContinuation::DECISION_ACCEPT) {
            $completion = $turn->lastCompletion();
            $completion->output = null;
            $completion->finishReason = null;
            $completion->timing = null;

            return;
        }

        $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_EXCEPTION, body: $this->detail);
        $turn->close($signal, $container->get(ClockInterface::class)->now());
    }
}
