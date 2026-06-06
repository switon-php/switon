<?php

declare(strict_types=1);

namespace Switon\Routing;

/**
 * Defines the result object returned by route matching.
 *
 * Use when accessing resolved handler strings and extracted route variables.
 *
 * Road-signs:
 * - getHandler() for dispatch target
 * - getVariables() for extracted params
 * - setVariables() for post-match normalization
 *
 * @see \Switon\Routing\Matcher
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\Event\RouteParamsResolved
 */
interface MatcherInterface
{
    /**
     * Returns the resolved handler string.
     */
    public function getHandler(): string;

    /**
     * Returns extracted route variables.
     *
     * @return array<string, mixed>
     */
    public function getVariables(): array;

    /**
     * Replaces route variables after matching.
     *
     * @param array<string, mixed> $variables
     */
    public function setVariables(array $variables): void;
}
