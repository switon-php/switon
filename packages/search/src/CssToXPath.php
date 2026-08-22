<?php

declare(strict_types=1);

namespace Switon\Search;

use function count;
use function explode;
use function implode;
use function is_string;
use function ltrim;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strtr;
use function trim;

/** CSS to XPath. `$relative` queries from the current item (`.//`) instead of `//`. */
class CssToXPath
{
    public function transform(string $path, bool $relative = false): string
    {
        $path = $this->transformInternal($path, $relative);
        if (!str_contains($path, ':')) {
            return $path;
        }

        $path = $this->replaceCallback(
            '#:(eq|gt|lt)\((-?\d+)\)#',
            static function (array $match): string {
                $word = $match[1];
                $index = (int) $match[2];
                if ($word === 'eq') {
                    if ($index >= 0) {
                        return '[' . ($index + 1) . ']';
                    }
                    $fromEnd = $index + 1;

                    return $fromEnd === 0 ? '[last()]' : '[last()' . $fromEnd . ']';
                }
                if ($word === 'gt' || $word === 'lt') {
                    $operator = $word === 'gt' ? '>' : '<';
                    if ($index >= 0) {
                        return '[position()' . $operator . ($index + 1) . ']';
                    }

                    return '[position()' . $operator . "last()$index]";
                }

                return '';
            },
            $path,
        );

        $path = $this->replace(['#:contains\((["\'])([^\'"]+)\\1\)#'], ["[contains(.,'\\2')]"], $path);

        return strtr(
            $path,
            [
                ':header' => '*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]',
                ':first' => '[1]',
                ':last' => '[last()]',
                ':even' => '[position() mod 2 = 0]',
                ':odd' => '[position() mod 2 = 1]',
                ':contains(' => 'contains(.,',
                ':not(' => 'not(',
                ':empty' => '[not(* or text())]',
                ':only-child' => '[last()=1]',
                ':first-child' => '[position()=1]',
                ':last-child' => '[position()=last()]',
            ],
        );
    }

    protected function transformInternal(string $path, bool $relative): string
    {
        if (str_contains($path, ',')) {
            $expressions = [];
            foreach (explode(',', $path) as $part) {
                $expressions[] = $this->transform(trim($part), $relative);
            }

            return implode('|', $expressions);
        }

        $paths = [$relative ? './/' : '//'];
        $path = $this->replace('|\s+>\s+|', '>', $path);
        $segments = preg_split('/\s+/', $path);
        if ($segments === false) {
            Exception::raise('CSS selector {selector} could not be split', ['selector' => $path]);
        }

        foreach ($segments as $key => $segment) {
            $pathSegment = self::tokenize($segment);
            if ($key === 0) {
                if (str_starts_with($pathSegment, '[contains(')) {
                    $paths[0] .= '*' . ltrim($pathSegment, '*');
                } else {
                    $paths[0] .= $pathSegment;
                }
                continue;
            }
            if (str_starts_with($pathSegment, '[contains(')) {
                foreach ($paths as $pathKey => $xpath) {
                    $paths[$pathKey] .= '//*' . ltrim($pathSegment, '*');
                    $paths[] = $xpath . $pathSegment;
                }
            } else {
                foreach ($paths as $pathKey => $xpath) {
                    $paths[$pathKey] .= '//' . $pathSegment;
                }
            }
        }

        if (count($paths) === 1) {
            return $paths[0];
        }

        return implode('|', $paths);
    }

    protected static function tokenize(string $expression): string
    {
        $expression = str_replace('>', '/', $expression);

        $expression = self::replace('|#([a-z][\w\-]*)|i', "[@id='\$1']", $expression);
        $expression = self::replace('|(?<![\w\-])(\[@id=)|i', '*$1', $expression);

        $expression = self::replaceCallback(
            '#\[@?([\w\-]*)([~*^$|!])?=([^\[]+)\]#',
            static function (array $matches): string {
                $attr = strtolower($matches[1]);
                $type = $matches[2];
                $val = trim($matches[3], "'\" \t");
                $field = $attr === '' ? 'text()' : '@' . $attr;

                $items = [];
                foreach (explode(str_contains($val, '|') ? '|' : '&', $val) as $word) {
                    if ($type === '') {
                        $items[] = "$field='$word'";
                    } elseif ($type === '~') {
                        $items[] = "contains(concat(' ', normalize-space($field), ' '), ' $word ')";
                    } elseif ($type === '|') {
                        $item = "$field='$word' or starts-with($field,'$word-')";
                        $items[] = $word === $val ? $item : "($item)";
                    } elseif ($type === '!') {
                        $item = "not($field) or $field!='$word'";
                        $items[] = $word === $val ? $item : "($item)";
                    } else {
                        $operators = ['*' => 'contains', '^' => 'starts-with', '$' => 'ends-with'];
                        $items[] = $operators[$type] . "($field, '$word')";
                    }
                }

                return '[' . implode(str_contains($val, '|') ? ' or ' : ' and ', $items) . ']';
            },
            $expression,
        );

        $expression = self::replaceCallback(
            '#\[(!?)([a-z][\w\-|&]*)\]#i',
            static function (array $matches): string {
                $val = $matches[2];
                $op = str_contains($val, '|') ? '|' : '&';
                $items = [];
                foreach (explode($op, $val) as $word) {
                    $items[] = '@' . $word;
                }
                $predicate = '[' . implode($op === '|' ? ' or ' : ' and ', $items) . ']';

                return $matches[1] === '!' ? "not($predicate)" : $predicate;
            },
            $expression,
        );

        if (!str_contains($expression, '[@')) {
            $expression = self::replace(
                '|\.([a-z][\w\-]*)|i',
                "[contains(concat(' ', normalize-space(@class), ' '), ' \$1 ')]",
                $expression,
            );
        }

        return str_replace('**', '*', $expression);
    }

    /**
     * @param list<string> $pattern
     * @param list<string> $replacement
     */
    protected static function replace(array|string $pattern, array|string $replacement, string $subject): string
    {
        $replaced = preg_replace($pattern, $replacement, $subject);
        if (!is_string($replaced)) {
            Exception::raise('CSS selector {selector} could not be converted', ['selector' => $subject]);
        }

        return $replaced;
    }

    /** @param callable(array<int|string, string>): string $callback */
    protected static function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $replaced = preg_replace_callback($pattern, $callback, $subject);
        if (!is_string($replaced)) {
            Exception::raise('CSS selector {selector} could not be converted', ['selector' => $subject]);
        }

        return $replaced;
    }
}
