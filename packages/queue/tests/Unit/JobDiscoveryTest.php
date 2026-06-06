<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Unit;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\ClassScannerInterface;
use Switon\Core\Exception\MisuseException;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\JobDiscovery;
use Switon\Queue\Tests\Fixtures\EmptyQueueNameJob;
use Switon\Queue\Tests\Fixtures\InvalidConcurrencyJob;
use Switon\Queue\Tests\Fixtures\MailJob;
use Switon\Queue\Tests\Fixtures\OtherMailJob;
use Switon\Queue\Tests\Fixtures\ReportsJob;
use Switon\Queue\Tests\Fixtures\UnqueuedJob;
use Switon\Queue\Tests\TestCase;

class JobDiscoveryTest extends TestCase
{
    public function testAllMergesScannerAndComposerJobs(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())
            ->method('scan')
            ->with(
                [
                    '@app/Job/*Job.php' => 'App\\Job\\*Job',
                    '@app/Areas/*/Job/*Job.php' => 'App\\Areas\\*\\Job\\*Job',
                ],
                Queued::class,
                RunnerInterface::class
            )
            ->willReturn([]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())
            ->method('collect')
            ->with('switon.jobs')
            ->willReturn([MailJob::class, ReportsJob::class]);

        $discovery = $this->make(JobDiscovery::class, [
            'classScanner' => $classScanner,
            'composerExtra' => $composerExtra,
        ]);

        $this->assertSame(
            [
                'emails' => ['class' => MailJob::class, 'concurrency' => 1],
                'reports' => ['class' => ReportsJob::class, 'concurrency' => 4],
            ],
            $discovery->all()
        );
    }

    public function testAllCachesScanResult(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())->method('scan')->willReturn([MailJob::class]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())->method('collect')->with('switon.jobs')->willReturn([]);

        $discovery = $this->make(JobDiscovery::class, [
            'classScanner' => $classScanner,
            'composerExtra' => $composerExtra,
        ]);

        $first = $discovery->all();
        $second = $discovery->all();

        $this->assertSame($first, $second);
        $this->assertSame(['emails' => ['class' => MailJob::class, 'concurrency' => 1]], $first);
    }

    public function testQueuesReturnsQueueNames(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())->method('scan')->willReturn([MailJob::class, ReportsJob::class]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())->method('collect')->with('switon.jobs')->willReturn([]);

        $discovery = $this->make(JobDiscovery::class, [
            'classScanner' => $classScanner,
            'composerExtra' => $composerExtra,
        ]);

        $this->assertSame(['emails', 'reports'], $discovery->queues());
    }

    public function testDuplicateQueueThrows(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())->method('scan')->willReturn([MailJob::class, OtherMailJob::class]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())->method('collect')->with('switon.jobs')->willReturn([]);

        $discovery = $this->make(JobDiscovery::class, [
            'classScanner' => $classScanner,
            'composerExtra' => $composerExtra,
        ]);

        $this->expectException(MisuseException::class);
        $discovery->all();
    }

    public function testSkipsClassesWithoutQueuedAttribute(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())->method('scan')->willReturn([UnqueuedJob::class, MailJob::class]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())->method('collect')->with('switon.jobs')->willReturn([]);

        $discovery = $this->make(JobDiscovery::class, [
            'classScanner' => $classScanner,
            'composerExtra' => $composerExtra,
        ]);

        $this->assertSame(
            ['emails' => ['class' => MailJob::class, 'concurrency' => 1]],
            $discovery->all()
        );
    }

    public function testSkipsJobWhenQueuedNameIsEmpty(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())->method('scan')->willReturn([EmptyQueueNameJob::class, MailJob::class]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())->method('collect')->with('switon.jobs')->willReturn([]);

        $discovery = $this->make(JobDiscovery::class, [
            'classScanner' => $classScanner,
            'composerExtra' => $composerExtra,
        ]);

        $this->assertSame(
            ['emails' => ['class' => MailJob::class, 'concurrency' => 1]],
            $discovery->all()
        );
    }

    public function testRaisesWhenQueuedConcurrencyIsBelowOne(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())->method('scan')->willReturn([InvalidConcurrencyJob::class]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())->method('collect')->with('switon.jobs')->willReturn([]);

        $discovery = $this->make(JobDiscovery::class, [
            'classScanner' => $classScanner,
            'composerExtra' => $composerExtra,
        ]);

        $this->expectException(MisuseException::class);
        $discovery->all();
    }
}
