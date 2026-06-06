<?php

declare(strict_types=1);

namespace App\Areas\Admin\Input;

use Switon\Http\Attribute\RequestData;
use Switon\Validating\Attribute\EqualTo;
use Switon\Validating\Attribute\NotEmpty;
use Switon\Validating\Attribute\Trim;

#[RequestData]
class PasswordChangeInput
{
    #[Trim]
    #[NotEmpty(label: 'auth.password_old')]
    public string $old_password = '';

    #[Trim]
    #[NotEmpty(label: 'auth.password_new')]
    public string $new_password = '';

    #[Trim]
    #[NotEmpty(label: 'auth.password_new_confirm')]
    #[EqualTo('new_password')]
    public string $new_password_confirm = '';
}
