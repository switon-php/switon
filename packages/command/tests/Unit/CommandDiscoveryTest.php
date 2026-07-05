<?php

declare(strict_types=1);

namespace Switon\Command\Tests\Unit;

use Switon\Command\CommandDiscovery;
use Switon\Command\Tests\TestCase;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\ClassScannerInterface;

class CommandDiscoveryTest extends TestCase
{
    public function testDiscoverMergesPackageAndScannerResults(): void
    {
        $composer = $this->createMock(ComposerExtraInterface::class);
        $composer->expects($this->once())
            ->method('collect')
            ->with('switon.commands')
            ->willReturn([
                'Vendor\\Pkg\\AlphaCommand',
                'Vendor\\Pkg\\DupCommand',
            ]);

        $scanner = $this->createMock(ClassScannerInterface::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->with([
                '@app/Command/*Command.php' => 'App\\Command\\*Command',
                '@app/Areas/*/Command/*Command.php' => 'App\\Areas\\*\\Command\\*Command',
            ])
            ->willReturn([
                'App\\Command\\DupCommand',
                'App\\Command\\BetaCommand',
            ]);

        $discovery = $this->make(CommandDiscovery::class, [
            'composerExtra' => $composer,
            'classScanner' => $scanner,
        ]);

        $result = $discovery->discover();

        $this->assertSame([
            'alpha' => 'Vendor\\Pkg\\AlphaCommand',
            'beta' => 'App\\Command\\BetaCommand',
            'dup' => 'App\\Command\\DupCommand',
        ], $result);
    }

    public function testDiscoverSkipsScannerClassesMatchingExcludePrefixes(): void
    {
        $composer = $this->createStub(ComposerExtraInterface::class);
        $composer->method('collect')->willReturn([]);

        $scanner = $this->createStub(ClassScannerInterface::class);
        $scanner->method('scan')->willReturn([
            'App\\Internal\\SkipCommand',
            'App\\Command\\KeepCommand',
        ]);

        $discovery = $this->make(CommandDiscovery::class, [
            'composerExtra' => $composer,
            'classScanner' => $scanner,
            'excludes' => ['App\\Internal\\'],
        ]);

        $this->assertSame([
            'keep' => 'App\\Command\\KeepCommand',
        ], $discovery->discover());
    }

    public function testDiscoverCachesResults(): void
    {
        $composer = $this->createMock(ComposerExtraInterface::class);
        $composer->expects($this->once())
            ->method('collect')
            ->with('switon.commands')
            ->willReturn([]);

        $scanner = $this->createMock(ClassScannerInterface::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->willReturn([]);

        $discovery = $this->make(CommandDiscovery::class, [
            'composerExtra' => $composer,
            'classScanner' => $scanner,
        ]);

        $first = $discovery->discover();
        $second = $discovery->discover();

        $this->assertSame($first, $second);
    }
}
