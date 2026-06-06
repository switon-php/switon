<?php

declare(strict_types=1);

namespace Switon\Routing;

/**
 * Defines route-pattern compilation into regex-ready match expressions.
 *
 * Use when converting attribute or manual route patterns with placeholders into matcher patterns.
 *
 * Road-signs:
 * - literal patterns may pass through unchanged
 * - placeholder syntax <code>{name}</code> / <code>{name:pattern}</code>
 * - compiled output is consumed by RegexRoute
 *
 * @see \Switon\Routing\PatternCompiler
 * @see \Switon\Routing\RegexRoute
 */
interface PatternCompilerInterface
{
    /**
     * Compiles one route pattern into a matcher-ready expression.
     */
    public function compile(string $pattern): string;
}
