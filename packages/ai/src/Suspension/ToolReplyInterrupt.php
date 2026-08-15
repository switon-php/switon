<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Exception;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;

/**
 * Turn suspend: waiting for an external reply to one tool call.
 *
 * @see \Switon\Ai\Suspension\ToolReplyContinuation
 */
class ToolReplyInterrupt extends ToolInterrupt
{
    public function resume(AgentInterface $agent, Turn $turn, Continuation $mail, ContainerInterface $container): void
    {
        /** @var ToolReplyContinuation $mail */
        if ($mail->callId !== $this->callId) {
            Exception::raise('Tool reply callId {got} does not match interrupt {expected}.', [
                'got' => $mail->callId,
                'expected' => $this->callId,
            ]);
        }

        $call = $turn->toolCall($this->callId);
        /** @var ToolMessage $input */
        $input = $turn->lastCompletion()->input;
        $input->results[] = ToolResult::of($call, $mail->reply);
    }
}
