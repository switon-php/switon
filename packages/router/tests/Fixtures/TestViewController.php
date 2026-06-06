<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[RequestMapping('/view')]
class TestViewController
{
    #[ViewGetMapping('test')]
    public function viewAction(): void
    {
    }
}
