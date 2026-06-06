<?php

declare(strict_types=1);

namespace Switon\Beacon\Exception;

use Switon\Beacon\Exception as BaseException;

/**
 * Use when Beacon class discovery cannot trust the Composer class index.
 *
 * @see \Switon\Beacon\ClassIndex
 */
class ClassIndexNotFoundException extends BaseException
{
}
