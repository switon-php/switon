<?php

declare(strict_types=1);

namespace Switon\Command\Tests\Unit;

use Switon\Command\CommandDiscovery;
use Switon\Command\Tests\TestCase;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\ClassScannerInterface;
use RuntimeException;

class CommandDiscoveryCoverageTest extends TestCase
{
    public function testDiscoverSkipsExcludedAndNonCommandClasses(): void
    {
        $composer = $this->createMock(ComposerExtraInterface::class);
        $composer->expects($this->once())
            ->method('collect')
            ->with('switon.commands')
            ->willReturn(['Vendor\\Pkg\\SkipMe', 'Vendor\\Pkg\\AlphaCommand']);

        $scanner = $this->createMock(ClassScannerInterface::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->willReturn(['App\\Command\\BetaCommand', 'App\\Command\\NotACommand', 'App\\Command\\UtilityHelper']);

        $discovery = $this->make(CommandDiscovery::class, [
            'composerExtra' => $composer,
            'classScanner' => $scanner,
            'excludes' => ['Vendor\\Pkg\\SkipMe'],
        ]);

        $result = $discovery->discover();

        $this->assertSame([
            'alpha' => 'Vendor\\Pkg\\AlphaCommand',
            'beta' => 'App\\Command\\BetaCommand',
            'not-a' => 'App\\Command\\NotACommand',
        ], $result);
    }

    public function testExtractCommandNameReturnsNullForNonCommandClass(): void
    {
        $probe = new class () extends CommandDiscovery {
            public function expose(string $className): ?string
            {
                return $this->extractCommandName($className);
            }
        };

        $this->assertSame('xml-http', $probe->expose('App\\Command\\XMLHttpCommand'));
        $this->assertNull($probe->expose('App\\Command\\UtilityHelper'));
    }

    public function testDiscoverSkipsComposerCommandsWhenComposerExtraFails(): void
    {
        $composer = $this->createMock(ComposerExtraInterface::class);
        $composer->expects($this->once())
            ->method('collect')
            ->with('switon.commands')
            ->willThrowException(new RuntimeException('cache broken'));

        $scanner = $this->createMock(ClassScannerInterface::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->willReturn([]);

        $discovery = $this->make(CommandDiscovery::class, [
            'composerExtra' => $composer,
            'classScanner' => $scanner,
        ]);

        $result = $discovery->discover();

        $this->assertSame([], $result);
    }
}
