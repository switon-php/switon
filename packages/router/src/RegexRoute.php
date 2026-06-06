<?php

declare(strict_types=1);

namespace Switon\Routing;

use JsonSerializable;

use function is_string;
use function preg_match;

/**
 * Matches one dynamic route using a compiled regex pattern.
 *
 * Use when a route contains placeholders and must return extracted variables on match.
 *
 * @see \Switon\Routing\RouteInterface
 * @see \Switon\Routing\Matcher
 * @see \Switon\Routing\RouterInterface::match()
 */
class RegexRoute implements RouteInterface, JsonSerializable
{
    /** Original route pattern. */
    protected string $pattern;

    /** Compiled regex pattern. */
    protected string $compiled;

    /** Route handler string. */
    protected string $handler;

    /** Allowed HTTP verb, or <code>*</code>. */
    protected string $verb;

    /**
     * @param string $verb Allowed HTTP verb.
     * @param string $pattern Route pattern.
     * @param string $compiled Compiled regex.
     * @param string $handler Route handler.
     */
    public function __construct(string $verb, string $pattern, string $compiled, string $handler)
    {
        $this->verb = $verb;
        $this->pattern = $pattern;
        $this->compiled = $compiled;
        $this->handler = $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function match(string $uri, string $verb = 'GET'): ?MatcherInterface
    {
        // Performance optimization: Check HTTP method first (fast fail)
        // Avoids expensive regex matching for wrong HTTP method
        // Time saved: ~0.01-0.1ms per failed method check
        if ($verb !== $this->verb && $this->verb !== '*') {
            return null;
        }

        // Apply compiled regex pattern to URI
        // Performance: O(n) where n is URI length
        // Typical time: 0.01-0.1ms depending on pattern complexity
        // Failed matches: ~0.01ms (regex engine fails fast)
        if (preg_match($this->compiled, $uri, $matches) === 1) {
            // Extract named parameters from regex capture groups
            // Only string keys are parameter names (numeric keys are ignored)
            // Performance: O(m) where m is number of matches
            $extractedParameters = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $extractedParameters[$key] = $value;
                }
            }
            return new Matcher($this->handler, $extractedParameters);
        }

        // No match: URI doesn't match pattern
        return null;
    }

    /**
     * @return array{pattern: string, compiled: string, handler: string, verb: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'pattern' => $this->pattern,
            'compiled' => $this->compiled,
            'handler' => $this->handler,
            'verb' => $this->verb,
        ];
    }
}
