<?php

declare(strict_types=1);

namespace Switon\Schedule;

use ReflectionClass;
use Switon\Core\Attributes;
use Switon\Core\Exception\MisuseException;
use Switon\Core\Naming;
use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

use function array_shift;
use function array_splice;
use function class_exists;
use function count;
use function implode;
use function preg_match;
use function preg_split;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Default schedule registry: validates <code>RunnerInterface</code> + #[Scheduled] and expression fields.
 *
 * Road-signs:
 * - register
 * - TaskDiscovery
 * - all / get
 * - name derivation ({@see self::taskClassToId()})
 *
 * @see \Switon\Schedule\ScheduleInterface
 * @see \Switon\Schedule\Attribute\Scheduled
 * @see \Switon\Schedule\TaskDiscovery
 */
class Schedule implements ScheduleInterface
{
    /** @var array<string, ScheduledTask> */
    protected array $byName = [];

    /** @var array<string, true> */
    protected array $byClass = [];

    /**
     * {@inheritDoc}
     *
     * @param class-string<RunnerInterface> $class
     */
    public function register(string $class): void
    {
        if (!class_exists($class)) {
            MisuseException::raise('Scheduled class not found: {class}', ['class' => $class]);
        }

        $reflection = new ReflectionClass($class);
        $scheduled = Attributes::first($reflection, Scheduled::class);
        if ($scheduled === null) {
            MisuseException::raise('Missing #[Scheduled] on {class}', ['class' => $class]);
        }

        $this->assertImplementsRunnerInterface($reflection);

        $parts = $this->splitExpression($scheduled->expression);
        $count = count($parts);
        if ($count !== 4) {
            MisuseException::raise(
                'Scheduled expression must have exactly 4 fields (second minute hour day-of-month), got {count} on {class}',
                ['count' => (string)$count, 'class' => $class]
            );
        }

        foreach ($parts as $p) {
            if (preg_match('#^(\*|\d+|\*/\d+)$#', $p) !== 1) {
                MisuseException::raise(
                    'Unsupported expression field "{field}" in {class}',
                    ['field' => $p, 'class' => $class]
                );
            }
            if (str_starts_with($p, '*/') && (int)substr($p, 2) <= 0) {
                MisuseException::raise(
                    'Unsupported expression field "{field}" in {class}',
                    ['field' => $p, 'class' => $class]
                );
            }
        }

        $lockTtl = $scheduled->lockTtl;
        if ($lockTtl < 0 || $lockTtl > 86_400) {
            MisuseException::raise(
                'Scheduled lockTtl must be 0 (no distributed lock) or between 1 and 86400 seconds on {class}',
                ['class' => $class]
            );
        }

        $name = $this->taskClassToId($reflection->getName());
        if ($name === '' || $name === '-') {
            MisuseException::raise(
                'Cannot derive scheduled task id from {class}',
                ['class' => $reflection->getName()]
            );
        }

        if (isset($this->byName[$name])) {
            MisuseException::raise(
                'Duplicate scheduled task id "{name}" (taskClassToId collision; rename a class or adjust its namespace)',
                ['name' => $name]
            );
        }
        if (isset($this->byClass[$class])) {
            MisuseException::raise('Scheduled class already registered: {class}', ['class' => $class]);
        }

        $this->byName[$name] = new ScheduledTask(
            $name,
            $class,
            $scheduled->expression,
            $lockTtl,
        );
        $this->byClass[$class] = true;
    }

    /** {@inheritDoc} */
    public function all(): array
    {
        return $this->byName;
    }

    public function get(string $name): ?ScheduledTask
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    protected function splitExpression(string $expression): array
    {
        $expression = trim($expression);
        $parts = preg_split('/\s+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        /** @var list<string> $parts */
        return $parts;
    }

    /**
     * Not HTTP {@see \Switon\Http\HandlerId}; split FQCN by <code>\\</code>, drop the root namespace segment, strip repeated leading <code>Task</code>, remove nested <code>Areas</code> segments, strip trailing class-name <code>Task</code> suffix, then {@see Naming::kebab()}.
     */
    protected function taskClassToId(string $fqcn): string
    {
        $segments = explode('\\', $fqcn);

        if (count($segments) > 1) {
            array_shift($segments);
        }

        while (($segments[0] ?? null) === 'Task') {
            array_shift($segments);
        }

        while (count($segments) >= 2 && $segments[1] === 'Areas') {
            array_splice($segments, 1, 1);
        }

        if ($segments === []) {
            return '';
        }

        $lastIndex = count($segments) - 1;
        $last = $segments[$lastIndex];
        if ($last !== 'Task' && str_ends_with($last, 'Task')) {
            $segments[$lastIndex] = substr($last, 0, -4);
        }

        return Naming::kebab(implode('.', $segments));
    }

    /**
     * @param ReflectionClass<RunnerInterface> $reflection
     */
    protected function assertImplementsRunnerInterface(ReflectionClass $reflection): void
    {
        if (!$reflection->implementsInterface(RunnerInterface::class)) {
            MisuseException::raise(
                'Scheduled class must implement {interface}: {class}',
                ['interface' => RunnerInterface::class, 'class' => $reflection->getName()]
            );
        }
    }
}
