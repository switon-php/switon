<?php

declare(strict_types=1);

namespace App\Areas\Admin\Controller;

use App\Controller\Controller;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Captcha\Attribute\CaptchaRequired;
use Switon\Captcha\CaptchaInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewPostMapping;
use Switon\Validating\Attribute\NotEmpty;
use Switon\Validating\Attribute\Trim;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/admin/account')]
class AccountController extends Controller
{
    #[Autowired] protected CaptchaInterface $captcha;
    #[Autowired] protected AdminRepository $adminRepository;

    #[PostMapping]
    public function captchaAction(): array
    {
        return $this->captcha->create();
    }

    #[CaptchaRequired]
    #[ViewPostMapping]
    public function registerAction(
        #[Trim, NotEmpty(label: 'auth.password')] string $password,
    ): Admin {
        $admin = $this->adminRepository->fill($this->request->all());

        $admin->white_ip = '*';
        $admin->status = Admin::STATUS_INIT;
        $admin->password = $password;

        return $this->adminRepository->create($admin);
    }
}
