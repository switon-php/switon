<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Switon\Core\RandomInterface;
use Switon\Queue\Command\QueueCommand;
use Switon\Queue\JobDiscoveryInterface;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;
use Switon\Redis\ClientInterface;
use Switon\Queue\Tests\TestCase;
use Redis;
use RuntimeException;
use Throwable;

class QueueCommandTest extends TestCase
{
    public function testDefaultActionDelegatesToStatsWithJson(): void
    {
        $console = $this->createMock(ConsoleInterface::class);
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())->method('stats')->with('')->willReturn([
            'queue' => 'default',
            'ready' => 1,
            'delayed' => 2,
            'failed' => 3,
        ]);
        $console->expects($this->once())
            ->method('writeLn')
            ->with($this->callback(static function (string $line): bool {
                $decoded = Json::parse($line);
                return $decoded['queue'] === 'default' && $decoded['ready'] === 1;
            }));

        $command = $this->makeCommand(console: $console, queue: $queue);

        $this->assertSame(0, $command->defaultAction('', true));
    }

    public function testStatsActionRendersTableWhenJsonFalse(): void
    {
        $console = $this->createMock(ConsoleInterface::class);
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('stats')
            ->with('jobs')
            ->willReturn([
                'queue' => 'jobs',
                'ready' => 4,
                'delayed' => 5,
                'failed' => 6,
            ]);

        $console->expects($this->once())
            ->method('table')
            ->with(
                ['queue', 'ready', 'delayed', 'failed'],
                [['jobs', 4, 5, 6]],
                8
            );

        $command = $this->makeCommand(console: $console, queue: $queue);

        $this->assertSame(0, $command->statsAction('jobs', false));
    }

    public function testPushActionWritesQueuedIdOnSuccess(): void
    {
        $envelope = null;
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('push')
            ->with(
                'mail',
                $this->callback(static function (QueueEnvelope $e) use (&$envelope): bool {
                    $envelope = $e;
                    return $e->body === ['task' => 'send'];
                }),
                0
            )
            ->willReturn('msg-99');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('queued: msg-99');

        $command = $this->makeCommand(console: $console, queue: $queue);

        $body = Json::stringify(['task' => 'send']);
        $this->assertSame(0, $command->pushAction('mail', $body, 0));
        $this->assertNotNull($envelope);
    }

    public function testPushActionReturnsErrorWhenPayloadIsNotArray(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->never())->method('push');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('error')->willReturn(1);

        $command = $this->makeCommand(console: $console, queue: $queue);

        $this->assertSame(1, $command->pushAction('mail', '"scalar"', 0));
    }

    public function testPushActionReturnsErrorWhenJsonInvalid(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->never())->method('push');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with(
                'Push failed: {message}',
                $this->callback(static function (array $ctx): bool {
                    return isset($ctx['message']) && is_string($ctx['message']) && $ctx['message'] !== '';
                }),
                1
            )
            ->willReturn(1);

        $command = $this->makeCommand(console: $console, queue: $queue);

        $this->assertSame(1, $command->pushAction('mail', '{', 0));
    }

    public function testPushActionReturnsErrorWhenTransportPushFails(): void
    {
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('push')->willThrowException(new RuntimeException('redis down'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('Push failed: {message}', ['message' => 'redis down'], 1)
            ->willReturn(1);

        $command = $this->makeCommand(console: $console, queue: $queue);

        $this->assertSame(1, $command->pushAction('mail', '{}', 0));
    }

    public function testRunActionReturnsErrorWhenNoQueuesResolved(): void
    {
        $discovery = $this->createMock(JobDiscoveryInterface::class);
        $discovery->expects($this->once())->method('queues')->willReturn([]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('error')->willReturn(1);

        $command = $this->makeCommand(console: $console, jobDiscovery: $discovery);

        $this->assertSame(1, $command->runAction(''));
    }

    public function testRunActionPullsSingleQueueNameAsString(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('pull')
            ->with(
                'only-one',
                $this->callback(static fn (float $timeout): bool => abs($timeout - 1.0) < 0.001)
            )
            ->willReturn(null);

        $command = $this->makeCommand(queue: $queue);

        $this->assertSame(0, $command->runAction('only-one'));
    }

    public function testRunActionPullsCommaSeparatedNamesAsArray(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('pull')
            ->with(
                ['a', 'b'],
                $this->callback(static fn (float $timeout): bool => abs($timeout - 1.0) < 0.001)
            )
            ->willReturn(null);

        $command = $this->makeCommand(queue: $queue);

        $this->assertSame(0, $command->runAction(' a , b '));
    }

    public function testRunActionConsumesWhenEnvelopeReturned(): void
    {
        $envelope = QueueEnvelope::of(['x' => 1]);

        $queue = $this->createMock(QueueInterface::class);
        $queue->method('pull')->willReturn($envelope);
        $queue->expects($this->once())->method('consume')->with($envelope);

        $command = $this->makeCommand(queue: $queue);

        $this->assertSame(0, $command->runAction('q'));
    }

    public function testRunActionUsesDiscoveredQueuesWhenQueueArgumentIsEmpty(): void
    {
        $discovery = $this->createMock(JobDiscoveryInterface::class);
        $discovery->expects($this->once())->method('queues')->willReturn(['jobs', 'emails']);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('pull')
            ->with(
                ['jobs', 'emails'],
                $this->callback(static fn (float $timeout): bool => abs($timeout - 1.0) < 0.001)
            )
            ->willReturn(null);
        $queue->expects($this->never())->method('consume');

        $command = $this->makeCommand(queue: $queue, jobDiscovery: $discovery);

        $this->assertSame(0, $command->runAction(''));
    }

    public function testRunActionReturnsErrorWhenPullThrows(): void
    {
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('pull')->willThrowException(new RuntimeException('broken'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('Run failed: {message}', ['message' => 'broken'], 1)
            ->willReturn(1);

        $command = $this->makeCommand(console: $console, queue: $queue);

        $this->assertSame(1, $command->runAction('q'));
    }

    public function testMigrateDueActionValidatesLockTtl(): void
    {
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('error')->willReturn(1);

        $command = $this->makeCommand(console: $console);

        $this->assertSame(1, $command->migrateDueAction('jobs', lockTtl: 2));
    }

    public function testMigrateDueActionValidatesRenewEveryMs(): void
    {
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('error')->willReturn(1);

        $command = $this->makeCommand(console: $console);

        $this->assertSame(1, $command->migrateDueAction('jobs', renewEveryMs: 100));
    }

    public function testMigrateDueActionValidatesLimit(): void
    {
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('error')->willReturn(1);

        $command = $this->makeCommand(console: $console);

        $this->assertSame(1, $command->migrateDueAction('jobs', limit: 0));
    }

    public function testMigrateDueActionReturnsErrorWhenNoQueuesDiscovered(): void
    {
        $discovery = $this->createMock(JobDiscoveryInterface::class);
        $discovery->expects($this->once())->method('queues')->willReturn([]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('error')->willReturn(1);

        $command = $this->makeCommand(console: $console, jobDiscovery: $discovery);

        $this->assertSame(1, $command->migrateDueAction(''));
    }

    public function testWorkActionConsumesEnvelopeThenReportsWorkerError(): void
    {
        $envelope = QueueEnvelope::of(['id' => 1]);

        $queue = $this->createMock(QueueInterface::class);
        $pullCalls = 0;
        $queue->expects($this->exactly(2))
            ->method('pull')
            ->with(
                'jobs',
                $this->callback(static fn (float $timeout): bool => abs($timeout) < 0.001)
            )
            ->willReturnCallback(static function () use (&$pullCalls, $envelope): QueueEnvelope {
                $pullCalls++;
                if ($pullCalls === 1) {
                    return $envelope;
                }

                throw new RuntimeException('broken worker');
            });
        $queue->expects($this->once())->method('consume')->with($envelope);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('Worker error: {message}', ['message' => 'broken worker'], 1)
            ->willThrowException(new StopQueueCommandLoop('stop'));

        $command = $this->makeCommand(console: $console, queue: $queue);

        $this->expectException(StopQueueCommandLoop::class);
        $command->workAction('jobs', 0, 0);
    }

    public function testWorkActionReturnsErrorWhenNoQueuesDiscovered(): void
    {
        $discovery = $this->createMock(JobDiscoveryInterface::class);
        $discovery->expects($this->once())->method('queues')->willReturn([]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('error')->willReturn(1);

        $command = $this->makeCommand(console: $console, jobDiscovery: $discovery);

        $this->assertSame(1, $command->workAction(''));
    }

    public function testMigrateDueActionReleasesLeaderLockWhenMigrationThrows(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('migrateDue')
            ->with('jobs', 100)
            ->willThrowException(new RuntimeException('migrate broken'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('Delay worker error: {message}', ['message' => 'migrate broken'], 1)
            ->willThrowException(new StopQueueCommandLoop('stop'));

        $redis = new LeaderLockRedisStub();
        $redis->getReturn = 'test-uuid';

        $command = $this->makeLoopCommand(
            queue: $queue,
            console: $console,
            redis: $redis,
            nowMsValues: [1_000]
        );

        try {
            $command->migrateDueAction('jobs', sleepMs: 0, renewEveryMs: 10_000);
            $this->fail('Expected the loop to stop.');
        } catch (StopQueueCommandLoop) {
        }

        $this->assertSame(1, $redis->delCalls);
    }

    public function testMigrateDueActionRetriesWhenLeaderLockIsUnavailable(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('migrateDue')
            ->with('jobs', 100)
            ->willThrowException(new RuntimeException('retry stop'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('Delay worker error: {message}', ['message' => 'retry stop'], 1)
            ->willThrowException(new StopQueueCommandLoop('stop'));

        $command = $this->makeLoopCommand(
            queue: $queue,
            console: $console,
            acquireResults: [false, true]
        );

        try {
            $command->migrateDueAction('jobs', sleepMs: 0);
            $this->fail('Expected the loop to stop.');
        } catch (StopQueueCommandLoop) {
        }

        $this->assertSame(2, $command->acquireCalls);
        $this->assertSame(0, $command->renewCalls);
    }

    public function testMigrateDueActionRenewsLeaderLockWhenWindowElapsed(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $migrateCalls = 0;
        $queue->expects($this->exactly(2))
            ->method('migrateDue')
            ->with('jobs', 100)
            ->willReturnCallback(static function () use (&$migrateCalls): int {
                $migrateCalls++;
                if ($migrateCalls === 1) {
                    return 1;
                }

                throw new RuntimeException('migrate stop');
            });

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('Delay worker error: {message}', ['message' => 'migrate stop'], 1)
            ->willThrowException(new StopQueueCommandLoop('stop'));

        $command = $this->makeLoopCommand(
            queue: $queue,
            console: $console,
            acquireResults: [true],
            renewResults: [true],
            nowMsValues: [1_000, 6_000]
        );

        try {
            $command->migrateDueAction('jobs', sleepMs: 0, renewEveryMs: 4_000);
            $this->fail('Expected the loop to stop.');
        } catch (StopQueueCommandLoop) {
        }

        $this->assertSame(1, $command->acquireCalls);
        $this->assertSame(1, $command->renewCalls);
        $this->assertSame(2, $migrateCalls);
    }

    public function testMigrateDueActionRetriesWhenLeaderRenewalFails(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $migrateCalls = 0;
        $queue->expects($this->exactly(2))
            ->method('migrateDue')
            ->with('jobs', 100)
            ->willReturnCallback(static function () use (&$migrateCalls): int {
                $migrateCalls++;
                if ($migrateCalls === 1) {
                    return 1;
                }

                throw new RuntimeException('renew stop');
            });

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('Delay worker error: {message}', ['message' => 'renew stop'], 1)
            ->willThrowException(new StopQueueCommandLoop('stop'));

        $command = $this->makeLoopCommand(
            queue: $queue,
            console: $console,
            acquireResults: [true, true],
            renewResults: [false],
            nowMsValues: [1_000, 6_000]
        );

        try {
            $command->migrateDueAction('jobs', sleepMs: 0, renewEveryMs: 4_000);
            $this->fail('Expected the loop to stop.');
        } catch (StopQueueCommandLoop) {
        }

        $this->assertSame(2, $command->acquireCalls);
        $this->assertSame(1, $command->renewCalls);
    }

    public function testNowMsReturnsMillisecondTimestamp(): void
    {
        $command = $this->makeCommand();

        $value = $command->nowMsForTest();

        $this->assertIsInt($value);
        $this->assertGreaterThan(0, $value);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function parseQueueNamesProvider(): iterable
    {
        yield 'empty' => ['', []];
        yield 'trim and dedupe' => [' a , b , a ', ['a', 'b']];
        yield 'skips blanks' => [',x,,', ['x']];
    }

    #[DataProvider('parseQueueNamesProvider')]
    public function testParseQueueNamesNormalizesInput(string $input, array $expected): void
    {
        $command = $this->makeCommand();

        $this->assertSame($expected, $command->parseQueueNamesForTest($input));
    }

    public function testTryAcquireLeaderLockAcceptsTrueOrOk(): void
    {
        $redis = new LeaderLockRedisStub();
        $command = $this->makeCommand(redis: $redis);

        $redis->setReturn = true;
        $this->assertTrue($command->tryAcquireLeaderLockForTest('k', 't', 10));

        $redis->setReturn = 'OK';
        $this->assertTrue($command->tryAcquireLeaderLockForTest('k', 't', 10));

        $redis->setReturn = false;
        $this->assertFalse($command->tryAcquireLeaderLockForTest('k', 't', 10));
    }

    public function testRenewLeaderLockRequiresMatchingToken(): void
    {
        $redis = new LeaderLockRedisStub();
        $redis->getReturn = 'other';
        $command = $this->makeCommand(redis: $redis);

        $this->assertFalse($command->renewLeaderLockForTest('k', 'mine', 10));

        $redis->getReturn = 'mine';
        $redis->expireReturn = false;
        $this->assertFalse($command->renewLeaderLockForTest('k', 'mine', 10));

        $redis->expireReturn = true;
        $this->assertTrue($command->renewLeaderLockForTest('k', 'mine', 10));

        $redis->expireReturn = 1;
        $this->assertTrue($command->renewLeaderLockForTest('k', 'mine', 10));
    }

    public function testReleaseLeaderLockDeletesOnlyWhenTokenMatches(): void
    {
        $redis = new LeaderLockRedisStub();
        $command = $this->makeCommand(redis: $redis);

        $redis->getReturn = 'x';
        $command->releaseLeaderLockForTest('k', 'mine');
        $this->assertSame(0, $redis->delCalls);

        $redis->getReturn = 'mine';
        $command->releaseLeaderLockForTest('k', 'mine');
        $this->assertSame(1, $redis->delCalls);
    }

    protected function makeCommand(
        ?ConsoleInterface      $console = null,
        ?QueueInterface        $queue = null,
        ?JobDiscoveryInterface $jobDiscovery = null,
        ?ClientInterface       $redis = null,
        ?RandomInterface       $random = null,
    ): QueueCommand {
        $random ??= $this->createStub(RandomInterface::class);
        $random->method('uuid')->willReturn('test-uuid');

        /** @var QueueCommandTestHarness $command */
        $command = $this->make(QueueCommandTestHarness::class, [
            'console' => $console ?? $this->createStub(ConsoleInterface::class),
            'queue' => $queue ?? $this->createStub(QueueInterface::class),
            'jobDiscovery' => $jobDiscovery ?? $this->createStub(JobDiscoveryInterface::class),
            'redisQueue' => $redis ?? new LeaderLockRedisStub(),
            'random' => $random,
        ]);

        return $command;
    }

    protected function makeLoopCommand(
        ?QueueInterface   $queue = null,
        ?ConsoleInterface $console = null,
        ?ClientInterface  $redis = null,
        ?array            $acquireResults = null,
        ?Throwable       $acquireThrowable = null,
        ?array            $renewResults = null,
        ?Throwable       $renewThrowable = null,
        ?array            $nowMsValues = null,
    ): QueueCommandLoopStub {
        $random = $this->createStub(RandomInterface::class);
        $random->method('uuid')->willReturn('test-uuid');

        $command = $this->make(QueueCommandLoopStub::class, [
            'console' => $console ?? $this->createStub(ConsoleInterface::class),
            'queue' => $queue ?? $this->createStub(QueueInterface::class),
            'jobDiscovery' => $this->createStub(JobDiscoveryInterface::class),
            'redisQueue' => $redis ?? new LeaderLockRedisStub(),
            'random' => $random,
        ]);

        if ($acquireResults !== null) {
            $command->acquireResults = $acquireResults;
        }
        if ($acquireThrowable !== null) {
            $command->acquireThrowable = $acquireThrowable;
        }
        if ($renewResults !== null) {
            $command->renewResults = $renewResults;
        }
        if ($renewThrowable !== null) {
            $command->renewThrowable = $renewThrowable;
        }
        if ($nowMsValues !== null) {
            $command->nowMsValues = $nowMsValues;
        }

        return $command;
    }
}

/**
 * Minimal Redis double for {@see QueueCommand} leader-lock helpers.
 */
class QueueCommandTestHarness extends QueueCommand
{
    public function nowMsForTest(): int
    {
        return $this->nowMs();
    }

    /**
     * @return list<string>
     */
    public function parseQueueNamesForTest(string $input): array
    {
        return $this->parseQueueNames($input);
    }

    public function tryAcquireLeaderLockForTest(string $key, string $token, int $ttl): bool
    {
        return $this->tryAcquireLeaderLock($key, $token, $ttl);
    }

    public function renewLeaderLockForTest(string $key, string $token, int $ttl): bool
    {
        return $this->renewLeaderLock($key, $token, $ttl);
    }

    public function releaseLeaderLockForTest(string $key, string $token): void
    {
        $this->releaseLeaderLock($key, $token);
    }
}

class LeaderLockRedisStub implements ClientInterface
{
    public mixed $setReturn = true;

    public mixed $getReturn = false;

    public mixed $expireReturn = true;

    public int $delCalls = 0;

    public function getUri(): ?string
    {
        return null;
    }

    public function getTransient(): static
    {
        return $this;
    }

    public function set(string $key, mixed $value, mixed $extra = null): bool
    {
        return $this->setReturn === 'OK' ? true : (bool)$this->setReturn;
    }

    public function get(string $key): mixed
    {
        return $this->getReturn;
    }

    public function expire(string $key, int $ttl): bool|Redis
    {
        return $this->expireReturn === 1 ? true : (bool)$this->expireReturn;
    }

    public function del(string $key): int
    {
        $this->delCalls++;

        return 1;
    }
}

class StopQueueCommandLoop extends RuntimeException
{
}

class QueueCommandLoopStub extends QueueCommand
{
    /** @var list<bool> */
    public array $acquireResults = [true];

    public ?Throwable $acquireThrowable = null;

    public int $acquireCalls = 0;

    /** @var list<bool> */
    public array $renewResults = [true];

    public ?Throwable $renewThrowable = null;

    public int $renewCalls = 0;

    /** @var list<int> */
    public array $nowMsValues = [1_000];

    public int $nowMsCalls = 0;

    protected function tryAcquireLeaderLock(string $key, string $token, int $ttl): bool
    {
        $index = $this->acquireCalls++;
        if (isset($this->acquireResults[$index])) {
            return $this->acquireResults[$index];
        }
        if ($this->acquireThrowable !== null) {
            throw $this->acquireThrowable;
        }

        return false;
    }

    protected function renewLeaderLock(string $key, string $token, int $ttl): bool
    {
        $index = $this->renewCalls++;
        if (isset($this->renewResults[$index])) {
            return $this->renewResults[$index];
        }
        if ($this->renewThrowable !== null) {
            throw $this->renewThrowable;
        }

        return false;
    }

    protected function nowMs(): int
    {
        $index = $this->nowMsCalls++;
        if (isset($this->nowMsValues[$index])) {
            return $this->nowMsValues[$index];
        }

        return $this->nowMsValues[count($this->nowMsValues) - 1];
    }
}
