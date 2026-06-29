<?php

declare(strict_types=1);

namespace Switon\Queue;

use ReflectionClass;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attributes;
use Switon\Core\ClassScannerInterface;
use Switon\Core\Exception\MisuseException;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;

use function array_keys;
use function trim;

/**
 * Scans queue jobs from application paths and framework package Composer extra metadata.
 *
 * Guidance: Framework package jobs belong in composer.json `extra.switon.jobs`; app jobs under `@app/Job` and `@app/Areas/{area}/Job` are scanned automatically.
 *
 * Road-signs:
 * - all()
 * - app: {@see ClassScannerInterface} + <code>$paths</code> + {@see Queued} + {@see RunnerInterface}
 * - packages: {@see ComposerExtraInterface::collect()} <code>switon.jobs</code>
 *
 * @see \Switon\Queue\JobDiscoveryInterface
 * @see \Switon\Core\ClassScannerInterface
 * @see \Switon\ComposerExtra\ComposerExtraInterface
 * @see \Switon\Queue\Attribute\Queued
 */
class JobDiscovery implements JobDiscoveryInterface
{
    #[Autowired] protected ClassScannerInterface $classScanner;
    #[Autowired] protected ComposerExtraInterface $composerExtra;

    /** @var array<string, string> */
    #[Autowired] protected array $paths
        = [
            '@app/Job/*Job.php' => 'App\\Job\\*Job',
            '@app/Areas/*/Job/*Job.php' => 'App\\Areas\\*\\Job\\*Job',
        ];

    /** @var array<string, array{class: class-string<RunnerInterface>, concurrency: int}>|null */
    protected ?array $jobs = null;

    /** {@inheritDoc} */
    public function all(): array
    {
        if ($this->jobs !== null) {
            return $this->jobs;
        }

        /** @var array<int, class-string> $classes */
        $classes = [];
        foreach ($this->classScanner->scan($this->paths, Queued::class, RunnerInterface::class) as $className) {
            $classes[] = $className;
        }
        foreach ($this->composerExtra->collect('switon.jobs') as $className) {
            $classes[] = $className;
        }

        $jobs = [];
        foreach ($classes as $class) {
            /** @var class-string<RunnerInterface> $class */
            $reflection = new ReflectionClass($class);

            $queued = Attributes::first($reflection, Queued::class);
            if ($queued === null) {
                continue;
            }
            $queue = trim($queued->queue);
            if ($queue === '') {
                continue;
            }
            if ($queued->concurrency < 1) {
                MisuseException::raise(
                    'Queued concurrency for "{class}" must be >= 1, got {concurrency}',
                    ['class' => $class, 'concurrency' => $queued->concurrency]
                );
            }

            $definition = ['class' => $class, 'concurrency' => $queued->concurrency];

            if (isset($jobs[$queue]) && $jobs[$queue] !== $definition) {
                MisuseException::raise(
                    'Duplicate queue job binding for "{queue}" ({existing} vs {class})',
                    ['queue' => $queue, 'existing' => $jobs[$queue]['class'], 'class' => $class]
                );
            }

            $jobs[$queue] = $definition;
        }

        $this->jobs = $jobs;

        return $this->jobs;
    }

    /** {@inheritDoc} */
    public function queues(): array
    {
        return array_keys($this->all());
    }
}
