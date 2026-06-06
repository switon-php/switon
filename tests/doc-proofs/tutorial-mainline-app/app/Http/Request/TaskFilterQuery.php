<?php

declare(strict_types=1);

namespace App\Http\Request;

use Switon\Http\Attribute\RequestQuery;

#[RequestQuery]
class TaskFilterQuery
{
    public ?bool $completed = null;
}
