<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Switon\Beacon\EventScannerInterface;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassName;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilterMatcherInterface;
use Switon\Core\Json;
use Switon\Eventing\EventLoggerInterface;
use Switon\Eventing\ListenerProviderInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

use function array_filter;
use function implode;
use function strtolower;

/**
 * List event classes and their log category (code). Scan @app/Event, @app/Event, @vendor/…/Event, @vendor/…/Event.
 *
 * Requires matching PHP files then iterates get_declared_classes() so the result is authoritative.
 * Category is resolved via EventLoggerInterface::categoryForClass().
 *
 * @see \Switon\Beacon\EventScannerInterface
 * @see \Switon\Eventing\EventLoggerInterface
 */
class EventCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected EventLoggerInterface $eventLogger;
    #[Autowired] protected EventScannerInterface $eventScanner;
    #[Autowired] protected FilterMatcherInterface $filterMatcher;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;

    /**
     * List event classes (class, file, category). Filter by class name or category. No properties; use event:class for detail.
     *
     * @param string $filter Optional: match class name or category (e.g. user.created)
     * @param bool $json Machine-readable output
     */
    #[Hidden, Tool('[filter]. Returns JSON: events[] → class, file, category. Filter matches class or category.')]
    public function classesAction(string $filter = '', bool $json = true): int
    {
        $events = $this->buildEventsList(false);
        $events = array_values(array_filter($events, fn (array $e) => $this->matchFilter($filter, $e['class'], $e['category'])));

        return $this->outputEvents($events, $json);
    }

    /**
     * Class → category (log code). Returns class, category.
     */
    #[Hidden, Tool('<class>. Returns JSON: events[] with class, category (log code).')]
    public function categoryAction(string $class, bool $json = true): int
    {
        $class = ClassName::normalize($class);
        $rows = [['class' => $class, 'category' => $this->eventLogger->categoryForClass($class)]];
        if ($json) {
            $this->console->writeLn(Json::stringify(['events' => $rows]));
            return 0;
        }
        $this->console->writeLn($rows[0]['class'] . '  ' . $rows[0]['category']);
        return 0;
    }

    /**
     * Code → classes. Find event classes whose category matches code. Returns class, category.
     */
    #[Hidden, Tool('<code>. Find classes with this log code. Returns JSON: events[] with class, category.')]
    public function byCodeAction(string $code, bool $json = true): int
    {
        $events = $this->buildEventsList(false);
        $events = array_values(array_filter($events, fn (array $e) => $this->matchFilter($code, $e['class'], $e['category'])));
        $rows = array_map(static fn (array $e) => ['class' => $e['class'], 'category' => $e['category']], $events);
        if ($json) {
            $this->console->writeLn(Json::stringify(['events' => $rows]));
            return 0;
        }
        foreach ($rows as $r) {
            $this->console->writeLn($r['class'] . '  ' . $r['category']);
        }
        return 0;
    }

    /**
     * Show one event class: class, file, category, and public properties (payload).
     *
     * @param string $class Event class FQCN (must be in scanned event classes)
     * @param bool $json Machine-readable output
     */
    #[Hidden, Tool('<class>. Returns JSON: single event → class, file, category, properties[].')]
    public function classAction(string $class, bool $json = true): int
    {
        $class = ClassName::normalize($class);
        $all = $this->buildEventsList(true);
        $match = null;
        foreach ($all as $e) {
            if (strtolower($e['class']) === strtolower($class)) {
                $match = $e;
                break;
            }
        }
        if ($match === null) {
            return $this->console->error('Event class not found: {class}', ['class' => $class]);
        }

        $properties = $match['properties'] ?? [];
        if ($json) {
            $row = ['class' => $match['class'], 'file' => $match['file'], 'category' => $match['category'], 'properties' => $properties];
            $this->console->writeLn(Json::stringify($row));
            return 0;
        }

        $line = $match['class'] . '  ' . $match['category'] . '  (' . $match['file'] . ')';
        if ($properties !== []) {
            $line .= '  [' . implode(', ', array_map(static fn (array $p) => $p['name'] . ($p['type'] ? ': ' . $p['type'] : ''), $properties)) . ']';
        }
        $this->console->writeLn($line);
        return 0;
    }

    /**
     * List listeners for all events (or filtered subset).
     *
     * @param string $filter Optional filter for event FQCN or listener (FQCN::method)
     * @param bool $json Machine-readable output
     */
    #[Tool('[filter]. Returns JSON: events{* or event: listeners[] (FQCN::method)}. Filter matches event or listener.')]
    public function listenersAction(string $filter = '', bool $json = true): int
    {
        $events = $this->listenerProvider->getListeners();
        $events = $this->filterEvents($events, $filter);

        if ($json) {
            $this->console->writeLn(Json::stringify(['events' => $events]));
            return 0;
        }

        foreach ($events as $event => $listeners) {
            $this->console->writeLn($event . ':');
            foreach ($listeners as $listener) {
                $this->console->writeLn('  ' . $listener);
            }
        }

        return 0;
    }

    /**
     * @return list<array{class: string, file: string, category: string, properties?: list<array{name: string, type: string|null}>}>
     */
    protected function buildEventsList(bool $withProps): array
    {
        $classes = $this->eventScanner->scanEventClasses();
        $events = [];
        $seen = [];
        foreach ($classes as $class) {
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            $ref = new ReflectionClass($class);
            $file = $ref->getFileName();
            $events[] = [
                'class' => $class,
                'file' => $file !== false ? $file : '',
                'category' => $this->eventLogger->categoryForClass($class),
            ];
        }
        if ($withProps) {
            foreach ($events as $i => $e) {
                $events[$i]['properties'] = $this->getPublicProperties($e['class']);
            }
        }
        return $events;
    }

    /**
     * @param list<array{class: string, file: string, category: string}> $events
     */
    protected function outputEvents(array $events, bool $json): int
    {
        if ($json) {
            $out = array_map(static fn (array $e) => ['class' => $e['class'], 'file' => $e['file'], 'category' => $e['category']], $events);
            $this->console->writeLn(Json::stringify(['events' => $out]));
            return 0;
        }
        foreach ($events as $e) {
            $this->console->writeLn($e['class'] . '  ' . $e['category'] . '  (' . $e['file'] . ')');
        }
        return 0;
    }

    protected function matchFilter(string $filter, string $class, string $category): bool
    {
        return $this->filterMatcher->matchAny($filter, [
            'class' => $class,
            'category' => $category,
        ]);
    }

    /**
     * @param array<string, list<string>> $events
     *
     * @return array<string, list<string>>
     */
    protected function filterEvents(array $events, string $filter): array
    {
        $out = [];
        foreach ($events as $event => $listeners) {
            $matched = [];
            foreach ($listeners as $listener) {
                if ($this->filterMatcher->matchAny($filter, [
                    'event' => $event,
                    'listener' => $listener,
                ])) {
                    $matched[] = $listener;
                }
            }
            if ($matched !== []) {
                $out[$event] = $matched;
            }
        }

        return $out;
    }

    /**
     * @param class-string $class
     *
     * @return list<array{name: string, type: string|null}>
     */
    protected function getPublicProperties(string $class): array
    {
        $r = new ReflectionClass($class);
        $out = [];
        foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic()) {
                continue;
            }
            $type = $p->getType();
            $out[] = [
                'name' => $p->getName(),
                'type' => $type instanceof ReflectionNamedType ? $type->getName() : ($type !== null ? (string)$type : null),
            ];
        }
        return $out;
    }
}
