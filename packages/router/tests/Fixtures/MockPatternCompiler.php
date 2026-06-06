<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Switon\Routing\PatternCompilerInterface;

/**
 * Mock pattern compiler implementation for router tests.
 */
class MockPatternCompiler implements PatternCompilerInterface
{
    protected array $compiledPatterns = [];

    public function compile(string $pattern): string
    {
        if (isset($this->compiledPatterns[$pattern])) {
            return $this->compiledPatterns[$pattern];
        }

        // Simple mock compilation - convert {param} or {param:regex} to regex
        $compiled = $pattern;

        // Handle {param:regex} format (e.g., {id:[-\w]+})
        $compiled = preg_replace_callback(
            '#\{([^}:]+)(?::([^}]+))?\}#',
            function ($matches) {
                $paramName = $matches[1];
                $regexPattern = $matches[2] ?? '[^/]+';
                return '(?<' . $paramName . '>' . $regexPattern . ')';
            },
            $compiled
        );

        $compiled = '#^' . $compiled . '$#';

        $this->compiledPatterns[$pattern] = $compiled;
        return $compiled;
    }

    // Test helper method
    public function setCompiledPattern(string $pattern, string $compiled): void
    {
        $this->compiledPatterns[$pattern] = $compiled;
    }
}
