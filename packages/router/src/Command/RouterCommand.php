<?php

declare(strict_types=1);

namespace Switon\Routing\Command;

use ReflectionClass;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Console\Colors;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassName;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\FilterMatcherInterface;
use Switon\Core\Naming;
use Switon\Routing\Exception\NotFoundRouteException;
use Switon\Routing\RouterInterface;
use Throwable;

use function basename;
use function explode;
use function ksort;
use function str_contains;
use function str_pad;
use function str_repeat;
use function str_starts_with;
use function strtoupper;

/**
 * List registered routes or resolve verb+path to handler.
 *
 * router:list — list routes (optional filter; same as _conventions).
 * router:resolve — given path + verb, return handler and route variables (AI/scripts).
 * router:prefix — output router prefix only (optional --json).
 *
 * Use when inspecting registered routes, resolving handlers for tooling, or exposing router prefix configuration.
 *
 * Road-signs:
 * - listAction() for route inventory
 * - resolveAction() for handler lookup
 * - prefixAction() for prefix diagnostics
 *
 * @see \Switon\Routing\RouterInterface
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\RouterInterface::getAllRoutes()
 */
class RouterCommand
{
    /** Console output adapter. */
    #[Autowired] protected ConsoleInterface $console;

    /** Route data source. */
    #[Autowired] protected RouterInterface $router;

    /** Path filter (substring or wildcards * ?). */
    #[Autowired] protected FilterMatcherInterface $filterMatcher;

    /** Filesystem for reading handler source files. */
    #[Autowired] protected FilesystemInterface $filesystem;

    /** Common HTTP verbs to try when verb is omitted (router:resolve path → try all). */
    protected const array RESOLVE_VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Resolves path + verb to handler metadata.
     *
     * Omit verb to try all common verbs; JSON output keeps a fixed shape for tooling.
     *
     * @param string $path Request path (e.g. /api/users/123); leading / optional
     * @param string $verb HTTP method (e.g. GET), or empty to try all common verbs
     * @param bool $json Machine-readable output: matched, path, verb, results[], error
     */
    #[Hidden, Tool('<path> [verb]. Returns JSON: matched, path, verb, results[] (handler, file, lineStart, lineEnd, …), error. Exit 0 if matched, 1 if not.')]
    public function resolveAction(string $path, string $verb = '', bool $json = true): int
    {
        $verb = $verb !== '' ? strtoupper($verb) : '';

        $verbsToTry = $verb !== '' ? [$verb] : self::RESOLVE_VERBS;
        $results = [];
        foreach ($verbsToTry as $v) {
            try {
                $matcher = $this->router->match($path, $v);
                $handler = $matcher->getHandler();
                $id = $this->routeHandlerToId($handler);
                $loc = $this->handlerToLocation($handler);
                $results[] = [
                    'verb' => $v,
                    'handler' => $handler,
                    'id' => $id !== '' ? $id : null,
                    'params' => $matcher->getVariables(),
                    'file' => $loc['file'],
                    'lineStart' => $loc['lineStart'],
                    'lineEnd' => $loc['lineEnd'],
                ];
            } catch (NotFoundRouteException $e) {
                // skip this verb
            }
        }

        $matched = $results !== [];
        $requestedVerb = $verb !== '' ? $verb : null;

        if ($json) {
            $this->console->writeLn(json_encode([
                'matched' => $matched,
                'path' => $path,
                'verb' => $requestedVerb,
                'results' => $results,
                'error' => $matched ? null : 'no route matches',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $matched ? 0 : 1;
        }

        if (!$matched) {
            $this->console->writeLn('No route matches ' . ($verb !== '' ? $verb . ' ' : '') . $path . '. Use full path (e.g. time/current); list: router:list');
            return 1;
        }
        foreach ($results as $r) {
            $line = $r['verb'] . ' ' . $r['handler'] . ' ' . json_encode($r['params'], JSON_UNESCAPED_UNICODE);
            if ($r['file'] !== null) {
                $line .= ' ' . $r['file'];
                if ($r['lineStart'] !== null && $r['lineEnd'] !== null) {
                    $line .= ':' . $r['lineStart'] . '-' . $r['lineEnd'];
                }
            }
            $this->console->writeLn($line);
        }
        return 0;
    }

    /**
     * Resolve handler string (Class::method) to file path and method line range via reflection.
     *
     * @return array{file: string|null, lineStart: int|null, lineEnd: int|null}
     */
    protected function handlerToLocation(string $handler): array
    {
        $out = ['file' => null, 'lineStart' => null, 'lineEnd' => null];
        if (!str_contains($handler, '::')) {
            return $out;
        }
        [$class, $method] = explode('::', $handler, 2);
        $class = trim($class, '\\');
        $method = trim($method);
        if ($class === '' || $method === '') {
            return $out;
        }
        try {
            $rc = new ReflectionClass($class);
            $file = $rc->getFileName();
            if ($file === false) {
                return $out;
            }
            $out['file'] = $file;
            $rm = $rc->getMethod($method);
            $start = $rm->getStartLine();
            $end = $rm->getEndLine();
            if ($start > 0 && $end > 0) {
                $out['lineStart'] = $this->extendLineStartToDocOrAttributes($file, $start);
                $out['lineEnd'] = $end;
            }
        } catch (Throwable) {
            // leave out as null
        }
        return $out;
    }

    /**
     * Extend method start line upward to include PHPDoc and/or attributes above the method.
     *
     * @param string $file Absolute path to the class file
     * @param int $methodLine 1-based line of the method (function keyword)
     *
     * @return int 1-based line (unchanged or first line of docblock/attribute)
     */
    protected function extendLineStartToDocOrAttributes(string $file, int $methodLine): int
    {
        $lines = explode("\n", $this->filesystem->read($file));
        $oneBasedLastLineAbove = $methodLine - 1;
        if ($oneBasedLastLineAbove < 1) {
            return $methodLine;
        }
        $extendedStart = $methodLine;
        for ($i = $oneBasedLastLineAbove - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, '*/') || str_starts_with($trimmed, '*')) {
                continue;
            }
            if (str_starts_with($trimmed, '/**')) {
                $extendedStart = $i + 1;
                continue;
            }
            if (str_starts_with($trimmed, '#[')) {
                $extendedStart = $i + 1;
                continue;
            }
            break;
        }
        return $extendedStart;
    }

    /**
     * Prints routes with an optional verb/path filter.
     *
     * Filter matching uses substring or wildcards `* ?`, same as `_conventions`.
     * Filter matches against "verb fullPattern" (e.g. GET, /api, users).
     *
     * @param string $filter Optional: match verb or path (substring or * ?)
     * @param bool $json Output machine-readable JSON (AI/scripts)
     */
    #[Tool('[filter]. Returns JSON: prefix, routes[] (each: verb, pattern, id, handler). Filter matches verb or path.')]
    public function listAction(string $filter = '', bool $json = true): int
    {
        // Step 1: Get all routes from router
        $routes = $this->router->getAllRoutes();
        $routerPrefix = $this->router->getPrefix();

        // Early return if no routes registered
        if (empty($routes)) {
            if ($json) {
                $this->console->writeLn(json_encode(['prefix' => $routerPrefix, 'routes' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return 0;
            }
            $this->console->writeLn('No routes registered.');
            return 0;
        }

        // Step 2: Filter by filter (matches "verb fullPattern", same as _conventions)
        if ($filter !== '') {
            $routes = array_filter($routes, function ($route) use ($routerPrefix, $filter) {
                $fullPattern = $routerPrefix !== '' ? $routerPrefix . $route['pattern'] : $route['pattern'];
                $subject = $route['verb'] . ' ' . $fullPattern;
                return $this->filterMatcher->match($filter, $subject);
            });
        }

        // Step 3: Sort routes by pattern first, then by verb
        usort($routes, static function ($a, $b) use ($routerPrefix) {
            $patternA = $routerPrefix !== '' ? $routerPrefix . $a['pattern'] : $a['pattern'];
            $patternB = $routerPrefix !== '' ? $routerPrefix . $b['pattern'] : $b['pattern'];
            $patternCompare = strcmp($patternA, $patternB);

            // If patterns are equal, sort by verb
            if ($patternCompare === 0) {
                return strcmp($a['verb'], $b['verb']);
            }

            return $patternCompare;
        });

        if ($json) {
            $out = [];
            foreach ($routes as $route) {
                $fullPattern = $routerPrefix !== '' ? $routerPrefix . $route['pattern'] : $route['pattern'];
                $id = $this->routeHandlerToId($route['handler']);
                $out[] = [
                    'verb' => $route['verb'],
                    'pattern' => $fullPattern,
                    'id' => $id !== '' ? $id : null,
                    'handler' => $route['handler'],
                ];
            }
            $this->console->writeLn(json_encode(['prefix' => $routerPrefix, 'routes' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        // Step 5: Group routes by pattern for display
        // This shows routes with same pattern but different methods together
        $routesByPattern = [];
        foreach ($routes as $route) {
            $fullPattern = $routerPrefix !== '' ? $routerPrefix . $route['pattern'] : $route['pattern'];
            if (!isset($routesByPattern[$fullPattern])) {
                $routesByPattern[$fullPattern] = [];
            }
            $routesByPattern[$fullPattern][] = $route;
        }

        // Sort patterns alphabetically for consistent output
        ksort($routesByPattern);

        // Sort verbs within each pattern group
        foreach ($routesByPattern as $pattern => $patternRoutes) {
            usort($routesByPattern[$pattern], static function ($a, $b) {
                return strcmp($a['verb'], $b['verb']);
            });
        }

        // Step 6: Display router prefix if configured
        if ($routerPrefix !== '') {
            $this->console->writeLn(
                $this->console->colorize('Router Prefix: ', Colors::FC_CYAN | Colors::AT_BOLD) .
                $routerPrefix
            );
            $this->console->writeLn();
        }

        // Step 7: Display filter information if active
        if ($filter !== '') {
            $this->console->writeLn(
                $this->console->colorize('Filtered by: ', Colors::FC_YELLOW | Colors::AT_BOLD) .
                $filter
            );
            $this->console->writeLn();
        }

        // Step 8: Calculate column widths for aligned display
        $verbWidth = 0;
        $patternWidth = 0;
        $idWidth = 0;
        foreach ($routesByPattern as $fullPattern => $patternRoutes) {
            $patternWidth = max($patternWidth, strlen($fullPattern));
            foreach ($patternRoutes as $route) {
                $verbWidth = max($verbWidth, strlen($route['verb']));

                // Calculate ID from handler (never throws exception)
                $id = $this->routeHandlerToId($route['handler']);
                if ($id === '') {
                    $id = '-';
                }
                $idWidth = max($idWidth, strlen($id));
            }
        }

        // Ensure minimum widths for headers
        $verbWidth = max($verbWidth, 6); // 'VERB' header width
        $patternWidth = max($patternWidth, 12); // 'PATTERN' header width
        $idWidth = max($idWidth, 2); // 'ID' header width

        // Step 9: Display table header
        $this->console->writeLn(
            $this->console->colorize(str_pad('VERB', $verbWidth), Colors::FC_CYAN | Colors::AT_BOLD) .
            '  ' .
            $this->console->colorize(str_pad('PATTERN', $patternWidth), Colors::FC_CYAN | Colors::AT_BOLD) .
            '  ' .
            $this->console->colorize(str_pad('ID', $idWidth), Colors::FC_CYAN | Colors::AT_BOLD) .
            '  ' .
            $this->console->colorize('HANDLER', Colors::FC_CYAN | Colors::AT_BOLD)
        );
        $this->console->writeLn(str_repeat('-', $verbWidth + $patternWidth + $idWidth + 30));

        // Step 10: Display routes grouped by pattern
        $nonStandardCount = 0;
        foreach ($routesByPattern as $fullPattern => $patternRoutes) {
            foreach ($patternRoutes as $route) {
                $routeVerb = $route['verb'];

                // Color-code HTTP verb for easy identification
                $verbDisplay = $this->console->colorize(
                    str_pad($routeVerb, $verbWidth),
                    $this->getVerbColor($routeVerb)
                );

                $patternDisplay = str_pad($fullPattern, $patternWidth);

                // Generate ID from handler (always returns valid ID or '-')
                $id = $this->routeHandlerToId($route['handler']);

                // Check if pattern and ID match (standard route pattern)
                // Pattern should match the format like /area/controller/action
                // ID should match like area.controller::action
                $patternMatchesId = $this->patternMatchesId($fullPattern, $id);

                // Highlight non-standard routes in yellow
                if (!$patternMatchesId && $id !== '-') {
                    $nonStandardCount++;
                    $idDisplay = $this->console->colorize(str_pad($id, $idWidth), Colors::FC_YELLOW);
                } else {
                    $idDisplay = str_pad($id, $idWidth);
                }

                $this->console->writeLn(
                    $verbDisplay . '  ' .
                    $patternDisplay . '  ' .
                    $idDisplay . '  ' .
                    $route['handler']
                );
            }
        }

        // Step 11: Display summary statistics
        $this->console->writeLn();
        $this->console->writeLn(
            sprintf(
                'Total: %d route(s)',
                count($routes)
            )
        );

        if ($nonStandardCount > 0) {
            $this->console->writeLn(
                sprintf(
                    'Non-standard: %d route(s)',
                    $nonStandardCount
                )
            );
        }

        return 0;
    }

    /**
     * Outputs the configured router prefix for scripts or diagnostics.
     *
     * @param bool $json Machine-readable: {"prefix":"/api"} or {"prefix":""}
     */
    #[Hidden, Tool('Returns JSON: prefix (JSON or plain).')]
    public function prefixAction(bool $json = true): int
    {
        $prefix = $this->router->getPrefix();
        if ($json) {
            $this->console->writeLn(json_encode(['prefix' => $prefix], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 0;
        }
        $this->console->writeLn($prefix !== '' ? $prefix : '(empty)');
        return 0;
    }

    /**
     * Converts a route handler string to a kebab-style identifier.
     *
     * Follows the same rule as {@see \Switon\Routing\HandlerId::getId()}.
     */
    protected function routeHandlerToId(string $handler): string
    {
        [$controller, $action] = explode('::', $handler, 2);

        return ClassName::dotId($controller, compact: true) . '::' . Naming::kebab(basename($action, 'Action'));
    }

    /**
     * Checks whether a route pattern follows the same structure as its handler ID.
     */
    protected function patternMatchesId(string $pattern, string $id): bool
    {
        // If ID is invalid, no match
        if ($id === '-' || $id === '') {
            return false;
        }

        // Skip patterns with parameters (not standard routes)
        if (str_contains($pattern, '{')) {
            return false;
        }

        // Remove leading slash and split pattern into parts
        $patternParts = array_values(array_filter(explode('/', trim($pattern, '/'))));

        // Split ID into controller and action parts
        if (!str_contains($id, '::')) {
            // ID without action (only controller)
            $idController = $id;
            $idAction = 'index';
        } else {
            [$idController, $idAction] = explode('::', $id, 2);
        }

        // ID controller should match pattern parts (area.controller)
        $idControllerParts = explode('.', $idController);

        // Pattern should have at least as many parts as ID controller parts
        if (count($patternParts) < count($idControllerParts)) {
            return false;
        }

        // Compare pattern parts with ID controller parts
        foreach ($idControllerParts as $index => $idPart) {
            if (!isset($patternParts[$index]) || $patternParts[$index] !== $idPart) {
                return false;
            }
        }

        // Check action part
        $patternActionIndex = count($idControllerParts);
        if (isset($patternParts[$patternActionIndex])) {
            $patternAction = $patternParts[$patternActionIndex];
            // Pattern action must match ID action
            if ($patternAction !== $idAction) {
                return false;
            }
            // Should not have extra parts after action
            if (count($patternParts) > $patternActionIndex + 1) {
                return false;
            }
        } else {
            // No action in pattern, ID action should be 'index' (default)
            if ($idAction !== 'index') {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns display color flags for one HTTP verb.
     */
    protected function getVerbColor(string $verb): int
    {
        return match ($verb) {
            'GET' => Colors::FC_GREEN,
            'POST' => Colors::FC_BLUE,
            'PUT' => Colors::FC_YELLOW,
            'PATCH' => Colors::FC_YELLOW,
            'DELETE' => Colors::FC_RED,
            'HEAD' => Colors::FC_MAGENTA,
            'OPTIONS' => Colors::FC_CYAN,
            '*' => Colors::FC_WHITE,
            default => Colors::FC_WHITE,
        };
    }
}
