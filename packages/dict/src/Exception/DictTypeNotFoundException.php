<?php

declare(strict_types=1);

namespace Switon\Dict\Exception;

use Switon\Dict\Exception;

/**
 * Use when the configured dict type does not exist or is not currently readable.
 */
class DictTypeNotFoundException extends Exception
{
}
