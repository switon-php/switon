<?php

declare(strict_types=1);

namespace App\Areas\Admin\Controller;

use App\Entity\Admin;
use App\Areas\Admin\Input\PasswordChangeInput;
use App\Controller\Controller;
use App\Repository\AdminRepository;
use Exception;
use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\Attribute\CaptchaRequired;
use Switon\Core\App;
use Switon\Core\Attribute\Autowired;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Http\ResponseInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Http\UrlGeneratorInterface;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewMapping;
use Switon\Routing\Attribute\ViewPostMapping;
use Switon\Session\SessionInterface;
use Switon\Validating\Attribute\NotEmpty;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/admin/password')]
class PasswordController extends Controller
{
    #[Autowired] protected App $app;
    #[Autowired] protected CaptchaInterface $captcha;
    #[Autowired] protected AdminRepository $adminRepository;
    #[Autowired] protected UrlGeneratorInterface $urlGenerator;
    #[Autowired] protected SessionInterface $session;

    #[GetMapping]
    public function captchaAction(): array
    {
        return $this->captcha->create();
    }

    #[ViewMapping]
    public function forgetAction(): array
    {
        $vars = [];

        $vars['redirect'] = $this->request->get('redirect', $this->urlGenerator->generate('/'));
        $vars['admin_name'] = $this->cookies->get('admin_name');
        $vars['i18n'] = [
            'title' => t('auth.forget_title'),
            'account' => t('auth.account'),
            'username' => t('auth.username'),
            'email' => t('auth.email'),
            'captcha' => t('auth.captcha'),
            'submit' => t('auth.password_forgot'),
            'error' => t('common.error'),
        ];

        return $vars;
    }

    #[CaptchaRequired]
    #[PostMapping('forget')]
    public function doForgetAction(string $admin_name, string $email): ResponseInterface|string
    {
        $admin = $this->adminRepository->first(['admin_name' => $admin_name]);
        if (!$admin || $admin->email !== $email) {
            return t('auth.account_email_mismatch');
        }

        $token = jwt_encode(['admin_name' => $admin_name], 600, 'admin.password.forget');

        $this->mailer->compose()
            ->setSubject(t('mail.reset_password.subject', ['app_name' => $this->app->name()]))
            ->setTo($email)
            ->setHtmlBody(
                ['@app/Areas/Admin/View/Mail/ResetPassword', 'email' => $email, 'admin_name' => $admin_name,
                    'token' => $token]
            )
            ->send();
        return $this->response->json(['code' => 0, 'msg' => t('auth.submit_reset_link_sent')]);
    }

    #[ViewMapping]
    public function resetAction(): array
    {
        $i18n = [
            'title' => t('auth.reset_title'),
            'token_expired' => t('auth.token_expired'),
            'reset_again' => t('auth.reset_again'),
            'username' => t('auth.username'),
            'account' => t('auth.account'),
            'password' => t('auth.password'),
            'submit' => t('auth.reset'),
            'error' => t('common.error'),
        ];

        $token = $this->request->get('token');
        try {
            $claims = jwt_decode($token, 'admin.password.forget');
        } catch (Exception $exception) {
            return ['expired' => true, 'token' => $token, 'i18n' => $i18n];
        }

        return ['expired' => false,
            'admin_name' => $claims['admin_name'],
            'token' => $token,
            'i18n' => $i18n,
        ];
    }

    #[PostMapping('reset')]
    public function doResetAction(string $token, #[NotEmpty(label: 'auth.password')] string $password): string|ResponseInterface
    {
        try {
            $claims = jwt_decode($token, 'admin.password.forget');
        } catch (Exception $exception) {
            return t('auth.reset_token_expired');
        }

        $admin_name = $claims['admin_name'];

        $admin = $this->adminRepository->firstOrFail(['admin_name' => $admin_name]);
        $admin->password = $password;
        $this->adminRepository->update($admin);

        return $this->response->json(['code' => 0, 'msg' => t('auth.submit_reset_success')]);
    }

    #[Authorize(Authorize::AUTHENTICATED)]
    #[ViewPostMapping]
    public function changeAction(PasswordChangeInput $input, Admin $admin): int|string
    {
        if (!$admin->verifyPassword($input->old_password)) {
            return t('auth.old_password_incorrect');
        }

        $admin->password = $input->new_password;
        $this->adminRepository->update($admin);
        $this->session->destroy();

        return 0;
    }
}
