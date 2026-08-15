<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Exception;
use Switon\Ai\Exception\ToolDeniedException;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;

/**
 * Turn suspend: waiting for host allow/deny on one tool call.
 *
 * @see \Switon\Ai\Suspension\ToolReviewContinuation
 */
class ToolReviewInterrupt extends ToolInterrupt
{
    public function resume(AgentInterface $agent, Turn $turn, Continuation $mail, ContainerInterface $container): void
    {
        /** @var ToolReviewContinuation $mail */
        if ($mail->callId !== $this->callId) {
            Exception::raise('Tool review callId {got} does not match interrupt {expected}.', [
                'got' => $mail->callId,
                'expected' => $this->callId,
            ]);
        }

        $call = $turn->toolCall($this->callId);
        $call->reviewed = true;

        // Anything but an explicit allow is a deny — a typo'd decision must not run the tool.
        if ($mail->decision === ToolReviewContinuation::DECISION_ALLOW) {
            return;
        }

        /** @var ToolMessage $input */
        $input = $turn->lastCompletion()->input;
        $input->results[] = ToolResult::of(
            $call,
            ToolDeniedException::of($mail->reason !== '' ? 'Tool call denied: ' . $mail->reason : 'Tool call denied.'),
        );
    }
}
