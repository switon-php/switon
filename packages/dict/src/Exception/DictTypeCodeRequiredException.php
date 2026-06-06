<?php

declare(strict_types=1);

namespace Switon\Dict\Exception;

use Switon\Dict\Exception;

/**
 * Use when Dict::typeCode() returns no usable type code.
 */
class DictTypeCodeRequiredException extends Exception
{
}
