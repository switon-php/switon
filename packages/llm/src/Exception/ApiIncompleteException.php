<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

use Switon\Llm\Exception;

/** Use when an instruct returns without ending in {@see Instructor::RESPONSE}. */
class ApiIncompleteException extends Exception
{
}
