<?php

declare(strict_types=1);

namespace Switon\LlmHtml;

/**
 * How much chrome is gone from {@see LlmHtmlResult::$content}.
 *
 * `original` is the input string. `cleaned` has hard noise removed. `pruned` has structural chrome removed as well.
 */
enum Cleanup: string
{
    case Original = 'original';
    case Cleaned = 'cleaned';
    case Pruned = 'pruned';
}
