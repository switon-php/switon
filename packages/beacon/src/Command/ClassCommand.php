<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Switon\Beacon\ClassIndexInterface;
use Switon\Beacon\Exception\ClassIndexNotFoundException;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassName;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilterMatcherInterface;
use Switon\Core\Json;
use ReflectionClass;
use Throwable;

use function array_filter;
use function array_slice;
use function array_values;
use function class_exists;
use function explode;
use function file_get_contents;
use function interface_exists;
use function ksort;
use function str_contains;
use function str_replace;
use function trim;

/**
 * class:list = list FQCNs; class:inspect = one class meta; class:content = class/method source; class:methods = search methods by filter.
 *
 * @see \Switon\Beacon\ClassIndexInterface
 * @see \Switon\Beacon\Command\CodeCommand
 */
#[Hidden]
class ClassCommand
{
    #[Autowired] protected ClassIndexInterface $classIndex;

    #[Autowired] protected ConsoleInterface $console;

    #[Autowired] protected FilterMatcherInterface $filterMatcher;

    /** List FQCNs from class index, optional filter. */
    #[Tool('[filter]. Returns JSON with classes array of FQCNs. Run composer dump-autoload -o if index missing.')]
    public function listAction(string $filter = ''): int
    {
        try {
            $classNames = $this->classIndex->all();
        } catch (ClassIndexNotFoundException $e) {
            return $this->writeError(['error' => 'class index unavailable', 'message' => $e->getMessage()]);
        }
        $classNames = array_values(array_filter($classNames, fn (string $c): bool => $this->filterMatcher->match($filter, $c)));
        $this->console->writeLn(Json::stringify(['classes' => $classNames]));
        return 0;
    }

    /** Inspect one class: file, methods, properties. */
    #[Tool('<FQCN>. Returns JSON: class, file, methods (names), properties (name: type).')]
    public function inspectAction(string $class): int
    {
        $class = ClassName::normalize($class);
        $data = $this->getInspectData($class);
        if ($data === null) {
            return $this->writeError(['error' => 'class not found', 'class' => $class]);
        }
        $this->console->writeLn(Json::stringify($data));
        return 0;
    }

    /** Get class or method source (file, lines, source). */
    #[Tool('<FQCN[::method]> [--no-phpdoc]. Returns JSON: file, source, class; with ::method adds startLine, endLine, method.')]
    public function contentAction(string $classOrMethod, bool $phpdoc = true): int
    {
        $class = $classOrMethod;
        $method = null;
        if (str_contains($classOrMethod, '::')) {
            $parts = explode('::', $classOrMethod, 2);
            $class = trim($parts[0]);
            $method = trim($parts[1]);
        }
        $class = ClassName::normalize($class);
        $result = $this->getContentData($class, $method, !$phpdoc);
        if (isset($result['error'])) {
            return $this->writeError($result['error']);
        }
        $this->console->writeLn(Json::stringify($result['data']));
        return 0;
    }

    /**
     * Search method names by filter and group by class (AI clue index).
     *
     * Filter supports wildcards `*` `?` (same matcher used by tool:list).
     *
     * @param string $filter Optional filter; matches class name or method name
     * @param bool $interfaceOnly When true, output interfaces only.
     */
    #[Tool('[filter] [--interface-only]. Returns JSON: classes{FQCN: [methodName, ...]}, skipped[] for items that could not be inspected.')]
    public function methodsAction(string $filter = '', bool $interfaceOnly = false): int
    {
        $filter = trim($filter);

        try {
            $classNames = $this->classIndex->all();
        } catch (ClassIndexNotFoundException $e) {
            return $this->writeError(['error' => 'class index unavailable', 'message' => $e->getMessage()]);
        }

        /** @var array<string, list<string>> $classes */
        $classes = [];
        /** @var list<string> $skipped */
        $skipped = [];
        foreach ($classNames as $class) {
            // Apply class-level filter first: only consider matching FQCNs.
            if ($filter !== '' && !$this->filterMatcher->match($filter, $class)) {
                continue;
            }

            $isInterface = interface_exists($class);
            $isClass = class_exists($class);

            // When interface-only, silently ignore non-interfaces entirely.
            if ($interfaceOnly && !$isInterface) {
                continue;
            }

            if (!$isInterface && !$isClass) {
                // Only track non-existent types when not interface-only.
                if (!$interfaceOnly) {
                    $skipped[] = $class;
                }
                continue;
            }

            // Event namespace types are skipped only in non-interface-only mode.
            if (str_contains($class, '\\Event\\')) {
                if (!$interfaceOnly) {
                    $skipped[] = $class;
                }
                continue;
            }
            $ref = new ReflectionClass($class);
            // Skip exception hierarchies when not limited to interfaces
            if (
                !$interfaceOnly
                && ($ref->isSubclassOf(Throwable::class) || $ref->getName() === Throwable::class)
            ) {
                $skipped[] = $class;
                continue;
            }

            $methods = [];
            foreach ($ref->getMethods() as $m) {
                if ($filter === '' || $this->filterMatcher->matchAny($filter, ['class' => $class, 'method' => $m->getName()])) {
                    $methods[] = $m->getName();
                }
            }

            if ($methods === []) {
                continue;
            }

            sort($methods);
            $classes[$class] = $methods;
        }

        if ($classes !== []) {
            ksort($classes);
        }

        $this->console->writeLn(Json::stringify([
            'classes' => $classes,
            'skipped' => $skipped,
        ]));
        return 0;
    }

    /**
     * Search property names by filter and group by class (AI clue index).
     *
     * Filter supports wildcards `*` `?` (same matcher used by tool:list).
     *
     * @param string $filter Optional filter; matches class name or property name
     */
    #[Tool('[filter]. Returns JSON: classes{FQCN: [propertyName, ...]}.')]
    public function propertiesAction(string $filter = ''): int
    {
        $filter = trim($filter);

        try {
            $classNames = $this->classIndex->all();
        } catch (ClassIndexNotFoundException $e) {
            return $this->writeError(['error' => 'class index unavailable', 'message' => $e->getMessage()]);
        }

        /** @var array<string, list<string>> $classes */
        $classes = [];
        foreach ($classNames as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }
            $ref = new ReflectionClass($class);

            $props = [];
            foreach ($ref->getProperties() as $p) {
                if ($p->isStatic()) {
                    continue;
                }
                if ($filter === '' || $this->filterMatcher->matchAny($filter, ['class' => $class, 'property' => $p->getName()])) {
                    $props[] = $p->getName();
                }
            }

            if ($props === []) {
                continue;
            }

            $classes[$class] = $props;
        }

        $this->console->writeLn(Json::stringify(['classes' => $classes]));
        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function writeError(array $payload): int
    {
        $this->console->writeLn(Json::stringify($payload));
        return 1;
    }

    protected function getClassFile(string $fqcn): ?string
    {
        $fqcn = ClassName::normalize($fqcn);
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            return null;
        }
        $file = (new ReflectionClass($fqcn))->getFileName();
        return $file !== false ? $file : null;
    }

    /**
     * @return array{class: string, file: string, methods: list<string>, properties: list<string>}|null
     */
    protected function getInspectData(string $class): ?array
    {
        $class = ClassName::normalize($class);
        if (!class_exists($class) && !interface_exists($class)) {
            return null;
        }
        $ref = new ReflectionClass($class);
        $file = $ref->getFileName();
        $methods = [];
        foreach ($ref->getMethods() as $m) {
            $methods[] = $m->getName();
        }
        $properties = [];
        foreach ($ref->getProperties() as $p) {
            if ($p->isStatic()) {
                continue;
            }
            $type = $p->getType();
            $properties[] = $type !== null ? $p->getName() . ': ' . $type : $p->getName();
        }
        return [
            'class' => $class,
            'file' => $file !== false ? $file : '',
            'methods' => $methods,
            'properties' => $properties,
        ];
    }

    /**
     * @return array{error: array<string, mixed>, humanMessage: string}|array{data: array<string, mixed>}
     */
    protected function getContentData(string $class, ?string $method, bool $noPhpdoc = false): array
    {
        $class = ClassName::normalize($class);
        if (!class_exists($class) && !interface_exists($class)) {
            return ['error' => ['error' => 'class not found', 'class' => $class], 'humanMessage' => 'Class not found: ' . $class];
        }
        $refClass = new ReflectionClass($class);
        $file = $refClass->getFileName();

        $refMethod = null;
        if ($method !== null) {
            if (!$refClass->hasMethod($method)) {
                return ['error' => ['error' => 'method not found', 'class' => $class, 'method' => $method], 'humanMessage' => "Method not found: $class::$method"];
            }
            $refMethod = $refClass->getMethod($method);
            $file = $refMethod->getDeclaringClass()->getFileName();
        }

        if ($file === false || !is_file($file)) {
            return ['error' => ['error' => 'file not found', 'file' => $file], 'humanMessage' => 'Source file not available.'];
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return ['error' => ['error' => 'could not read file', 'file' => $file], 'humanMessage' => 'Could not read file.'];
        }

        if ($method === null) {
            $source = $content;
        } else {
            $lines = explode("\n", $content);
            $startLine = $refMethod->getStartLine();
            $endLine = $refMethod->getEndLine();
            $slice = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
            $source = implode("\n", $slice);
        }
        $source = trim($source);

        if ($noPhpdoc) {
            $docsToStrip = [];
            if ($method === null) {
                $classDoc = $refClass->getDocComment();
                if ($classDoc !== false) {
                    $docsToStrip[] = $classDoc;
                }
                foreach ($refClass->getMethods() as $m) {
                    $d = $m->getDocComment();
                    if ($d !== false) {
                        $docsToStrip[] = $d;
                    }
                }
                foreach ($refClass->getProperties() as $p) {
                    $d = $p->getDocComment();
                    if ($d !== false) {
                        $docsToStrip[] = $d;
                    }
                }
                foreach ($refClass->getReflectionConstants() as $c) {
                    $d = $c->getDocComment();
                    if ($d !== false) {
                        $docsToStrip[] = $d;
                    }
                }
                foreach ($docsToStrip as $doc) {
                    $source = str_replace($doc, '', $source);
                }
                $source = trim(preg_replace("/\n{3,}/", "\n\n", $source));
            } else {
                $doc = $refClass->getMethod($method)->getDocComment();
                if ($doc !== false) {
                    $source = trim(str_replace($doc, '', $source));
                }
            }
        } else {
            $refForDoc = $method !== null ? $refClass->getMethod($method) : $refClass;
            $doc = $refForDoc->getDocComment();
            if ($doc !== false && $method !== null) {
                $source = trim($doc) . "\n" . $source;
            }
        }

        $data = ['class' => $class, 'file' => $file, 'source' => $source];
        if ($method !== null && $refMethod !== null) {
            $data['method'] = $method;
            $data['startLine'] = $refMethod->getStartLine();
            $data['endLine'] = $refMethod->getEndLine();
        }
        return ['data' => $data];
    }

}
