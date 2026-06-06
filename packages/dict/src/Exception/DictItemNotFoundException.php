<?php

declare(strict_types=1);

namespace Switon\Dict\Exception;

use Switon\Dict\Exception;

/**
 * Use when one dict parameter or scalar value does not resolve to a valid item.
 */
class DictItemNotFoundException extends Exception
{
}
