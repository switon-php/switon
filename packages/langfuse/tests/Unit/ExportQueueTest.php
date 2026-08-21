<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Runtime;
use Switon\Langfuse\ExportQueue;
use Switon\Langfuse\Observability\Span;
use Switon\Langfuse\Otlp;
use Switon\Sync\Termination;
use Swoole\Coroutine as SwooleCoroutine;

#[CoversClass(ExportQueue::class)]
class ExportQueueTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required for queue tests');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        Runtime::setCoroutineEnabled(false);

        parent::tearDown();
    }

    public function testTerminationDrainsQueuedExportsBeforeWorkerExits(): void
    {
        $sent = [];
        $termination = new Termination();

        SwooleCoroutine\run(function () use (&$sent, $termination): void {
            Runtime::setCoroutineEnabled(true);

            $queue = new ExportQueue(
                static function (array $spans) use (&$sent): void {
                    $sent[] = $spans[0]->name;
                },
                $termination,
            );

            foreach (['step-1', 'step-2', 'step-3'] as $name) {
                $queue->push([new Span($name, Otlp::traceId('trace-1'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);
            }

            // Termination must let the worker finish what was already accepted, then stop it.
            $termination->terminate();
            $queue->flush(5.0);

            SwooleCoroutine::sleep(0.3);

            $this->assertSame(['step-1', 'step-2', 'step-3'], $sent);
            $this->assertTrue($queue->isClosed());
        });
    }

    public function testPushingAfterCloseIsIgnored(): void
    {
        $sent = [];
        $termination = new Termination();

        SwooleCoroutine\run(function () use (&$sent, $termination): void {
            Runtime::setCoroutineEnabled(true);

            $queue = new ExportQueue(
                static function (array $spans) use (&$sent): void {
                    $sent[] = $spans[0]->name;
                },
                $termination,
            );

            $queue->push([new Span('step-1', Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);
            $queue->flush(5.0);
            $queue->close();
            $queue->push([new Span('step-2', Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);

            SwooleCoroutine::sleep(0.3);

            $this->assertSame(['step-1'], $sent);
        });
    }

    public function testBlockingModeSendsWithoutWorker(): void
    {
        Runtime::setCoroutineEnabled(false);

        $sent = [];
        $queue = new ExportQueue(
            static function (array $spans) use (&$sent): void {
                $sent[] = $spans[0]->name;
            },
            new Termination(),
        );

        $queue->push([new Span('inline', Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);

        $this->assertSame(['inline'], $sent);
    }

    /**
     * `flush()` must wait for the batch already pop()ed, not just for an empty channel: otherwise the
     * caller returns while the last export is in flight and the process may exit without it.
     */
    public function testFlushWaitsForInFlightExport(): void
    {
        $sent = [];
        $termination = new Termination();

        SwooleCoroutine\run(function () use (&$sent, $termination): void {
            Runtime::setCoroutineEnabled(true);

            $queue = new ExportQueue(
                static function (array $spans) use (&$sent): void {
                    SwooleCoroutine::sleep(0.2);
                    $sent[] = $spans[0]->name;
                },
                $termination,
                8,
            );

            $queue->push([new Span('only', Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);
            // Let the worker pop the batch, so the channel is empty but the export is still running.
            SwooleCoroutine::sleep(0.05);

            $queue->flush(5.0);

            $this->assertSame(['only'], $sent);
        });
    }

    /**
     * An idle worker must exit on its own: a permanently live consumer keeps `Coroutine\run()` from
     * returning, so a `flush()`-only caller would hang at teardown.
     */
    public function testIdleWorkerExitsSoFlushOnlyCallerCanReturn(): void
    {
        $sent = [];
        $termination = new Termination();

        // No close(): the run has to end by itself once the queue goes idle.
        SwooleCoroutine\run(function () use (&$sent, $termination): void {
            Runtime::setCoroutineEnabled(true);

            $queue = new ExportQueue(
                static function (array $spans) use (&$sent): void {
                    $sent[] = $spans[0]->name;
                },
                $termination,
                8,
            );

            $queue->push([new Span('only', Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);
            $queue->flush(5.0);
        });

        $this->assertSame(['only'], $sent);
    }

    /** After an idle exit the next push starts a fresh worker and keeps sending. */
    public function testPushAfterIdleExitStartsNewWorker(): void
    {
        $sent = [];
        $termination = new Termination();

        SwooleCoroutine\run(function () use (&$sent, $termination): void {
            Runtime::setCoroutineEnabled(true);

            $queue = new ExportQueue(
                static function (array $spans) use (&$sent): void {
                    $sent[] = $spans[0]->name;
                },
                $termination,
                8,
            );

            $queue->push([new Span('first', Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);
            $queue->flush(5.0);

            // Outlive the idle window so the worker has exited before the second push.
            SwooleCoroutine::sleep(1.2);

            $queue->push([new Span('second', Otlp::traceId('t'), 'b1b2c3d4e5f60718', 1.0, 2.0)]);
            $queue->flush(5.0);
        });

        $this->assertSame(['first', 'second'], $sent);
    }

    /** A non-positive capacity means "export inline", not "a queue that rejects everything". */
    public function testZeroCapacitySendsInline(): void
    {
        Runtime::setCoroutineEnabled(true);

        $sent = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $queue = new ExportQueue(
            static function (array $spans) use (&$sent): void {
                $sent[] = $spans[0]->name;
            },
            new Termination(),
            0,
            $logger,
        );

        $queue->push([new Span('inline', Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);
        $queue->flush(1.0);

        $this->assertSame(['inline'], $sent);
    }

    /** A full queue drops the batch and warns once: telemetry must never throw into the caller. */
    public function testFullQueueDropsBatchAndLogsOnce(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $termination = new Termination();
        $closed = false;

        SwooleCoroutine\run(function () use ($logger, $termination, &$closed): void {
            Runtime::setCoroutineEnabled(true);

            $queue = new ExportQueue(
                static function (array $spans): void {
                    SwooleCoroutine::sleep(1.0);
                },
                $termination,
                1,
                $logger,
            );

            foreach (range(1, 6) as $n) {
                $queue->push([new Span('step-' . $n, Otlp::traceId('t'), 'a1b2c3d4e5f60718', 1.0, 2.0)]);
            }

            $queue->close();
            $closed = $queue->isClosed();
            $queue->flush(0.5);
        });

        $this->assertTrue($closed);
    }
}
