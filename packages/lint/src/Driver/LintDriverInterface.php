<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

/**
 * One language driver for {@see \Switon\Lint\LinterInterface} (wired by extension key on {@see \Switon\Lint\Linter::$drivers}).
 *
 * @see \Switon\Lint\Driver\PhpLintDriver
 * @see \Switon\Lint\Driver\JsLintDriver
 * @see \Switon\Lint\Driver\GoLintDriver
 * @see \Switon\Lint\Driver\BashLintDriver
 * @see \Switon\Lint\Driver\PythonLintDriver
 * @see \Switon\Lint\Driver\JsonLintDriver
 * @see \Switon\Lint\Driver\RubyLintDriver
 * @see \Switon\Lint\Driver\LuaLintDriver
 * @see \Switon\Lint\Driver\XmlLintDriver
 */
interface LintDriverInterface
{
    /**
     * @param bool $deep When true, also run project analyzers if discovered.
     *
     * @return array{
     *     path: string,
     *     tools: list<array{name: string, ok: bool}>,
     *     diagnostics: list<array{
     *         line: int,
     *         severity: string,
     *         message: string,
     *         code?: string
     *     }>
     * }
     */
    public function lint(string $path, callable $shouldAbort, bool $deep = false): array;
}
