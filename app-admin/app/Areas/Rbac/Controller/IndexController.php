<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Controller;

use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Http\ResponseInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class IndexController extends Controller
{
    #[Authorize(Authorize::AUTHENTICATED)]
    #[GetMapping('/rbac')]
    public function indexAction(): ResponseInterface
    {
        return $this->response->redirect('permission/');
    }
}
