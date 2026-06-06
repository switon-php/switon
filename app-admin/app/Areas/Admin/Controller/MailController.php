<?php

declare(strict_types=1);

namespace App\Areas\Admin\Controller;

use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewPostMapping;
use Switon\Viewing\ViewInterface;

#[Authorize]
#[RequestMapping('/admin/mail')]
class MailController extends Controller
{
    #[Autowired] protected ViewInterface $view;

    #[ViewPostMapping]
    public function resetPasswordAction(): void
    {
        $this->view->setVars(
            [
                'admin_name' => 'switon',
                'token'      => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX25hbWUiOiJhZG1pbiIsInNjb3BlIjoiYWRtaW4udXNlci5wYXNzd29yZC5mb3JnZXQiLCJpYXQiOjE1NTQzNzM0MDgsImV4cCI6MTU1NDM3MzcwOH0.aMlRI9SAnW_6L4Qo89cmYF1kOyGZXmiPWj_3cNiRA9g',
                'email'      => 'test@qq.com',
            ]
        );
    }
}
