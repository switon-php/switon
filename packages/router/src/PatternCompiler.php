<?php

declare(strict_types=1);

namespace Switon\Routing;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Exception\PatternInvalidException;

use function explode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_contains;
use function str_replace;

/**
 * Compiles route patterns into regex expressions used by dynamic routing.
 *
 * Use when route definitions include placeholders, snippets, or custom parameter constraints.
 *
 * Road-signs:
 * - snippets
 * - <code>{name:pattern}</code>
 * - strict (case-sensitive regex)
 * - dev validate
 * - fail PatternInvalidException
 *
 * @see \Switon\Routing\PatternCompilerInterface
 * @see \Switon\Routing\RegexRoute
 * @see \Switon\Routing\Exception\PatternInvalidException
 * @see \Switon\Core\AppInterface
 */
class PatternCompiler implements PatternCompilerInterface
{
    /** Application context for environment checks. */
    #[Autowired] protected AppInterface $app;

    /** When true, route regex is case-sensitive. */
    #[Autowired] protected bool $strict = true;
    /** @var array<string, string> Snippet replacements used during compile(). */
    #[Autowired] protected array $snippets
        = [
            '{controller}' => '{controller:var}',
            '{action}' => '{action:var}',
            '{id}' => '{id:\d+}',
            ':var}' => ':[a-zA-Z](?:[-\w]*[a-zA-Z0-9])?}',
            ':int}' => ':\d+}',
            ':uuid}' => ':[A-Fa-f0-9]{8}(-[A-Fa-f0-9]{4}){3}-[A-Fa-f0-9]{12}}',
            ':slug}' => ':[a-z0-9]+(?:-[a-z0-9]+)*}',
            ':alpha}' => ':[a-zA-Z]+}',
            ':alphanum}' => ':[a-zA-Z0-9]+}',
            ':any}' => ':.+}',
        ];

    /**
     * Expands nested snippet aliases once during initialization.
     */
    public function __construct()
    {
        foreach ($this->snippets as $key => $value) {
            // Check if snippet value contains a reference to another snippet
            if (preg_match('#:\w+}#', $value, $match) === 1) {
                $var = $match[0];
                // Replace snippet reference with actual snippet value
                if (($val = $this->snippets[$var] ?? null) !== null) {
                    $this->snippets[$key] = str_replace($var, $val, $value);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws PatternInvalidException
     */
    public function compile(string $pattern): string
    {
        // Performance optimization: Early return for literal patterns (no parameters)
        // Avoids unnecessary processing for static routes
        // Time saved: ~90% for literal routes (no regex compilation needed)
        if (!str_contains($pattern, '{')) {
            return $pattern;
        }

        // Step 1: Expand built-in snippets
        // Replaces shortcuts like {id} with {id:\d+}
        // Performance: O(n) where n is number of snippets in pattern
        // Uses strtr() for efficient multi-replacement
        $pattern = strtr($pattern, $this->snippets);

        // Step 2: Protect numeric tokens from being treated as parameters
        // Handles patterns like {1,3} in regex quantifiers
        // This prevents false positives when detecting parameters
        $shouldRestoreNumericTokens = false;
        if (preg_match('#{\d#', $pattern) === 1) {
            $shouldRestoreNumericTokens = true;
            // Temporarily replace {digits} with @digits@ to protect them
            $pattern = preg_replace('#{([\d,]+)}#', '@\1@', $pattern);
        }

        // Step 3: Convert parameter syntax to regex named capture groups
        // Transforms {name:pattern} to (?<name>pattern)
        // Performance: O(m) where m is number of parameters
        $parameterMatches = [];
        if (preg_match_all('#{([A-Z].*)}#Ui', $pattern, $parameterMatches, PREG_SET_ORDER) > 0) {
            foreach ($parameterMatches as $match) {
                // Split parameter into name and pattern parts
                $parts = explode(':', $match[1], 2);
                // Build named capture group: (?<name>pattern)
                // Default pattern is [^/]+ if not specified (matches anything except slashes)
                $namedCaptureGroup = '(?<' . $parts[0] . '>' . ($parts[1] ?? '[^/]+') . ')';
                $pattern = str_replace($match[0], $namedCaptureGroup, $pattern);
            }
        }

        // Step 4: Restore numeric tokens if they were protected
        if ($shouldRestoreNumericTokens) {
            // Convert @digits@ back to {digits}
            $pattern = preg_replace('#@([\d,]+)@#', '{\1}', $pattern);
        }

        // Step 5: Add regex anchors and case sensitivity flag
        // Format: #^pattern$# or #^pattern$#i (case-insensitive)
        // Anchors ensure full URI match (not partial)
        // Performance impact: Case-insensitive matching is ~10-20% slower
        $compiled = '#^' . $pattern . '$#' . ($this->strict ? '' : 'i');

        // Step 6: Validate compiled pattern in development mode
        // Ensures the regex is valid before using it
        // Performance: Only runs in dev mode, zero overhead in production
        // Error handling: Throws PatternInvalidException with descriptive message
        if ($this->app->isEnv('dev') && preg_match($compiled, '') === false) {
            // Pattern validation failed - regex is malformed
            // This catches syntax errors in custom patterns
            PatternInvalidException::raise('Route pattern "{pattern}" invalid.', ['pattern' => $pattern]);
        }

        return $compiled;
    }
}
