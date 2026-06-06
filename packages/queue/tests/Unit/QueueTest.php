<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Unit;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ClockInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\RandomInterface;
use Switon\Core\RunnerInterface;
use Switon\Executor\ExecutorInterface;
use Switon\Executor\FutureInterface;
use Switon\Queue\Event\QueuePulled;
use Switon\Queue\Event\QueuePushed;
use Switon\Queue\Exception;
use Switon\Queue\JobDiscoveryInterface;
use Switon\Queue\Queue;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\Tests\Fixtures\DefaultDummyJob;
use Switon\Queue\Tests\Fixtures\EmailsDummyJob;
use Switon\Queue\Tests\Fixtures\EnvelopeAwareJob;
use Switon\Queue\Tests\Fixtures\MailJob;
use Switon\Redis\ClientInterface;
use Switon\Queue\Tests\TestCase;
use Throwable;
use stdClass;

class QueueTest extends TestCase
{
    public function testPushAndStats(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);

        $id = $queue->push('emails', QueueEnvelope::of(['userId' => 1]));

        $this->assertNotSame('', $id);
        $this->assertSame(1, $queue->stats('emails')['ready']);

        $snapshot = $queue->stats('emails');
        $this->assertSame('emails', $snapshot['queue']);
        $this->assertSame(1, $snapshot['ready']);
        $this->assertSame('1', $snapshot['counter']['pushed']);
    }

    public function testPushDispatchesQueuePushedEvent(): void
    {
        $events = new FakeEventDispatcher();
        $queue = $this->makeQueue(new FakeRedisClient(), [], $events);

        $queue->push('emails', QueueEnvelope::of(['userId' => 1]), 3);

        $this->assertSame([QueuePushed::class], $events->eventClasses());
        $event = $events->last();
        $this->assertInstanceOf(QueuePushed::class, $event);
        $this->assertSame('emails', $event->queue);
        $this->assertSame(3, $event->delay);
        $this->assertSame(['userId' => 1], $event->envelope->body);
    }

    public function testPushKeepsExistingMessageIdUnchanged(): void
    {
        $queue = $this->makeQueue(new FakeRedisClient());
        $envelope = QueueEnvelope::of(['n' => 1]);
        $envelope->message_id = 'fixed-id';

        $id = $queue->push('emails', $envelope);

        $this->assertSame('fixed-id', $id);
        $pulled = $queue->pull('emails');
        $this->assertSame('fixed-id', $pulled->message_id);
    }

    public function testPushUnmappedQueueEnqueues(): void
    {
        $queue = $this->makeQueue(new FakeRedisClient());

        $id = $queue->push('producer-only-queue', QueueEnvelope::of(['k' => 1]));

        $this->assertNotSame('', $id);
        $this->assertSame(1, $queue->stats('producer-only-queue')['ready']);
        $envelope = $queue->pull('producer-only-queue');
        $this->assertSame('producer-only-queue', $envelope->queue);
        $this->assertSame(['k' => 1], $envelope->body);
    }

    public function testConsumeUnmappedQueueThrows(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);

        $queue->push('producer-only-queue', QueueEnvelope::of());
        $envelope = $queue->pull('producer-only-queue');
        $this->assertNotNull($envelope);

        $this->expectException(Exception::class);
        $queue->consume($envelope);
    }

    public function testPullReturnsEnvelopeWithBody(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);

        $queue->push('emails', QueueEnvelope::of(['userId' => 42]));
        $envelope = $queue->pull('emails');

        $this->assertInstanceOf(QueueEnvelope::class, $envelope);
        $this->assertSame(['userId' => 42], $envelope->body);
        $this->assertSame('emails', $envelope->queue);
    }

    public function testPullDispatchesQueuePulledEvent(): void
    {
        $events = new FakeEventDispatcher();
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis, [], $events);
        $queue->push('emails', QueueEnvelope::of(['n' => 1]));
        $events->clear();

        $envelope = $queue->pull('emails', 1);

        $this->assertNotNull($envelope);
        $this->assertSame([QueuePulled::class], $events->eventClasses());
        $event = $events->last();
        $this->assertInstanceOf(QueuePulled::class, $event);
        $this->assertSame(['emails'], $event->queues);
        $this->assertSame(1.0, $event->timeout);
        $this->assertNotNull($event->envelope);
        $this->assertSame('emails', $event->envelope->queue);
        $this->assertSame(['n' => 1], $event->envelope->body);
    }

    public function testPullWithEmptyListReturnsNull(): void
    {
        $queue = $this->makeQueue(new FakeRedisClient());
        $this->assertNull($queue->pull([]));
    }

    public function testPullPopsInKeyOrder(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);

        $queue->push('emails', QueueEnvelope::of(['which' => 'e']));
        $queue->push('orders', QueueEnvelope::of(['which' => 'o']));

        $e1 = $queue->pull(['orders', 'emails'], 0);
        $this->assertSame('orders', $e1->queue);
        $this->assertSame(['which' => 'o'], $e1->body);

        $e2 = $queue->pull(['orders', 'emails'], 0);
        $this->assertSame('emails', $e2->queue);
    }

    public function testPullAcceptsDuplicateQueueNames(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);
        $queue->push('emails', QueueEnvelope::of(['n' => 1]));
        $e = $queue->pull(['emails', 'emails', 'emails'], 0);
        $this->assertSame(['n' => 1], $e->body);
    }

    public function testPullAcceptsInvalidQueueNames(): void
    {
        $queue = $this->makeQueue(new FakeRedisClient());
        $queue->push('emails', QueueEnvelope::of(['n' => 1]));
        $envelope = $queue->pull(['', 'emails'], 0);
        $this->assertNotNull($envelope);
        $this->assertSame('emails', $envelope->queue);
        $this->assertSame(['n' => 1], $envelope->body);
    }

    public function testPullConsumeWithQueueList(): void
    {
        DefaultDummyJob::$handled = 0;
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis, $this->defaultJobMap());
        $queue->push('default', QueueEnvelope::of());
        $envelope = $queue->pull(['emails', 'default'], 1);
        $this->assertNotNull($envelope);
        $queue->consume($envelope);
        $this->assertSame(1, DefaultDummyJob::$handled);
    }

    public function testMigrateDueThrottledWhenDueEveryPositive(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);

        $this->assertSame(0, $queue->migrateDue('emails'));
        $this->assertSame(0, $queue->migrateDue('emails'));
        $this->assertSame(1, $redis->zRangeByScoreCalls);
    }

    public function testMigrateDueSkipsReadyPushWhenDelayedAlreadyRemovedByOtherWorker(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);
        $payload = '{"queue":"emails","message_id":"mid-1","timestamp":1700000000,"body":{"n":1}}';
        $delayedKey = 'queue:test:delayed:emails';

        $redis->zAdd($delayedKey, 1_700_000_000, $payload);
        $redis->simulateConcurrentZRemOnce($delayedKey, $payload);

        $this->assertSame(0, $queue->migrateDue('emails'));
        $this->assertSame(0, $queue->stats('emails')['ready']);
        $this->assertSame(0, $queue->stats('emails')['delayed']);
    }

    public function testPullDoesNotMigrateDelayedMessages(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);
        $payload = '{"queue":"emails","message_id":"mid-2","timestamp":1700000000,"body":{"n":2}}';
        $redis->zAdd('queue:test:delayed:emails', 1_700_000_000, $payload);

        $this->assertNull($queue->pull('emails', 0));
        $this->assertSame(0, $queue->stats('emails')['ready']);
        $this->assertSame(1, $queue->stats('emails')['delayed']);
    }

    public function testMigrateDueMovesDelayedMessageToReady(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);
        $payload = '{"queue":"emails","message_id":"mid-3","timestamp":1700000000,"body":{"n":3}}';
        $redis->zAdd('queue:test:delayed:emails', 1_700_000_000, $payload);

        $this->assertSame(1, $queue->migrateDue('emails'));
        $this->assertSame(1, $queue->stats('emails')['ready']);
        $this->assertSame(0, $queue->stats('emails')['delayed']);
    }

    public function testPushIncrementsPushedCounter(): void
    {
        $queue = $this->makeQueue(new FakeRedisClient());

        $queue->push('emails', QueueEnvelope::of(['n' => 1]));
        $queue->push('emails', QueueEnvelope::of(['n' => 2]));
        $snapshot = $queue->stats('emails');
        $this->assertSame('2', $snapshot['counter']['pushed']);
    }

    public function testPushOptionalHeaders(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);
        $queue->push(
            'emails',
            QueueEnvelope::of(
                ['x' => 1],
                [
                    'type' => 'mail.welcome',
                    'correlation_id' => 'corr-1',
                    'reply_to' => '.rpc.reply',
                    'expiration' => '5000',
                    'x-tenant' => 'a',
                ]
            ),
            0,
        );
        $envelope = $queue->pull('emails');
        $this->assertNull($envelope->type);
        $this->assertNull($envelope->correlation_id);
        $this->assertNull($envelope->reply_to);
        $this->assertNull($envelope->expiration);
        $this->assertSame(
            [
                'type' => 'mail.welcome',
                'correlation_id' => 'corr-1',
                'reply_to' => '.rpc.reply',
                'expiration' => '5000',
                'x-tenant' => 'a',
            ],
            $envelope->headers
        );
    }

    public function testEphemeralQueueSkipsStats(): void
    {
        $queue = $this->makeQueue(new FakeRedisClient());

        $queue->push('.tmp-reply', QueueEnvelope::of(['n' => 1]));
        $snapshot = $queue->stats('.tmp-reply');
        $this->assertSame(1, $snapshot['ready']);
        $this->assertSame(0, $snapshot['delayed']);
        $this->assertSame(0, $snapshot['failed']);

        $envelope = $queue->pull('.tmp-reply');
        $this->assertNotNull($envelope);
        $this->assertSame('.tmp-reply', $envelope->queue);

        $after = $queue->stats('.tmp-reply');
        $this->assertSame(0, $after['ready']);
        $this->assertSame(0, $after['delayed']);
        $this->assertSame(0, $after['failed']);
    }

    public function testPushObjectRoundTripIsArray(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);
        $dto = new stdClass();
        $dto->userId = 7;
        $queue->push('emails', QueueEnvelope::of((array)$dto));
        $envelope = $queue->pull('emails');

        $this->assertIsArray($envelope->body);
        $this->assertSame(7, $envelope->body['userId']);
    }

    public function testRepeatedPush(): void
    {
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis);

        $queue->push('emails', QueueEnvelope::of(['n' => 1]));
        $queue->push('emails', QueueEnvelope::of(['n' => 2]));

        $this->assertSame(2, $queue->stats('emails')['ready']);
        $snapshot = $queue->stats('emails');
        $this->assertSame('2', $snapshot['counter']['pushed']);
    }

    public function testPullThenConsumeProcessesJob(): void
    {
        DefaultDummyJob::$handled = 0;
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis, $this->defaultJobMap());

        $queue->push('default', QueueEnvelope::of());
        $envelope = $queue->pull('default', 1);
        $this->assertNotNull($envelope);
        $queue->consume($envelope);
        $this->assertSame(1, DefaultDummyJob::$handled);
    }

    public function testDiscoveredJobRunsFromScanMap(): void
    {
        MailJob::$handled = 0;
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis, ['emails' => ['class' => MailJob::class, 'concurrency' => 1]]);

        $queue->push('emails', QueueEnvelope::of());
        $envelope = $queue->pull('emails', 1);
        $this->assertNotNull($envelope);
        $queue->consume($envelope);
        $this->assertSame(1, MailJob::$handled);
    }

    public function testConsumeUsesInvokeForSingleConcurrencyJob(): void
    {
        DefaultDummyJob::$handled = 0;
        $redis = new FakeRedisClient();
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static fn (string $class): object => new $class());
        $executor = new RecordingExecutor($container);
        $queue = $this->makeQueue($redis, $this->defaultJobMap(), null, $executor);

        $queue->push('default', QueueEnvelope::of(['n' => 1]));
        $envelope = $queue->pull('default', 1);
        $this->assertNotNull($envelope);

        $queue->consume($envelope);

        $this->assertSame([['method' => 'invoke', 'task' => DefaultDummyJob::class, 'payload' => $envelope]], $executor->calls);
        $this->assertSame(1, DefaultDummyJob::$handled);
    }

    public function testConsumeUsesSubmitForConcurrentJob(): void
    {
        EmailsDummyJob::$handled = 0;
        $redis = new FakeRedisClient();
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static fn (string $class): object => new $class());
        $executor = new RecordingExecutor($container);
        $queue = $this->makeQueue(
            $redis,
            ['emails' => ['class' => EmailsDummyJob::class, 'concurrency' => 2]],
            null,
            $executor
        );

        $queue->push('emails', QueueEnvelope::of(['n' => 1]));
        $envelope = $queue->pull('emails', 1);
        $this->assertNotNull($envelope);

        $queue->consume($envelope);

        $this->assertSame([['method' => 'submit', 'task' => EmailsDummyJob::class, 'payload' => $envelope, 'concurrency' => 2]], $executor->calls);
        $this->assertSame(1, EmailsDummyJob::$handled);
    }

    public function testConsumePassesQueueEnvelopeToRunner(): void
    {
        EnvelopeAwareJob::$payload = null;
        $redis = new FakeRedisClient();
        $queue = $this->makeQueue($redis, ['envelopes' => ['class' => EnvelopeAwareJob::class, 'concurrency' => 1]]);

        $queue->push('envelopes', QueueEnvelope::of(['userId' => 7]));
        $envelope = $queue->pull('envelopes', 1);
        $this->assertNotNull($envelope);

        $queue->consume($envelope);

        $this->assertInstanceOf(QueueEnvelope::class, EnvelopeAwareJob::$payload);
        $this->assertSame(['userId' => 7], EnvelopeAwareJob::$payload->body);
        $this->assertSame('envelopes', EnvelopeAwareJob::$payload->queue);
    }

    /**
     * @param array<string, array{class: class-string<RunnerInterface>, concurrency: int}> $jobMap
     */
    protected function makeQueue(
        FakeRedisClient      $redis,
        array                $jobMap = [],
        ?FakeEventDispatcher $eventDispatcher = null,
        ?ExecutorInterface   $executor = null
    ): Queue {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('time')->willReturn(1_700_000_000);
        $clock->method('microtime')->willReturn(1_700_000_000.123);
        $random = $this->createStub(RandomInterface::class);
        $random->method('uuid')->willReturn('00000000-0000-4000-8000-000000000001');
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class): object => new $class()
        );

        return $this->make(Queue::class, [
            'redisQueue' => $redis,
            'prefix' => 'queue:test:',
            'clock' => $clock,
            'random' => $random,
            'executor' => $executor ?? new FakeExecutor($container),
            'jobDiscovery' => new FixedJobDiscovery($jobMap),
            'eventDispatcher' => $eventDispatcher ?? new FakeEventDispatcher(),
        ]);
    }

    /**
     * @return array<string, array{class: class-string<RunnerInterface>, concurrency: int}>
     */
    protected function defaultJobMap(): array
    {
        return [
            'default' => ['class' => DefaultDummyJob::class, 'concurrency' => 1],
            'emails' => ['class' => EmailsDummyJob::class, 'concurrency' => 1],
        ];
    }
}

class FixedJobDiscovery implements JobDiscoveryInterface
{
    /**
     * @param array<string, array{class: class-string<RunnerInterface>, concurrency: int}> $jobMap
     */
    public function __construct(protected array $jobMap)
    {
    }

    public function all(): array
    {
        return $this->jobMap;
    }

    public function queues(): array
    {
        return array_keys($this->jobMap);
    }
}

class FakeExecutor implements ExecutorInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function invoke(string $task, mixed $payload = null): mixed
    {
        return $this->container->get($task)->run($payload);
    }

    public function submit(string $task, mixed $payload = null, int $concurrency = 1): void
    {
        $this->container->get($task)->run($payload);
    }

    public function invokeAll(array $tasks): array
    {
        $futures = [];

        foreach ($tasks as $taskDefinition) {
            try {
                $futures[] = FakeFuture::success(
                    $this->container->get($taskDefinition[0])->run($taskDefinition[1] ?? null)
                );
            } catch (Throwable $error) {
                $futures[] = FakeFuture::failure($error);
            }
        }

        return $futures;
    }

    public function invokeAny(array $tasks): mixed
    {
        foreach ($this->invokeAll($tasks) as $future) {
            if ($future->isSuccessful()) {
                return $future->result();
            }
        }

        return null;
    }
}

class RecordingExecutor implements ExecutorInterface
{
    /** @var list<array{method: string, task: string, payload: mixed, concurrency?: int}> */
    public array $calls = [];

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function invoke(string $task, mixed $payload = null): mixed
    {
        $this->calls[] = ['method' => 'invoke', 'task' => $task, 'payload' => $payload];

        return $this->container->get($task)->run($payload);
    }

    public function submit(string $task, mixed $payload = null, int $concurrency = 1): void
    {
        $this->calls[] = ['method' => 'submit', 'task' => $task, 'payload' => $payload, 'concurrency' => $concurrency];
        $this->container->get($task)->run($payload);
    }

    public function invokeAll(array $tasks): array
    {
        $futures = [];

        foreach ($tasks as $taskDefinition) {
            try {
                $futures[] = FakeFuture::success(
                    $this->container->get($taskDefinition[0])->run($taskDefinition[1] ?? null)
                );
            } catch (Throwable $error) {
                $futures[] = FakeFuture::failure($error);
            }
        }

        return $futures;
    }

    public function invokeAny(array $tasks): mixed
    {
        foreach ($this->invokeAll($tasks) as $future) {
            if ($future->isSuccessful()) {
                return $future->result();
            }
        }

        return null;
    }
}

class FakeFuture implements FutureInterface
{
    protected function __construct(
        protected bool        $successful,
        protected mixed       $result = null,
        protected ?Throwable $error = null,
    ) {
    }

    public static function success(mixed $result = null): self
    {
        return new self(true, $result);
    }

    public static function failure(Throwable $error): self
    {
        return new self(false, null, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }
}

class FakeEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        return $event;
    }

    public function clear(): void
    {
        $this->events = [];
    }

    /**
     * @return list<class-string>
     */
    public function eventClasses(): array
    {
        return array_map(static fn (object $event): string => $event::class, $this->events);
    }

    public function last(): ?object
    {
        if ($this->events === []) {
            return null;
        }

        return $this->events[array_key_last($this->events)];
    }
}

class FakeRedisClient implements ClientInterface
{
    public int $zRangeByScoreCalls = 0;

    /** @var array<string, list<string>> */
    protected array $lists = [];

    /** @var array<string, array<string, int>> */
    protected array $zsets = [];

    /** @var array<string, array<string, int>> */
    protected array $hashes = [];

    /** @var array<string, int> */
    protected array $expirations = [];

    /** @var array<string, array<string, bool>> */
    protected array $zRemConcurrentMissOnce = [];

    public function getUri(): ?string
    {
        return null;
    }

    public function getTransient(): static
    {
        return $this;
    }

    public function lPush(string $key, string $value): int
    {
        $this->lists[$key] ??= [];
        array_unshift($this->lists[$key], $value);
        return count($this->lists[$key]);
    }

    public function lLen(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }

    public function lTrim(string $key, int $start, int $stop): bool
    {
        $list = $this->lists[$key] ?? [];
        $length = count($list);
        if ($length === 0) {
            return true;
        }

        if ($stop < 0) {
            $stop = $length + $stop;
        }
        $stop = min($stop, $length - 1);
        $start = max($start, 0);
        $this->lists[$key] = $start > $stop ? [] : array_slice($list, $start, $stop - $start + 1);
        return true;
    }

    public function brPop(array $keys, float $timeout): array|false
    {
        foreach ($keys as $key) {
            if (($this->lists[$key] ?? []) !== []) {
                $value = array_pop($this->lists[$key]);
                return [$key, $value];
            }
        }

        return false;
    }

    public function zAdd(string $key, int $score, string $member): int
    {
        $this->zsets[$key] ??= [];
        $this->zsets[$key][$member] = $score;
        return 1;
    }

    /**
     * @param array<string, array{0: int, 1: int}> $options
     *
     * @return list<string>
     */
    public function zRangeByScore(string $key, int|string $min, int|string $max, array $options = []): array
    {
        $this->zRangeByScoreCalls++;
        $items = [];
        foreach ($this->zsets[$key] ?? [] as $member => $score) {
            if ($score >= $min && $score <= $max) {
                $items[$member] = $score;
            }
        }
        asort($items);
        $members = array_keys($items);

        if (isset($options['limit'])) {
            $offset = (int)($options['limit'][0] ?? 0);
            $count = (int)($options['limit'][1] ?? count($members));
            $members = array_slice($members, $offset, $count);
        }

        return array_values($members);
    }

    public function zRem(string $key, string $member): int
    {
        if (($this->zRemConcurrentMissOnce[$key][$member] ?? false) === true) {
            unset($this->zRemConcurrentMissOnce[$key][$member], $this->zsets[$key][$member]);
            return 0;
        }

        if (!isset($this->zsets[$key][$member])) {
            return 0;
        }

        unset($this->zsets[$key][$member]);
        return 1;
    }

    public function zCard(string $key): int
    {
        return count($this->zsets[$key] ?? []);
    }

    /**
     * @return array<string, int> member => score
     */
    public function zsetMembers(string $key): array
    {
        return $this->zsets[$key] ?? [];
    }

    public function hIncrBy(string $key, string $field, int $value): int
    {
        $this->hashes[$key] ??= [];
        $this->hashes[$key][$field] = ($this->hashes[$key][$field] ?? 0) + $value;
        return $this->hashes[$key][$field];
    }

    /**
     * @return array<string, string>
     */
    public function hGetAll(string $key): array
    {
        $result = [];
        foreach ($this->hashes[$key] ?? [] as $field => $value) {
            $result[$field] = (string)$value;
        }
        return $result;
    }

    public function expire(string $key, int $ttl): bool
    {
        $this->expirations[$key] = $ttl;
        return true;
    }

    public function simulateConcurrentZRemOnce(string $key, string $member): void
    {
        $this->zRemConcurrentMissOnce[$key][$member] = true;
    }
}
