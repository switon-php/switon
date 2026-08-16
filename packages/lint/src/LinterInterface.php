<?php

declare(strict_types=1);

namespace Switon\Lint;

/**
 * Single-file lint for agent feedback (structured diagnostics).
 *
 * Default is fast syntax-only. Pass `$deep = true` for analyzers (PHPStan / ESLint / `go vet`).
 *
 * Road-signs:
 * - Consumer: {@see \Switon\Tooling\Tool\LintTool} exposes this as an agent tool
 * - Runners: {@see Process} shells out per language; driver choice follows the file extension
 * - Output: structured `errors` (file/line/message) rather than raw stderr
 *
 * @see \Switon\Lint\Linter
 */
interface LinterInterface
{
    /** True when a driver owns this path's extension and the file exists. */
    public function supports(string $path): bool;

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

    /**
     * Attach syntax-only lint report(s) onto a mutating-tool payload when drivers support the path(s).
     *
     * @param array<string, mixed> $payload
     * @param string|list<string> $paths
     *
     * @return array<string, mixed>
     */
    public function attach(array $payload, string|array $paths, callable $shouldAbort): array;
}
