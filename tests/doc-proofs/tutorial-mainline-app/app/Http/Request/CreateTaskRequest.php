<?php

declare(strict_types=1);

namespace App\Http\Request;

use Switon\Http\Attribute\RequestBody;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateTaskRequest
{
    #[Length(1, 120)]
    public string $title;

    public ?string $note = null;
}
