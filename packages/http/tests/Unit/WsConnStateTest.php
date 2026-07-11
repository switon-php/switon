<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Switon\Core\Runtime;
use Switon\Http\RequestContext;
use Switon\Http\Server\Adapter\WsConnState;
use Swoole\Coroutine;

class WsConnStateTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setCoroutineEnabled(false);
    }

    protected function tearDown(): void
    {
        Runtime::setCoroutineEnabled(false);
    }

    public function testWaitsAreNoOpsWhenCoroutineModeIsOff(): void
    {
        $state = new WsConnState();

        $state->waitForOpenMessage();
        $this->assertFalse($state->waitForOpenClose());
        $this->assertFalse($state->openReady);
        $this->assertSame([], $state->pendingMessageCids);
        $this->assertNull($state->pendingCloseCid);
    }

    public function testFinishOpenMarksReadyWithoutPendingWaiters(): void
    {
        $state = new WsConnState();

        $state->finishOpen();

        $this->assertTrue($state->openReady);
        $this->assertSame([], $state->pendingMessageCids);
        $this->assertNull($state->pendingCloseCid);
        $this->assertTrue($state->waitForOpenClose());
        $state->waitForOpenMessage();
        $this->assertSame([], $state->pendingMessageCids);
    }

    public function testRestoreOutsideACoroutineLeavesSnapshotsUntouched(): void
    {
        $state = new WsConnState();
        $requestContext = new RequestContext();
        $state->contextSnapshots = [spl_object_id($requestContext) => $requestContext];

        $state->restoreConnContexts();

        $this->assertSame($requestContext, $state->contextSnapshots[spl_object_id($requestContext)]);
    }

    public function testRestoreDoesNothingWithoutSnapshots(): void
    {
        $state = new WsConnState();

        $state->restoreConnContexts();

        $this->assertSame([], $state->contextSnapshots);
    }

    #[RequiresPhpExtension('swoole')]
    public function testFinishOpenResumesWaitersAndRestoresConnSnapshots(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $state = new WsConnState();
                $messageResumed = false;
                $closeReady = false;

                Coroutine::create(function () use ($state, &$messageResumed): void {
                    $state->waitForOpenMessage();
                    $messageResumed = true;
                });
                Coroutine::create(function () use ($state, &$closeReady): void {
                    $closeReady = $state->waitForOpenClose();
                });

                $requestContext = new RequestContext();
                Coroutine::getContext()[spl_object_id($requestContext)] = $requestContext;

                $state->finishOpen();

                $this->assertTrue($messageResumed);
                $this->assertTrue($closeReady);
                $this->assertTrue($state->openReady);
                $this->assertSame([], $state->pendingMessageCids);
                $this->assertNull($state->pendingCloseCid);
                $this->assertSame($requestContext, $state->contextSnapshots[spl_object_id($requestContext)]);

                $state->restoreConnContexts();
                $this->assertSame($requestContext, Coroutine::getContext()[spl_object_id($requestContext)]);
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }
}
