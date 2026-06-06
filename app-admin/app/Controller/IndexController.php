<?php

declare(strict_types=1);

namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewMapping;

#[Authorize(Authorize::AUTHENTICATED)]
#[RequestMapping('')]
class IndexController extends Controller
{
    #[ViewMapping('/')]
    public function indexAction(): array
    {
        return [];
    }
}
