<?php

declare(strict_types=1);

namespace Switon\Langfuse;

use Closure;
use Psr\Log\LoggerInterface;
use Switon\Core\Runtime;
use Switon\Langfuse\Observability\Span;
use Switon\Sync\Channel;
use Switon\Sync\CloseableInterface;
use Switon\Sync\Exception\ChannelClosedException;
use Switon\Sync\Exception\ChannelFullException;
use Switon\Sync\TerminationInterface;
use Swoole\Coroutine as SwooleCoroutine;
use Throwable;

use function array_values;
use function extension_loaded;
use function is_array;
use function max;
use function microtime;

/**
 * Serializes exports through one consumer coroutine.
 *
 * Use when the app runs under Swoole with curl hooks: an export yields instead of blocking, so
 * overlapping writes for one trace reach Langfuse out of order and it silently drops observations.
 *
 * Guidance: producers only push; one coroutine drains in FIFO order, which is what keeps a
 * multi-step agent trace complete.
 *
 * Road-signs:
 * - Coroutine mode: {@see self::push()} enqueues and returns, the worker sends
 * - Idle: the worker exits after {@see self::IDLE_SECONDS} and the next push starts a new one
 * - Blocking mode: {@see self::push()} sends inline, no worker, no queue
 * - Shutdown: {@see self::close()} stops intake and lets the worker drain, then it exits
 */
class ExportQueue implements CloseableInterface
{
    /** Seconds a worker waits for one item before yielding; keeps Swoole from declaring a deadlock. */
    protected const float POLL_SECONDS = 0.05;

    /**
     * Seconds an idle worker lingers before exiting.
     *
     * A permanently live consumer coroutine makes `Swoole\Coroutine\run()` never return; this window
     * keeps bursts together while still letting the scheduler drain.
     */
    protected const float IDLE_SECONDS = 1.0;

    protected ?Channel $queue = null;

    protected bool $workerStarted = false;

    protected bool $stopping = false;

    protected bool $warnedFull = false;

    /**
     * Accepted batches not yet sent, including the one the worker is sending now.
     *
     * Counting these instead of queue length is what lets `flush()` know the last export really left.
     */
    protected int $accepted = 0;

    /**
     * @param Closure(list<Span>): void $sender the actual HTTP export
     */
    public function __construct(
        protected Closure $sender,
        protected TerminationInterface $termination,
        protected int $capacity = 256,
        protected ?LoggerInterface $logger = null,
    ) {
    }

    /** Stop accepting exports; the worker drains what it queued and exits. The channel stays open so nothing accepted is dropped. */
    public function close(): void
    {
        $this->stopping = true;
    }

    public function isClosed(): bool
    {
        return $this->stopping;
    }

    /**
     * @param list<Span> $spans
     */
    public function push(array $spans): void
    {
        if ($spans === [] || $this->stopping) {
            return;
        }

        if (!$this->usesCoroutine() || !self::inCoroutine()) {
            ($this->sender)($spans);

            return;
        }

        $this->startWorker();

        try {
            $this->queue()->push($spans);
            $this->accepted++;
        } catch (ChannelFullException) {
            // Telemetry must never reach the caller: backpressure would stall the agent loop, so the
            // batch is dropped and logged once per process instead of thrown out of a listener.
            if (!$this->warnedFull) {
                $this->warnedFull = true;
                $this->logger?->warning(
                    'Langfuse export queue is full at {capacity}; dropping export until it drains.',
                    ['capacity' => $this->capacity],
                );
            }
        } catch (ChannelClosedException) {
            // Shutting down; the queue will not accept more exports.
        }
    }

    /** Coroutine mode needs the runtime flag, the extension, and a positive capacity (0 means inline). */
    protected function usesCoroutine(): bool
    {
        return $this->capacity > 0
            && Runtime::isCoroutineEnabled()
            && extension_loaded('swoole');
    }

    /**
     * Wait up to `$timeout` seconds until every accepted batch, including the one in flight, is sent.
     *
     * Counts accepted batches rather than queue length: the worker pops a batch before its request
     * runs, so a length check would return while the last export is still sending.
     */
    public function flush(float $timeout = 5.0): void
    {
        if (!$this->workerStarted) {
            return;
        }

        // Outside a coroutine no worker can run, so waiting would only spin until the deadline.
        if (!self::inCoroutine()) {
            return;
        }

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($this->accepted <= 0) {
                return;
            }
            SwooleCoroutine::sleep(0.01);
        }
    }

    protected function queue(): Channel
    {
        return $this->queue ??= new Channel($this->capacity);
    }

    /** Whether the caller can suspend; Swoole coroutine APIs fatal outside one. */
    protected static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && SwooleCoroutine::getCid() >= 0;
    }

    protected function startWorker(): void
    {
        if ($this->workerStarted) {
            return;
        }

        $this->workerStarted = true;
        $queue = $this->queue();
        $sender = $this->sender;

        // Registers this queue, not the channel: closing the channel would drop queued spans, while
        // close() here only stops intake so the worker can drain before exiting.
        $this->termination->register($this);

        SwooleCoroutine::create(function () use ($queue, $sender): void {
            $idleSince = microtime(true);

            while (true) {
                $draining = $this->stopping;

                try {
                    // Timed pop, not an indefinite block: a permanently sleeping worker makes Swoole
                    // report "all coroutines are asleep" as a deadlock during tests and shutdown.
                    $spans = $queue->pop(self::POLL_SECONDS);
                } catch (ChannelClosedException) {
                    $this->stopWorker();

                    return;
                } catch (Throwable) {
                    $this->stopWorker();

                    return;
                }

                if ($spans === false) {
                    // Empty this interval: shut down on request, or hand the scheduler back once the
                    // queue has stayed idle — a live consumer would keep `Coroutine\run()` waiting.
                    if ($draining || microtime(true) - $idleSince >= self::IDLE_SECONDS) {
                        $this->stopWorker();

                        return;
                    }

                    continue;
                }

                if ($spans === null) {
                    $this->stopWorker();

                    return;
                }

                if (!is_array($spans)) {
                    continue;
                }

                /** @var list<Span> $batch */
                $batch = array_values($spans);
                try {
                    $sender($batch);
                } finally {
                    $this->accepted--;
                    // Sending takes non-zero time; measure the idle window from the last delivery so a
                    // steady stream of bursts keeps one worker (and its FIFO order) alive.
                    $idleSince = microtime(true);
                }
            }
        });
    }

    /** Let the next {@see self::push()} start a fresh worker; called only from the worker itself. */
    protected function stopWorker(): void
    {
        $this->workerStarted = false;
    }
}
