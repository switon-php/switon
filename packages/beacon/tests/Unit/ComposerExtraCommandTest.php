<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\Command\ComposerExtraCommand;
use Switon\ComposerExtra\ComposerExtra;
use Switon\Core\ConsoleInterface;

#[AllowMockObjectsWithoutExpectations]
class ComposerExtraCommandTest extends TestCase
{
    protected ComposerExtraCommandHarness $command;
    protected ConsoleInterface&MockObject $console;
    protected ComposerExtra&MockObject $composerExtra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = $this->createMock(ConsoleInterface::class);
        $this->composerExtra = $this->createMock(ComposerExtra::class);
        $this->command = new ComposerExtraCommandHarness();
        $this->command->setConsole($this->console);
        $this->command->setComposerExtra($this->composerExtra);
    }

    public function testHealthActionPrintsOkAndReturnsZeroWhenHealthy(): void
    {
        $this->composerExtra->expects($this->once())
            ->method('health')
            ->willReturn([
                'ok' => true,
                'reason' => 'ok',
                'cacheFile' => '/tmp/composer-extra.json',
                'packageCount' => 3,
            ]);

        $this->console->expects($this->once())
            ->method('writeLn')
            ->with('OK');

        $this->assertSame(0, $this->command->healthAction());
    }

    public function testHealthActionPrintsErrorAndReturnsOneWhenUnhealthy(): void
    {
        $this->composerExtra->expects($this->once())
            ->method('health')
            ->willReturn([
                'ok' => false,
                'reason' => 'cache_missing',
                'cacheFile' => '/tmp/composer-extra.json',
                'packageCount' => 0,
            ]);

        $this->console->expects($this->once())
            ->method('error')
            ->with(
                'Composer extra cache unhealthy: {reason} ({cacheFile}). Check allow-plugins and rerun composer install.',
                [
                    'reason' => 'cache_missing',
                    'cacheFile' => '/tmp/composer-extra.json',
                ],
                1
            )
            ->willReturn(1);

        $this->assertSame(1, $this->command->healthAction());
    }

    public function testHealthActionJsonReturnsZeroWhenHealthy(): void
    {
        $payload = [
            'ok' => true,
            'reason' => 'ok',
            'cacheFile' => '/tmp/composer-extra.json',
            'packageCount' => 5,
        ];

        $this->composerExtra->expects($this->once())
            ->method('health')
            ->willReturn($payload);

        $this->console->expects($this->once())
            ->method('writeLn')
            ->with(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertSame(0, $this->command->healthAction(true));
    }

    public function testHealthActionJsonReturnsOneWhenUnhealthy(): void
    {
        $payload = [
            'ok' => false,
            'reason' => 'invalid_json',
            'cacheFile' => '/tmp/composer-extra.json',
            'packageCount' => 0,
        ];

        $this->composerExtra->expects($this->once())
            ->method('health')
            ->willReturn($payload);

        $this->console->expects($this->once())
            ->method('writeLn')
            ->with(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertSame(1, $this->command->healthAction(true));
    }
}

class ComposerExtraCommandHarness extends ComposerExtraCommand
{
    public function setConsole(ConsoleInterface $console): void
    {
        $this->console = $console;
    }

    public function setComposerExtra(ComposerExtra $composerExtra): void
    {
        $this->composerExtra = $composerExtra;
    }
}
