<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Agency\Event\StepRequesting;
use Switon\Agency\Exception\RunCancelledException;
use Switon\Agency\Exception\RunFailedException;
use Switon\Ai\AgentInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\AsToolResult;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Ai\Event\StepResponded;
use Switon\Ai\Event\TurnSuspended;
use Switon\Ai\Exception\ContextLengthExceededException;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\Exception\ToolDeniedException;
use Switon\Ai\Suspension\Interrupt;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Suspension\ToolReviewInterrupt;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Strings;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Llm\LlmProvidersInterface;
use Switon\Thread\Event\CompletionAppended;
use Switon\Thread\ThreadsInterface;
use Switon\Tooling\ArgumentBinderInterface;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\ToolsInterface;
use Throwable;
use function count;
use function in_array;

/**
 * Default {@see LoopInterface}: one-delivery model-step and tools drive (reads the agent, writes tip).
 *
 * @see \Switon\Agency\LoopInterface
 */
class Loop implements LoopInterface
{
    /** Transcript tool_result body when `as_reply=true` on an as-tool call. */
    public const string AS_TOOL_AS_REPLY_PLACEHOLDER = 'As-reply — see assistant reply.';

    /**
     * One re-stream after bad tool args (JSON decode or schema bind); then rethrow.
     * A schema bind writes {@see ArgumentBindException::$errors} into that next request.
     *
     * @see InvalidToolArgumentsException
     * @see ArgumentBindException
     */
    protected const int INVALID_TOOL_ARGS_RETRIES = 1;

    #[Autowired] protected LlmProvidersInterface $llmProviders;
    #[Autowired] protected ToolsInterface $tools;
    #[Autowired] protected ArgumentBinderInterface $argumentBinder;
    #[Autowired] protected ExtensionsInterface $extensions;
    #[Autowired] protected ThreadsInterface $threads;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ClockInterface $clock;

    public function drive(AgentInterface $agent, Delivery $delivery, int $maxSteps): ?Throwable
    {
        $thread = $agent->thread();
        $completion = $thread->lastCompletion();

        try {
            $this->eventDispatcher->dispatch(new DeliveryStarted($agent, $completion));

            for ($step = $thread->lastCompletion()->step; $step <= $maxSteps; $step++) {
                if ($agent->isCancelled()) {
                    break;
                }

                $charter = $agent->charter();
                $turn = $thread->lastTurn();

                // The open tool row holds results. The calls themselves are on the assistant row before it.
                $tip = $turn->lastCompletion();
                $assistant = $tip->input instanceof ToolMessage && $tip->output === null
                    ? $turn->completions[count($turn->completions) - 2]
                    : $tip;
                if ($assistant->toolCalls() !== []
                    && $this->dispatchToolCalls($agent, $turn, $assistant, $assistant->toolCalls())) {
                    if ($agent->isCancelled() && !$turn->isSettled()) {
                        break;
                    }

                    return null;
                }

                $completion = $turn->lastCompletion();
                try {
                    $this->completeStep($agent, $charter, $delivery);
                } catch (Throwable $e) {
                    return $this->finishRun($agent, $completion, $e);
                }

                $output = $completion->output;
                $toolCalls = $completion->toolCalls();
                // A reply with nothing left to run is the end of the turn. Seal it before cancel
                // handling, so a stop cannot leave the reply parked on a still-running turn.
                if ($output !== null && !($output instanceof AssistantMessage && $toolCalls !== [])) {
                    if (!$completion->isCancelled()) {
                        $completion->landedAt = $this->clock->now();
                    }
                    $turn->status = TurnStatus::Completed;
                    $this->threads->commit($thread);
                    if ($agent->isCancelled()) {
                        return $this->finishRun($agent, $completion, RunCancelledException::of());
                    }

                    return null;
                }

                if (!$completion->isCancelled() && $output !== null) {
                    $completion->landedAt = $this->clock->now();
                    $this->threads->saveTurn($thread);
                    $this->eventDispatcher->dispatch(new CompletionAppended($this->threads, $thread));
                }

                if ($agent->isCancelled() && $toolCalls === []) {
                    break;
                }
                // Empty text/reasoning is fine — UI meta shows stop; rejecting only dirtied history.
                if ($toolCalls === []) {
                    $turn->status = TurnStatus::Completed;
                    $this->threads->commit($thread);

                    return null;
                }

                // Tool results for the last model step carry the budget notice.
                $ended = $this->dispatchToolCalls(
                    $agent,
                    $turn,
                    $completion,
                    $toolCalls,
                    nearBudgetWarning: $maxSteps >= 2 && $step === $maxSteps - 1,
                );
                if ($ended) {
                    if ($agent->isCancelled() && !$turn->isSettled()) {
                        break;
                    }

                    return null;
                }
            }
        } catch (Throwable $e) {
            $message = RunFailedException::format($e);
            if ($e instanceof RunCancelledException) {
                return $this->finishRun($agent, $completion, $e);
            }

            $turn = $thread->lastTurn();
            if (!$turn->isSettled()) {
                $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_EXCEPTION, body: $message);
                $turn->close($signal, $this->clock->now());
                $this->threads->commit($thread);
            }

            return $e;
        }

        if ($agent->isCancelled()) {
            return $this->finishRun($agent, $completion, RunCancelledException::of());
        }

        $turn = $thread->lastTurn();
        if ($turn->status === TurnStatus::Running && $maxSteps >= 1 && $step > $maxSteps) {
            $signal = new AssistantAbortSignal(
                AssistantAbortSignal::KIND_BUDGET_EXCEEDED,
                body: 'Agent step budget is exhausted.',
            );
            $turn->close($signal, $this->clock->now());
            $this->threads->commit($thread);

            return null;
        }
        if ($turn->status === TurnStatus::Running) {
            $turn->status = TurnStatus::Completed;
            $this->threads->commit($thread);
        }

        return null;
    }

    /**
     * One model step into the hung tip slot.
     *
     * Guidance: prompt and tools live on the charter and may be rebuilt after a tool runs.
     * The route and effort stay on the envelope. This method reads them when it sends.
     */
    protected function completeStep(AgentInterface $agent, Charter $charter, Delivery $delivery): void
    {
        $thread = $agent->thread();
        $turn = $thread->lastTurn();
        $completion = $turn->lastCompletion();
        // Extensions may replace prompt and tools here. The route and effort stay on the envelope.
        $this->extensions->beforeModel($agent, $charter);
        $charter->effort = $delivery->envelope->effort;
        $llm = $this->llmProviders->get($delivery->envelope->model);

        $this->eventDispatcher->dispatch(new StepRequesting($agent, $completion, $charter));

        $retriesLeft = self::INVALID_TOOL_ARGS_RETRIES;
        $bindFeedback = null;
        while (true) {
            $feedbackHost = null;
            $feedbackPrompt = null;
            $appliedPrompt = null;
            try {
                if ($bindFeedback !== null) {
                    $feedbackHost = $charter;
                    $feedbackPrompt = $charter->prompt;
                    $note = "Tool arguments were rejected:\n" . $bindFeedback;
                    $feedbackPrompt = trim($feedbackPrompt);
                    $appliedPrompt = $feedbackPrompt === '' ? $note : $feedbackPrompt . "\n\n" . $note;
                    $charter->prompt = $appliedPrompt;
                }

                $llm->stream($thread, $charter, $agent->onDelta(...), $agent->isCancelled(...));

                if ($completion->isCancelled()) {
                    break;
                }

                $errors = [];
                foreach ($completion->toolCalls() as $index => $call) {
                    $schema = $charter->tools[$call->name] ?? null;
                    if ($schema === null) {
                        continue;
                    }

                    try {
                        $call->arguments = $this->argumentBinder->bind($schema, $call->arguments);
                    } catch (ArgumentBindException $e) {
                        foreach ($e->errors as $path => $message) {
                            $key = $call->name . '.' . $path;
                            if (array_key_exists($key, $errors)) {
                                $key = $call->name . '.' . $index . '.' . $path;
                            }
                            $errors[$key] = $message;
                        }
                    }
                }
                if ($errors !== []) {
                    throw ArgumentBindException::failed($errors);
                }

                break;
            } catch (InvalidToolArgumentsException|ArgumentBindException $e) {
                if ($retriesLeft <= 0) {
                    throw $e;
                }
                $retriesLeft--;
                $bindFeedback = $e instanceof ArgumentBindException ? $e->getMessage() : null;
                $completion->completeId = Strings::uuid();
                $completion->output = null;
                $completion->finishReason = null;
                $completion->usage = null;
                $completion->timing = null;
                $this->eventDispatcher->dispatch(new StepRequesting($agent, $completion, $charter));
            } finally {
                if ($feedbackHost !== null && $appliedPrompt !== null && $feedbackHost->prompt === $appliedPrompt) {
                    $feedbackHost->prompt = $feedbackPrompt;
                }
            }
        }

        // Model answered; extensions may reshape the completion before its tools are dispatched.
        $this->extensions->afterModel($agent, $completion);
        $this->eventDispatcher->dispatch(new StepResponded($agent, $completion));
    }

    /**
     * Stop → discard / interrupt signal; other failures → {@see RetryInterrupt} suspend.
     * Does not settle delegated workers — that lives on {@see \Switon\Agency\Tool\TaskDeliverTool}.
     */
    protected function finishRun(AgentInterface $agent, Completion $step, Throwable $error): Throwable
    {
        $message = RunFailedException::format($error);
        $thread = $agent->thread();
        $turn = $thread->lastTurn();
        if ($turn->isSettled()) {
            return $error;
        }
        if ($error instanceof RunCancelledException) {
            $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_CANCELLED, body: $message);
            $turn->close($signal, $this->clock->now());
            $this->threads->commit($thread);

            return $error;
        }

        $reason = match (true) {
            $error instanceof ContextLengthExceededException
            => RetryInterrupt::REASON_CONTEXT_OVERFLOW,
            $error instanceof InvalidToolArgumentsException
            || $error instanceof ArgumentBindException
            => RetryInterrupt::REASON_TOOL_ARGS_INVALID,
            default => RetryInterrupt::REASON_LLM_FAILED,
        };
        // A retry keeps the original hung slot; checkpoint it only when it is not there yet.
        if (!in_array($step, $turn->completions, true)) {
            $step->landedAt = $this->clock->now();
            $turn->completions[] = $step;
            $this->threads->saveTurn($thread);
            $this->eventDispatcher->dispatch(new CompletionAppended($this->threads, $thread));
        }
        $this->suspendTurn($agent, new RetryInterrupt($reason, $message));

        return $error;
    }

    protected function suspendTurn(AgentInterface $agent, Interrupt $interrupt): void
    {
        $thread = $agent->thread();
        $turn = $thread->lastTurn();
        $interrupt->suspendedAt = $this->clock->now();
        $turn->interrupt = $interrupt;
        $turn->status = TurnStatus::Suspended;
        $this->threads->saveTurn($thread);
        $this->eventDispatcher->dispatch(new TurnSuspended($agent, $turn, $turn->interrupt));
    }

    /**
     * Serial tool dispatch: land each success on the open tool-batch row; suspend on {@see Interrupt}.
     * Resume continues the calls that still have no result.
     *
     * A call with {@see ToolCall::$reviewed} skips review.
     *
     * @param list<ToolCall> $toolCalls
     *
     * @return bool Suspend, cancel, or an as-reply answer already sealed on the turn.
     */
    protected function dispatchToolCalls(
        AgentInterface $agent,
        Turn           $turn,
        Completion     $assistantStep,
        array          $toolCalls,
        bool           $nearBudgetWarning = false,
    ): bool
    {
        $thread = $agent->thread();
        $lastIndex = count($toolCalls) - 1;
        if ($toolCalls !== []) {
            $tip = $turn->lastCompletion();
            if ($tip === $assistantStep || !$tip->input instanceof ToolMessage || $tip->output !== null) {
                $next = Completion::of(ToolMessage::of([]));
                $next->step = $assistantStep->step + 1;
                $turn->completions[] = $next;
            }
        }
        $landed = [];
        $tip = $turn->lastCompletion();
        if ($tip->input instanceof ToolMessage && $tip->output === null) {
            foreach ($tip->input->results as $result) {
                $landed[$result->id] = true;
            }
        }

        foreach ($toolCalls as $index => $toolCall) {
            if (isset($landed[$toolCall->id])) {
                continue;
            }
            if ($agent->isCancelled()) {
                return true;
            }

            $charter = $agent->charter();
            if (!isset($charter->tools[$toolCall->name])) {
                $this->landToolResult($agent, ToolResult::of($toolCall, new ToolDeniedException()));
                continue;
            }

            // This call was already reviewed — do not ask again (that loop never ends).
            if (!$toolCall->reviewed && $this->extensions->reviewTool($agent, $toolCall)) {
                $this->suspendTurn($agent, ToolReviewInterrupt::of($toolCall));

                return true;
            }

            $this->extensions->beforeTool($agent, $toolCall);

            $outcome = $this->tools->call($agent, $toolCall, $charter->tools[$toolCall->name]);

            if ($outcome instanceof Interrupt) {
                $this->suspendTurn($agent, $outcome);

                return true;
            }

            $toolResult = $outcome;

            // Extensions may reshape the result (truncate, redact, annotate) before it lands.
            $this->extensions->afterTool($agent, $toolResult);

            // The result type is the honest tell — only the as-tool toolkit returns {@see AsToolResult}.
            if ($index === $lastIndex && $toolResult instanceof AsToolResult && ($toolCall->arguments['as_reply'] ?? false) === true) {
                $reply = $toolResult->turn->lastCompletion()->output;
                $toolResult->content = self::AS_TOOL_AS_REPLY_PLACEHOLDER;
                $this->landToolResult($agent, $toolResult);
                if ($agent->isCancelled()) {
                    return true;
                }

                $tip = $turn->lastCompletion();
                if ($tip->input instanceof ToolMessage && $tip->output === null && $reply instanceof AssistantMessage) {
                    $tip->output = $reply;
                    $tip->finishReason = 'stop';
                    $tip->landedAt = $this->clock->now();
                    if ($reply->toolCalls === []) {
                        $turn->status = TurnStatus::Completed;
                        $this->threads->commit($thread);

                        return true;
                    }
                    $this->threads->saveTurn($thread);
                    $this->eventDispatcher->dispatch(new CompletionAppended($this->threads, $thread));
                }

                return false;
            }

            if ($nearBudgetWarning && $index === $lastIndex) {
                $toolResult->parts[] = TextPart::of(
                    'Step budget: this is the last chance this turn. Wrap up with the answer now. Do not call more tools.',
                );
            }

            $this->landToolResult($agent, $toolResult);
            if ($agent->isCancelled()) {
                return true;
            }
        }

        return false;
    }

    /** Hang one tool result on the tool-batch row opened before the tool loop. */
    protected function landToolResult(AgentInterface $agent, ToolResult $result): void
    {
        /** @var ToolMessage $input */
        $input = $agent->thread()->lastTurn()->lastCompletion()->input;
        $input->results[] = $result;
    }
}