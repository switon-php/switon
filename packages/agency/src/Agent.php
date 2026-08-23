<?php

declare(strict_types=1);

namespace Switon\Agency;

use Closure;
use Psr\Container\ContainerInterface;
use Switon\Agency\Exception\DeliveryException;
use Switon\Agency\Exception\RunCancelledException;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Ai\AgentInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\DiscardTurnLetter;
use Switon\Ai\Envelope;
use Switon\Ai\Event\DeliverySettled;
use Switon\Ai\Profile;
use Switon\Ai\Signal\SessionResumedSignal;
use Switon\Ai\StreamDelta;
use Switon\Ai\Suspension\Continuation;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Exception;
use Switon\Core\MakerInterface;
use Switon\Core\Strings;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Sync\TerminationInterface;
use Switon\Thread\Exception\ThreadNotFoundException;
use Switon\Thread\ThreadsInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;
use WeakMap;

/**
 * Agent runtime (root when `$parent` is null). Inbox via `start()` / `deliver()` / `run()`.
 *
 * Road-signs:
 * - Lifecycle: `start()` seals a leftover running turn, then opens the inbox worker; `shutdown()` marks cancel and closes the inbox without waiting for the in-flight tool
 * - Drive: `runDelivery()` → `executeDelivery()` → {@see \Switon\Agency\Loop::drive()}
 * - Context: `thread()` persisted by {@see \Switon\Thread\ThreadsInterface}
 * - Delegation: `spawn()` creates as-tool workers under {@see \Switon\Agency\Toolkit\AsToolToolkit}
 */
class Agent implements AgentInterface
{
    /** Inbox sentinel: wakes an idle worker for `cancel()` without polling. */
    protected const INBOX_WAKE = 0;

    #[Autowired] protected LoopInterface $loop;
    #[Autowired] protected ThreadsInterface $threads;
    #[Autowired] protected ChartersInterface $charters;
    #[Autowired] protected ExtensionsInterface $extensions;
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ClockInterface $clock;
    #[Autowired] protected TerminationInterface $termination;

    #[Autowired] protected Thread $thread;
    #[Autowired] protected Profile $profile;
    #[Autowired] protected string $workspace = '';
    /** @var null|Closure(StreamDelta): void Stream sink for this agent; `spawn()` copies it to children. */
    #[Autowired] public ?Closure $onDelta = null;
    #[Autowired] protected int $inboxSize = 16;

    protected ?AgentInterface $parent = null;
    /** @var Channel|null */
    protected ?Channel $inbox = null;
    /** Non-null while cancel is in progress until the worker is quiescent. */
    protected ?Channel $cancel = null;
    /** Per-delivery charter instance held across steps; null between deliveries. */
    protected ?Charter $charter = null;
    /** @var WeakMap<Agent, string>|null Spawned children (key = child, value = leaf). */
    protected ?WeakMap $children = null;

    public function threadId(): string
    {
        return $this->thread->id;
    }

    public function thread(): Thread
    {
        return $this->thread;
    }

    /**
     * Charter for the current delivery. Readers share one instance until it is dropped or invalidated.
     *
     * Guidance: {@see switchProfile()} drops the cache; `invalidate()` marks the live instance when
     * the profile stays (desk switch, compaction, tool grant).
     */
    public function charter(): Charter
    {
        if ($this->charter === null || $this->charter->invalidated()) {
            $this->charter = $this->charters->get($this);
        }

        return $this->charter;
    }

    public function depth(): int
    {
        return $this->parent === null ? 0 : $this->parent->depth() + 1;
    }

    public function parent(): ?AgentInterface
    {
        return $this->parent;
    }

    public function root(): AgentInterface
    {
        $agent = $this;
        while (($parent = $agent->parent()) !== null) {
            $agent = $parent;
        }

        return $agent;
    }

    public function workspace(): string
    {
        if ($this->workspace !== '') {
            return $this->workspace;
        }

        $root = $this->root();

        return $root === $this ? '' : $root->workspace();
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    /** Runtime profile swap; drops the charter cache and flushes the resume recipe (`profileId`). */
    public function switchProfile(Profile $profile): void
    {
        if ($this->profile->id === $profile->id) {
            return;
        }

        $this->profile = $profile;
        $this->charter = null;
        $this->thread->state['profileId'] = $profile->id;
        $this->threads->saveState($this->thread);
    }

    /**
     * Spawn a delegated worker under this agent (starts its inbox).
     * Pass a leaf id (`t10001`…); null allocates the next leaf under `root()` and persists `subseq`.
     */
    public function spawn(Profile $profile, ?string $leaf = null): static
    {
        $rootThread = $this->root()->thread();
        if ($leaf === null) {
            $seq = $rootThread->state['subseq'] ?? 10001;
            $rootThread->state['subseq'] = $seq + 1;
            $this->threads->saveState($rootThread);
            $leaf = 't' . $seq;
        }
        $threadId = $rootThread->id . '.' . $leaf;
        $rootDir = $rootThread->datadir;
        $datadir = $rootDir === null ? null : $rootDir . '/subagents/' . $leaf;
        $thread = Thread::of($threadId, $datadir);
        try {
            $this->threads->load($thread);
        } catch (ThreadNotFoundException) {
            $this->threads->create($thread);
        }

        /** @var static $worker */
        $worker = $this->maker->make(static::class, [
            'thread' => $thread,
            'onDelta' => $this->onDelta,
            'profile' => $profile,
        ]);
        $worker->parent = $this;
        $worker->start();

        $this->children ??= new WeakMap();
        $this->children[$worker] = $leaf;

        return $worker;
    }

    public function start(): void
    {
        if ($this->thread->turns !== []) {
            $turn = $this->thread->lastTurn();
            if ($turn->status !== TurnStatus::Suspended && !$turn->isSettled()) {
                $turn->close(new SessionResumedSignal(), $this->clock->now());
                $this->threads->commit($this->thread);
            }
        }

        // Single worker, serial runs.
        $this->inbox = new Channel($this->inboxSize);
        $this->termination->register($this->inbox);
        $scheduled = Coroutine::create(function (): void {
            while (($inbox = $this->inbox) !== null) {
                $item = $inbox->pop(-1);
                if ($item === false) {
                    break;
                }

                if ($item === self::INBOX_WAKE) {
                } else {
                    try {
                        $outcome = $this->runDelivery($item);
                    } catch (Throwable $e) {
                        $outcome = $e;
                    }
                    if ($item->done !== null) {
                        $item->done->push($outcome);
                    }
                }

                if ($inbox->length() === 0 && $this->cancel !== null && $this->cancel->length() === 0) {
                    $this->cancel->push(true, 0.0);
                }
            }
        });
        if ($scheduled === false) {
            $this->inbox = null;
            Exception::raise('Failed to start agent worker coroutine.');
        }
    }

    public function deliver(Envelope $envelope): void
    {
        if ($this->inbox->push(new Delivery($envelope, $this->clock->microtime()), 3.0) !== false) {
            return;
        }
        DeliveryException::inboxFull();
    }

    public function run(Envelope $envelope): ?Turn
    {
        $done = new Channel(1);
        $delivery = new Delivery($envelope, $this->clock->microtime(), $done);
        if ($this->inbox->push($delivery, 3.0) === false) {
            DeliveryException::inboxFull();
        }

        $outcome = $done->pop();
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $this->thread->turns === [] ? null : $this->thread->lastTurn();
    }

    public function cancel(): void
    {
        if ($this->cancel !== null) {
            // Another coroutine releases this once the worker is quiescent.
            $gate = $this->cancel;
            while ($gate->length() === 0) {
                Coroutine::sleep(0.001);
            }

            return;
        }

        if ($this->inbox === null) {
            return;
        }

        // Flag first so a sync child unblocks this worker, which is what cancel() waits on.
        $this->cancel = new Channel(1);
        if ($this->children !== null) {
            foreach ($this->children as $child => $leaf) {
                $child->cancel();
            }
        }
        $this->inbox->push(self::INBOX_WAKE);
        $this->cancel->pop();
        $this->cancel = null;
    }

    public function isCancelled(): bool
    {
        return $this->cancel !== null;
    }

    /** Forward one fragment to {@see $onDelta} when a sink is injected. */
    public function onDelta(StreamDelta $delta): void
    {
        if ($this->onDelta !== null) {
            ($this->onDelta)($delta);
        }
    }

    /** This worker and every spawned child are blocked waiting for the next letter. */
    public function isIdle(): bool
    {
        $inbox = $this->inbox;
        // consumer_num counts coroutines blocked in pop(); 0 means this worker is busy or gone.
        if ($inbox === null || $inbox->stats()['consumer_num'] === 0) {
            return false;
        }

        if ($this->children === null) {
            return true;
        }

        foreach ($this->children as $child => $leaf) {
            if (!$child->isIdle()) {
                return false;
            }
        }

        return true;
    }

    public function shutdown(): void
    {
        if ($this->inbox === null) {
            return;
        }

        if ($this->cancel === null) {
            $this->cancel = new Channel(1);
        }

        $inbox = $this->inbox;
        $this->inbox = null;
        $inbox->close();
        // cancel() may be waiting. This method does not wait for the in-flight tool.
        if ($this->cancel->length() === 0) {
            $this->cancel->push(true, 0.0);
        }

        while (($item = $inbox->pop(0.0)) !== false) {
            if ($item === self::INBOX_WAKE) {
                continue;
            }

            /** @var Delivery $item */
            if ($item->done !== null) {
                $item->done->push(RunCancelledException::of());
            }
        }
    }

    /** Prepare hung step, then {@see LoopInterface::drive()}. */
    protected function runDelivery(Delivery $delivery): ?Throwable
    {
        // Inbound hooks run before any turn exists — they widen the letter, never write rows.
        $this->extensions->inbound($this, $delivery->envelope);
        $input = $delivery->envelope->letter;

        if ($input instanceof DiscardTurnLetter) {
            $thread = $this->thread;
            if ($thread->turns !== [] && $thread->lastTurn()->isSettled()) {
                array_pop($thread->turns);
                $this->threads->replaceConversation($thread);
            }

            return null;
        }

        if ($input instanceof Continuation) {
            try {
                $turn = $this->thread->lastTurn();
                $interrupt = $turn->interrupt;
                if (!$interrupt instanceof ($input->pairsWith())) {
                    Exception::raise('Mail {mail} cannot resume interrupt {interrupt}.', [
                        'mail' => $input::class,
                        'interrupt' => $interrupt === null ? 'null' : $interrupt::class,
                    ]);
                }
                $turn->interrupt = null;
                $turn->status = TurnStatus::Running;
                $interrupt->resume($this, $turn, $input, $this->container);
                if ($turn->isSettled()) {
                    $this->threads->commit($this->thread);
                    if ($delivery->envelope->onSettled !== null) {
                        ($delivery->envelope->onSettled)($this, $turn);
                    }
                    $this->extensions->afterTurn($this, $turn);
                    $this->eventDispatcher->dispatch(new DeliverySettled($this, $turn));

                    return null;
                }
                $this->threads->saveTurn($this->thread);

                return $this->executeDelivery($delivery);
            } catch (Throwable $e) {
                // Failed before drive. The worker turns this into the done-channel result.
                if ($this->thread->turns !== []) {
                    $this->eventDispatcher->dispatch(new DeliverySettled($this, $this->thread->lastTurn()));
                }

                throw $e;
            }
        }

        if ($input instanceof UserMessage) {
            if ($this->thread->turns !== [] && !$this->thread->lastTurn()->isSettled()) {
                $turn = $this->thread->lastTurn();
                $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_SUPERSEDED, body: 'Superseded by a new user message.');
                $turn->close($signal, $this->clock->now());
                $turn->interrupt = null;
                $this->threads->commit($this->thread);
            }
            $turnId = Strings::uuid();
            $step = Completion::of($input);
            // Turn opens with its first completion — no empty window for a hook to observe.
            $turn = Turn::of($turnId, model: $delivery->envelope->model, effort: $delivery->envelope->effort, completions: [$step]);
            $turn->openedAt = $this->clock->now();
            // Extension point runs before the turn joins the thread. History folds here; this turn does not.
            $this->extensions->beforeTurn($this, $turn);
            $this->thread->turns[] = $turn;

            return $this->executeDelivery($delivery);
        }

        if ($input instanceof TaskFinishSignal) {
            $step = Completion::of($input);
            $turn = Turn::of(Strings::uuid(), completions: [$step], model: $delivery->envelope->model, effort: $delivery->envelope->effort);
            $turn->openedAt = $this->clock->now();
            $this->extensions->beforeTurn($this, $turn);
            $this->thread->turns[] = $turn;

            return $this->executeDelivery($delivery);
        }

        return null;
    }

    protected function executeDelivery(Delivery $delivery): ?Throwable
    {
        $error = null;
        try {
            $error = $this->loop->drive($this, $delivery, $this->profile->maxSteps);
            if ($delivery->envelope->onSettled !== null) {
                ($delivery->envelope->onSettled)($this, $error ?? $this->thread->lastTurn());
            }

            return $error;
        } finally {
            $turn = $this->thread->lastTurn();
            // Sealed or suspended — extensions see the turn whichever way it landed.
            $this->extensions->afterTurn($this, $turn);
            $this->eventDispatcher->dispatch(new DeliverySettled($this, $turn));
        }
    }
}
