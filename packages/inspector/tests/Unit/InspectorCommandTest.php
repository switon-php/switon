<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Core\AppInterface;
use Switon\Core\ConsoleInterface;
use Switon\Inspector\Command\InspectorCommand;
use Switon\Redis\ClientInterface;

#[AllowMockObjectsWithoutExpectations]
class InspectorCommandTest extends TestCase
{
    protected InspectorCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected AppInterface&MockObject $app;
    protected FakeRedisPubSubClient $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new InspectorCommandProbe();
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->app = $this->createMock(AppInterface::class);
        $this->redis = new FakeRedisPubSubClient();

        $this->command->setConsole($this->console);
        $this->command->setApp($this->app);
        $this->command->setRedisCache($this->redis);
    }

    public function testWatchActionUsesCurrentAppIdAndPrintsMatchingLinks(): void
    {
        $this->app->method('id')->willReturn('demo');
        $this->redis->events = [
            ['__inspector:demo:127.0.0.1:OrderController::index', 'https://example.test/orders/1'],
        ];

        $lines = [];
        $this->console->expects($this->exactly(2))
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->command->watchAction();

        $this->assertSame(
            ['__inspector:demo:*'],
            $this->redis->patterns
        );
        $this->assertSame('subscribe to __inspector:demo:*', $lines[0]);
        $this->assertSame('[127.0.0.1][OrderController::index]: https://example.test/orders/1', $lines[1]);
    }

    public function testWatchActionFiltersByPathPrefix(): void
    {
        $this->app->method('id')->willReturn('demo');
        $this->redis->events = [
            ['__inspector:demo:127.0.0.1:AdminController::panel', 'https://example.test/admin'],
        ];

        $lines = [];
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->command->watchAction(null, 'ApiController::');

        $this->assertSame(['subscribe to __inspector:demo:*'], $lines);
    }

    public function testWatchActionSupportsIpSuffixFilter(): void
    {
        $this->app->method('id')->willReturn('demo');
        $this->redis->events = [
            ['__inspector:demo:10.0.0.9:OrderController::index', 'https://example.test/orders/9'],
            ['__inspector:demo:10.0.0.8:OrderController::index', 'https://example.test/orders/8'],
        ];

        $lines = [];
        $this->console->expects($this->exactly(2))
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->command->watchAction(null, '', '8');

        $this->assertSame('subscribe to __inspector:demo:*', $lines[0]);
        $this->assertSame('[10.0.0.8][OrderController::index]: https://example.test/orders/8', $lines[1]);
    }

    public function testWatchActionPrintsWhenFullIpMatches(): void
    {
        $this->app->method('id')->willReturn('demo');
        $this->redis->events = [
            ['__inspector:demo:192.168.1.10:HomeController::index', 'https://example.test/i'],
        ];

        $lines = [];
        $this->console->expects($this->exactly(2))
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->command->watchAction(null, '', '192.168.1.10');

        $this->assertSame('[192.168.1.10][HomeController::index]: https://example.test/i', $lines[1]);
    }

    public function testWatchActionSkipsWhenFullIpDoesNotMatch(): void
    {
        $this->app->method('id')->willReturn('demo');
        $this->redis->events = [
            ['__inspector:demo:192.168.1.10:HomeController::index', 'https://example.test/i'],
        ];

        $lines = [];
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->command->watchAction(null, '', '192.168.1.11');

        $this->assertSame(['subscribe to __inspector:demo:*'], $lines);
    }

}

class InspectorCommandProbe extends InspectorCommand
{
    public function setConsole(ConsoleInterface $console): void
    {
        $this->console = $console;
    }

    public function setApp(AppInterface $app): void
    {
        $this->app = $app;
    }

    public function setRedisCache(ClientInterface $redisCache): void
    {
        $this->redisCache = $redisCache;
    }
}

class FakeRedisPubSubClient implements ClientInterface
{
    /** @var array<int, string> */
    public array $patterns = [];

    /** @var array<int, array{0: string, 1: string}> */
    public array $events = [];

    public function getUri(): ?string
    {
        return null;
    }

    public function getTransient(): static
    {
        return $this;
    }

    /**
     * @param array<int, string> $patterns
     * @param callable $callback
     */
    public function psubscribe(array $patterns, callable $callback): void
    {
        $this->patterns = $patterns;
        foreach ($this->events as [$channel, $message]) {
            $callback($this, $patterns[0] ?? '', $channel, $message);
        }
    }
}
