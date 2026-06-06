<?php

declare(strict_types=1);

namespace Switon\Rpc\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\RandomInterface;
use Switon\Executor\ExecutorInterface;
use Switon\Invoking\InvokerInterface;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;
use Switon\Rpc\Event\RpcCalling;
use Switon\Rpc\Event\RpcDaemonFailed;
use Switon\Rpc\Event\RpcReceived;
use Switon\Rpc\Exception\RpcMethodException;
use Switon\Rpc\Exception\RpcProtocolException;
use Switon\Rpc\Exception\RpcRemoteException;
use Switon\Rpc\Exception\RpcTimeoutException;
use Switon\Rpc\Rpc;
use Switon\Rpc\Tests\TestCase;
use Throwable;

class RpcTest extends TestCase
{
    public function testCallPushesRequestDispatchesEventAndReturnsResult(): void
    {
        $transport = new FakeQueueTransport();
        $reply = QueueEnvelope::of(['result' => 3]);
        $reply->correlation_id = '1';
        $transport->pullResponses = [$reply];

        $events = new FakeRpcEventDispatcher();
        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events);

        $result = $rpc->call(TestRpcService::class . '::sum', [1, 2], 3.5);

        $this->assertSame(3, $result);
        $this->assertCount(1, $transport->pushes);
        $this->assertSame('rpc', $transport->pushes[0]['queue']);
        $replyQueue = $this->getRpcProperty($rpc, 'replyQueue');
        $this->assertCount(1, $transport->pullCalls);
        $this->assertSame($replyQueue, $transport->pullCalls[0]['queues']);
        $this->assertIsFloat($transport->pullCalls[0]['timeout']);
        $this->assertGreaterThan(0.0, $transport->pullCalls[0]['timeout']);
        $this->assertSame(
            ['method' => TestRpcService::class . '::sum', 'params' => [1, 2]],
            $transport->pushes[0]['envelope']->body
        );
        $this->assertSame('1', $transport->pushes[0]['envelope']->correlation_id);
        $this->assertSame('.rpc.reply.reply-uuid', $transport->pushes[0]['envelope']->reply_to);
        $this->assertCount(1, $events->events);
        $this->assertInstanceOf(RpcCalling::class, $events->events[0]);
        $this->assertSame(TestRpcService::class . '::sum', $events->events[0]->method);
        $this->assertSame([1, 2], $events->events[0]->params);
        $this->assertSame('1', $events->events[0]->correlationId);
        $this->assertSame(3.5, $events->events[0]->timeout);
        $this->assertSame([], $this->getRpcProperty($rpc, 'replies'));
    }

    public function testCallPushesToConfiguredRpcQueue(): void
    {
        $transport = new FakeQueueTransport();
        $reply = QueueEnvelope::of(['result' => 1]);
        $reply->correlation_id = '1';
        $transport->pullResponses = [$reply];

        $rpc = $this->makeRpc(transport: $transport, queue: 'rpc-priority');

        $this->assertSame(1, $rpc->call(TestRpcService::class . '::sum'));
        $this->assertSame('rpc-priority', $transport->pushes[0]['queue']);
    }

    public function testCallAssignsMonotonicCorrelationIdsAcrossSequentialCalls(): void
    {
        $transport = new FakeQueueTransport();
        $r1 = QueueEnvelope::of(['result' => 'a']);
        $r1->correlation_id = '1';
        $r2 = QueueEnvelope::of(['result' => 'b']);
        $r2->correlation_id = '2';
        $transport->pullResponses = [$r1, $r2];

        $rpc = $this->makeRpc(transport: $transport);

        $this->assertSame('a', $rpc->call('M::a'));
        $this->assertSame('b', $rpc->call('M::b'));

        $this->assertSame('1', $transport->pushes[0]['envelope']->correlation_id);
        $this->assertSame('2', $transport->pushes[1]['envelope']->correlation_id);
    }

    public function testCallCompletesAfterPullReturnsUnmatchedThenMatchingReply(): void
    {
        $transport = new FakeQueueTransport();
        $stray = QueueEnvelope::of(['result' => 'orphan']);
        $stray->correlation_id = '99';
        $mine = QueueEnvelope::of(['result' => 'ok']);
        $mine->correlation_id = '1';
        $transport->pullResponses = [$stray, $mine];

        $rpc = $this->makeRpc(transport: $transport);

        $this->assertSame('ok', $rpc->call('Svc::x', [], 30.0));
        $this->assertCount(2, $transport->pullCalls);
    }

    public function testCallBuffersReplyForAnotherPendingCorrelation(): void
    {
        $transport = new FakeQueueTransport();

        $otherReply = QueueEnvelope::of(['result' => 'other']);
        $otherReply->correlation_id = '2';

        $reply = QueueEnvelope::of(['result' => 'mine']);
        $reply->correlation_id = '1';

        $transport->pullResponses = [$otherReply, $reply];

        $rpc = $this->makeRpc(transport: $transport);
        $this->setRpcProperty($rpc, 'replies', ['2' => $rpc]);

        $result = $rpc->call(TestRpcService::class . '::sum');

        $this->assertSame('mine', $result);
        $this->assertSame($otherReply, $this->getRpcProperty($rpc, 'replies')['2']);
    }

    public function testCallThrowsRemoteExceptionForErrorReply(): void
    {
        $transport = new FakeQueueTransport();
        $reply = QueueEnvelope::of([
            'error' => [
                'class' => RpcMethodException::class,
                'message' => 'bad method',
                'code' => 42,
            ],
        ]);
        $reply->correlation_id = '1';
        $transport->pullResponses = [$reply];

        $rpc = $this->makeRpc(transport: $transport);

        try {
            $rpc->call('bad::method');
            $this->fail('Expected RpcRemoteException was not thrown.');
        } catch (RpcRemoteException $e) {
            $this->assertSame(42, $e->getCode());
            $this->assertStringContainsString('bad::method', $e->getMessage());
            $this->assertStringContainsString('bad method', $e->getMessage());
            $this->assertStringContainsString(RpcMethodException::class, $e->getMessage());
        }

        $this->assertSame([], $this->getRpcProperty($rpc, 'replies'));
    }

    public function testCallTimesOutAndClearsPendingReply(): void
    {
        $transport = new FakeQueueTransport();
        $transport->pullResponses = [null];

        $rpc = $this->makeRpc(transport: $transport);

        $this->expectException(RpcTimeoutException::class);

        try {
            $rpc->call(TestRpcService::class . '::sum', [], 0.000001);
        } finally {
            $this->assertSame([], $this->getRpcProperty($rpc, 'replies'));
        }
    }

    public function testCallTimeoutExceptionMessageContainsMethodAndTimeout(): void
    {
        $transport = new FakeQueueTransport();
        $transport->pullResponses = [null];

        $rpc = $this->makeRpc(transport: $transport);
        $method = 'Acme\\Jobs::handle';

        try {
            $rpc->call($method, [], 0.000001);
            $this->fail('Expected RpcTimeoutException was not thrown.');
        } catch (RpcTimeoutException $e) {
            $this->assertStringContainsString($method, $e->getMessage());
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertStringContainsString('seconds', $e->getMessage());
        }
    }

    public function testCallClearsPendingReplyWhenPushFails(): void
    {
        $transport = new FakeQueueTransport();
        $transport->pushException = RuntimeException::of('push failed');

        $rpc = $this->makeRpc(transport: $transport);

        $this->expectException(RuntimeException::class);

        try {
            $rpc->call(TestRpcService::class . '::sum');
        } finally {
            $this->assertSame([], $this->getRpcProperty($rpc, 'replies'));
        }
    }

    public function testCallStillDispatchesRpcCallingEventWhenPushFails(): void
    {
        $transport = new FakeQueueTransport();
        $transport->pushException = RuntimeException::of('push failed');
        $events = new FakeRpcEventDispatcher();

        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events);

        try {
            $rpc->call(TestRpcService::class . '::sum', [1, 2], 1.5);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            $this->assertCount(1, $events->events);
            $this->assertInstanceOf(RpcCalling::class, $events->events[0]);
            $this->assertSame(TestRpcService::class . '::sum', $events->events[0]->method);
            $this->assertSame([1, 2], $events->events[0]->params);
        }
    }

    public function testRunUsesMethodMappingAndRepliesWithResult(): void
    {
        $transport = new FakeQueueTransport();
        $service = new TestRpcService();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(TestRpcService::class)
            ->willReturn($service);

        $invoker = $this->createMock(InvokerInterface::class);
        $invoker->expects($this->once())
            ->method('invoke')
            ->with([$service, 'sum'], [1, 2])
            ->willReturn(3);

        $rpc = $this->makeRpc(
            transport: $transport,
            container: $container,
            invoker: $invoker,
            methods: ['sum' => TestRpcService::class . '::sum']
        );

        $request = QueueEnvelope::of(['method' => 'sum', 'params' => [1, 2]]);
        $request->correlation_id = '7';
        $request->reply_to = '.reply';

        $result = $rpc->run($request);

        $this->assertSame(3, $result);
        $this->assertCount(1, $transport->pushes);
        $this->assertSame('.reply', $transport->pushes[0]['queue']);
        $this->assertSame(['result' => 3], $transport->pushes[0]['envelope']->body);
        $this->assertSame('7', $transport->pushes[0]['envelope']->correlation_id);
    }

    public function testRunRepliesWithErrorWhenDispatchFails(): void
    {
        $transport = new FakeQueueTransport();
        $rpc = $this->makeRpc(transport: $transport);

        $request = QueueEnvelope::of(['method' => 'bad-method', 'params' => []]);
        $request->correlation_id = '9';
        $request->reply_to = '.reply';

        $result = $rpc->run($request);

        $this->assertNull($result);
        $this->assertCount(1, $transport->pushes);
        $this->assertSame('.reply', $transport->pushes[0]['queue']);
        $this->assertSame('9', $transport->pushes[0]['envelope']->correlation_id);
        $this->assertSame(RpcMethodException::class, $transport->pushes[0]['envelope']->body['error']['class']);
    }

    public function testCallThrowsProtocolExceptionWhenReplyHasNoResultNorError(): void
    {
        $transport = new FakeQueueTransport();
        $reply = QueueEnvelope::of(['payload' => 'ignored']);
        $reply->correlation_id = '1';
        $transport->pullResponses = [$reply];

        $rpc = $this->makeRpc(transport: $transport);

        $this->expectException(RpcProtocolException::class);

        try {
            $rpc->call(TestRpcService::class . '::sum');
        } finally {
            $this->assertSame([], $this->getRpcProperty($rpc, 'replies'));
        }
    }

    public function testCallThrowsProtocolExceptionWhenReplyErrorPayloadIsMalformed(): void
    {
        $transport = new FakeQueueTransport();
        $reply = QueueEnvelope::of([
            'error' => [
                'message' => 'missing class',
            ],
        ]);
        $reply->correlation_id = '1';
        $transport->pullResponses = [$reply];

        $rpc = $this->makeRpc(transport: $transport);

        $this->expectException(RpcProtocolException::class);

        try {
            $rpc->call(TestRpcService::class . '::sum');
        } finally {
            $this->assertSame([], $this->getRpcProperty($rpc, 'replies'));
        }
    }

    public function testCallRemoteExceptionUsesZeroWhenErrorCodeIsNotInteger(): void
    {
        $transport = new FakeQueueTransport();
        $reply = QueueEnvelope::of([
            'error' => [
                'class' => RpcMethodException::class,
                'message' => 'bad',
                'code' => 'not-int',
            ],
        ]);
        $reply->correlation_id = '1';
        $transport->pullResponses = [$reply];

        $rpc = $this->makeRpc(transport: $transport);

        try {
            $rpc->call('x::y');
            $this->fail('Expected RpcRemoteException was not thrown.');
        } catch (RpcRemoteException $e) {
            $this->assertSame(0, $e->getCode());
        }
    }

    public function testRunResolvesFullClassMethodWithoutAliasMapping(): void
    {
        $transport = new FakeQueueTransport();
        $service = new TestRpcService();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(TestRpcService::class)
            ->willReturn($service);

        $invoker = $this->createMock(InvokerInterface::class);
        $invoker->expects($this->once())
            ->method('invoke')
            ->with([$service, 'sum'], [5, 6])
            ->willReturn(11);

        $rpc = $this->makeRpc(
            transport: $transport,
            container: $container,
            invoker: $invoker,
            methods: []
        );

        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => [5, 6]]);
        $request->correlation_id = 'c1';
        $request->reply_to = '.out';

        $this->assertSame(11, $rpc->run($request));
        $this->assertSame(['result' => 11], $transport->pushes[0]['envelope']->body);
    }

    public function testRunPushesSuccessEnvelopeWhenResultIsNull(): void
    {
        $transport = new FakeQueueTransport();
        $service = new TestRpcService();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($service);

        $invoker = $this->createStub(InvokerInterface::class);
        $invoker->method('invoke')->willReturn(null);

        $rpc = $this->makeRpc(
            transport: $transport,
            container: $container,
            invoker: $invoker,
            methods: ['n' => TestRpcService::class . '::sum']
        );

        $request = QueueEnvelope::of(['method' => 'n', 'params' => []]);
        $request->correlation_id = 'n0';
        $request->reply_to = '.r';

        $this->assertNull($rpc->run($request));
        $this->assertArrayHasKey('result', $transport->pushes[0]['envelope']->body);
        $this->assertNull($transport->pushes[0]['envelope']->body['result']);
    }

    public function testRunErrorEnvelopeContainsThrowableCode(): void
    {
        $transport = new FakeQueueTransport();
        $service = new TestRpcService();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($service);

        $invoker = $this->createStub(InvokerInterface::class);
        $invoker->method('invoke')->willThrowException(RuntimeException::of('x', [], 77));

        $rpc = $this->makeRpc(transport: $transport, container: $container, invoker: $invoker);

        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => [0, 0]]);
        $request->correlation_id = 'c';
        $request->reply_to = '.e';

        $this->assertNull($rpc->run($request));
        $this->assertSame(77, $transport->pushes[0]['envelope']->body['error']['code']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidRpcMethodBodyProvider(): iterable
    {
        yield 'missing separator' => ['NoDoubleColon'];
        yield 'empty class segment' => ['::sum'];
        yield 'empty method segment' => [TestRpcService::class . '::'];
    }

    #[DataProvider('invalidRpcMethodBodyProvider')]
    public function testRunRepliesWithRpcMethodErrorForInvalidMethodFormat(string $methodField): void
    {
        $transport = new FakeQueueTransport();
        $rpc = $this->makeRpc(transport: $transport);

        $request = QueueEnvelope::of(['method' => $methodField, 'params' => []]);
        $request->correlation_id = 'fmt';
        $request->reply_to = '.reply';

        $this->assertNull($rpc->run($request));
        $this->assertSame(RpcMethodException::class, $transport->pushes[0]['envelope']->body['error']['class']);
    }

    public function testRunContainerFailureBecomesErrorReply(): void
    {
        $transport = new FakeQueueTransport();
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willThrowException(RuntimeException::of('missing binding'));

        $invoker = $this->createStub(InvokerInterface::class);

        $rpc = $this->makeRpc(transport: $transport, container: $container, invoker: $invoker);

        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => [1, 1]]);
        $request->correlation_id = 'cx';
        $request->reply_to = '.reply';

        $this->assertNull($rpc->run($request));
        $this->assertSame(RuntimeException::class, $transport->pushes[0]['envelope']->body['error']['class']);
        $this->assertSame('missing binding', $transport->pushes[0]['envelope']->body['error']['message']);
    }

    public function testRunRepliesWithErrorWhenInvokeThrows(): void
    {
        $transport = new FakeQueueTransport();
        $service = new TestRpcService();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($service);

        $invoker = $this->createStub(InvokerInterface::class);
        $invoker->method('invoke')->willThrowException(RuntimeException::of('invoke failed'));

        $rpc = $this->makeRpc(
            transport: $transport,
            container: $container,
            invoker: $invoker,
            methods: ['add' => TestRpcService::class . '::sum']
        );

        $request = QueueEnvelope::of(['method' => 'add', 'params' => [1, 2]]);
        $request->correlation_id = 'e1';
        $request->reply_to = '.err';

        $this->assertNull($rpc->run($request));
        $body = $transport->pushes[0]['envelope']->body;
        $this->assertSame(RuntimeException::class, $body['error']['class']);
        $this->assertSame('invoke failed', $body['error']['message']);
    }

    public function testDaemonFailedUsesEmptyMethodWhenTransportPullFailsBeforeEnvelope(): void
    {
        $transport = new FakeQueueTransport();
        $transport->pullResponses = [RuntimeException::of('broker unavailable')];

        $events = new FakeRpcEventDispatcher();
        $events->throwOnClass = RpcDaemonFailed::class;
        $events->throwable = new StopDaemonLoop();

        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events);

        try {
            $rpc->daemon();
            $this->fail('Expected daemon loop to be interrupted.');
        } catch (StopDaemonLoop) {
            $this->assertCount(1, $events->events);
            $failed = $events->events[0];
            $this->assertInstanceOf(RpcDaemonFailed::class, $failed);
            $this->assertSame('', $failed->method);
            $this->assertSame('broker unavailable', $failed->error->getMessage());
        }
    }

    public function testDaemonRepliesWithErrorWhenReceivedEventDispatchFails(): void
    {
        $transport = new FakeQueueTransport();
        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => [1, 2]]);
        $request->correlation_id = 'rx';
        $request->reply_to = '.reply';
        $transport->pullResponses = [$request];

        $events = new FakeRpcEventDispatcher();
        $events->throwablesByClass[RpcReceived::class] = RuntimeException::of('receive listener failed');
        $events->throwablesByClass[RpcDaemonFailed::class] = new StopDaemonLoop();

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->never())->method('submit');

        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events, executor: $executor);

        try {
            $rpc->daemon();
            $this->fail('Expected daemon loop to be interrupted.');
        } catch (StopDaemonLoop) {
            $this->assertCount(1, $transport->pushes);
            $this->assertSame('.reply', $transport->pushes[0]['queue']);
            $this->assertSame('rx', $transport->pushes[0]['envelope']->correlation_id);
            $this->assertSame(RuntimeException::class, $transport->pushes[0]['envelope']->body['error']['class']);
            $this->assertSame('receive listener failed', $transport->pushes[0]['envelope']->body['error']['message']);
            $this->assertCount(2, $events->events);
            $this->assertInstanceOf(RpcReceived::class, $events->events[0]);
            $this->assertInstanceOf(RpcDaemonFailed::class, $events->events[1]);
        }
    }

    public function testDaemonRepliesWithErrorWhenExecutorSubmitFails(): void
    {
        $transport = new FakeQueueTransport();
        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => [1, 2]]);
        $request->correlation_id = 'sx';
        $request->reply_to = '.submit';
        $transport->pullResponses = [$request];

        $events = new FakeRpcEventDispatcher();
        $events->throwablesByClass[RpcDaemonFailed::class] = new StopDaemonLoop();

        $executor = $this->createStub(ExecutorInterface::class);
        $executor->method('submit')->willThrowException(RuntimeException::of('submit failed'));

        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events, executor: $executor);

        try {
            $rpc->daemon();
            $this->fail('Expected daemon loop to be interrupted.');
        } catch (StopDaemonLoop) {
            $this->assertCount(1, $transport->pushes);
            $this->assertSame('.submit', $transport->pushes[0]['queue']);
            $this->assertSame('sx', $transport->pushes[0]['envelope']->correlation_id);
            $this->assertSame(RuntimeException::class, $transport->pushes[0]['envelope']->body['error']['class']);
            $this->assertSame('submit failed', $transport->pushes[0]['envelope']->body['error']['message']);
            $this->assertCount(2, $events->events);
            $this->assertInstanceOf(RpcReceived::class, $events->events[0]);
            $this->assertInstanceOf(RpcDaemonFailed::class, $events->events[1]);
        }
    }

    public function testDaemonSkipsReplyWhenCorrelationIdIsMissing(): void
    {
        $transport = new FakeQueueTransport();
        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => []]);
        $request->reply_to = '.reply';
        $transport->pullResponses = [$request];

        $events = new FakeRpcEventDispatcher();
        $events->throwablesByClass[RpcReceived::class] = RuntimeException::of('receive failed');
        $events->throwablesByClass[RpcDaemonFailed::class] = new StopDaemonLoop();

        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events);

        try {
            $rpc->daemon();
            $this->fail('Expected daemon loop to be interrupted.');
        } catch (StopDaemonLoop) {
            $this->assertCount(0, $transport->pushes);
            $this->assertCount(2, $events->events);
            $this->assertInstanceOf(RpcReceived::class, $events->events[0]);
            $this->assertInstanceOf(RpcDaemonFailed::class, $events->events[1]);
        }
    }

    public function testDaemonPullsFromConfiguredQueueAndPassesConcurrency(): void
    {
        $transport = new FakeQueueTransport();
        $request = QueueEnvelope::of(['method' => 'm', 'params' => []]);
        $request->correlation_id = '1';
        $request->reply_to = '.r';
        $transport->pullResponses = [$request, RuntimeException::of('stop')];

        $events = new FakeRpcEventDispatcher();
        $events->throwOnClass = RpcDaemonFailed::class;
        $events->throwable = new StopDaemonLoop();

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())
            ->method('submit')
            ->with(Rpc::class, $request, 64);

        $rpc = $this->makeRpc(
            transport: $transport,
            eventDispatcher: $events,
            executor: $executor,
            concurrency: 64,
            queue: 'rpc-worker'
        );

        try {
            $rpc->daemon();
            $this->fail('Expected daemon loop to be interrupted.');
        } catch (StopDaemonLoop) {
            $this->assertSame('rpc-worker', $transport->pullCalls[0]['queues']);
        }
    }

    public function testDaemonSkipsNullPullBeforeHandlingRequest(): void
    {
        $transport = new FakeQueueTransport();
        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => []]);
        $request->correlation_id = '3';
        $request->reply_to = '.reply';
        $transport->pullResponses = [null, $request, RuntimeException::of('stop daemon')];

        $events = new FakeRpcEventDispatcher();
        $events->throwOnClass = RpcDaemonFailed::class;
        $events->throwable = new StopDaemonLoop();

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())
            ->method('submit')
            ->with(Rpc::class, $request, 8192);

        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events, executor: $executor);

        try {
            $rpc->daemon();
            $this->fail('Expected daemon loop to be interrupted.');
        } catch (StopDaemonLoop) {
            $this->assertGreaterThanOrEqual(2, count($transport->pullCalls));
            $this->assertSame('rpc', $transport->pullCalls[0]['queues']);
            $this->assertSame('rpc', $transport->pullCalls[1]['queues']);
            $this->assertSame([], $transport->pullResponses);
        }
    }

    public function testDaemonDispatchesReceivedEventAndSubmitsEnvelope(): void
    {
        $transport = new FakeQueueTransport();
        $request = QueueEnvelope::of(['method' => TestRpcService::class . '::sum', 'params' => [1, 2]]);
        $request->correlation_id = '3';
        $request->reply_to = '.reply';
        $transport->pullResponses = [$request, RuntimeException::of('stop daemon')];

        $events = new FakeRpcEventDispatcher();
        $events->throwOnClass = RpcDaemonFailed::class;
        $events->throwable = new StopDaemonLoop();

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())
            ->method('submit')
            ->with(Rpc::class, $request, 8192);

        $rpc = $this->makeRpc(transport: $transport, eventDispatcher: $events, executor: $executor);

        try {
            $rpc->daemon();
            $this->fail('Expected daemon loop to be interrupted.');
        } catch (StopDaemonLoop) {
            $this->assertCount(2, $events->events);
            $this->assertInstanceOf(RpcReceived::class, $events->events[0]);
            $this->assertSame(TestRpcService::class . '::sum', $events->events[0]->method);
            $this->assertSame([1, 2], $events->events[0]->params);
            $this->assertInstanceOf(RpcDaemonFailed::class, $events->events[1]);
        }
    }

    public function testConstructorDerivesReplyQueueFromRandomUuid(): void
    {
        $random = $this->createStub(RandomInterface::class);
        $random->method('uuid')->willReturn('fixed-uuid');

        $rpc = $this->makeRpc(random: $random);

        $this->assertSame('.rpc.reply.fixed-uuid', $this->getRpcProperty($rpc, 'replyQueue'));
    }

    protected function makeRpc(
        ?RandomInterface          $random = null,
        ?QueueInterface           $transport = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?ContainerInterface       $container = null,
        ?InvokerInterface         $invoker = null,
        ?ExecutorInterface        $executor = null,
        array                     $methods = [],
        int                       $concurrency = 8192,
        string                    $queue = 'rpc',
    ): Rpc {
        if ($random === null) {
            $random = $this->createStub(RandomInterface::class);
            $random->method('uuid')->willReturn('reply-uuid');
        }

        return $this->make(Rpc::class, [
            'random' => $random,
            'transport' => $transport ?? new FakeQueueTransport(),
            'eventDispatcher' => $eventDispatcher ?? new FakeRpcEventDispatcher(),
            'container' => $container ?? $this->createStub(ContainerInterface::class),
            'invoker' => $invoker ?? $this->createStub(InvokerInterface::class),
            'executor' => $executor ?? $this->createStub(ExecutorInterface::class),
            'methods' => $methods,
            'concurrency' => $concurrency,
            'queue' => $queue,
        ]);
    }

    protected function getRpcProperty(Rpc $rpc, string $property): mixed
    {
        return (Closure::bind(static function (Rpc $rpc, string $property): mixed {
            return $rpc->{$property};
        }, null, Rpc::class))($rpc, $property);
    }

    protected function setRpcProperty(Rpc $rpc, string $property, mixed $value): void
    {
        (Closure::bind(static function (Rpc $rpc, string $property, mixed $value): void {
            $rpc->{$property} = $value;
        }, null, Rpc::class))($rpc, $property, $value);
    }
}

class FakeQueueTransport implements QueueInterface
{
    /** @var list<array{queue: string, envelope: QueueEnvelope, delay: int}> */
    public array $pushes = [];

    /** @var list<QueueEnvelope|Throwable|null> */
    public array $pullResponses = [];

    /** @var list<array{queues: string|array, timeout: float}> */
    public array $pullCalls = [];

    public ?Throwable $pushException = null;

    public function push(string $queue, QueueEnvelope $envelope, int $delay = 0): string
    {
        if ($this->pushException !== null) {
            throw $this->pushException;
        }

        $this->pushes[] = ['queue' => $queue, 'envelope' => $envelope, 'delay' => $delay];

        return 'message-' . count($this->pushes);
    }

    public function pull(string|array $queues, float $timeout = 0): ?QueueEnvelope
    {
        $this->pullCalls[] = ['queues' => $queues, 'timeout' => $timeout];
        $next = array_shift($this->pullResponses);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function migrateDue(string|array $queues, int $limit = 100): int
    {
        return 0;
    }

    public function stats(string $queue): array
    {
        return [];
    }

    public function consume(QueueEnvelope $envelope): void
    {
    }
}

class FakeRpcEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public ?string $throwOnClass = null;
    public ?Throwable $throwable = null;

    /** @var array<class-string, Throwable> */
    public array $throwablesByClass = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        if (isset($this->throwablesByClass[$event::class])) {
            throw $this->throwablesByClass[$event::class];
        }

        if ($this->throwOnClass !== null && $event instanceof $this->throwOnClass) {
            throw $this->throwable ?? new StopDaemonLoop();
        }

        return $event;
    }
}

class StopDaemonLoop extends \RuntimeException
{
}

class TestRpcService
{
    public function sum(int $a, int $b): int
    {
        return $a + $b;
    }
}
