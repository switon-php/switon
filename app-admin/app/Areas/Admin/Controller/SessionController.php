<?php

declare(strict_types=1);

namespace App\Areas\Admin\Controller;

use App\Controller\Controller;
use App\Entity\Admin;
use App\Entity\AdminLoginLog;
use App\Repository\AdminLoginLogRepository;
use App\Repository\AdminRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Captcha\CaptchaInterface;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RandomInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\Server\Network;
use Switon\Http\UrlGeneratorInterface;
use App\Areas\Rbac\Repository\AdminRoleRepository;
use App\Areas\Rbac\Repository\RoleRepository;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewMapping;
use Switon\Session\SessionInterface;

use function substr;
use function trim;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/admin/session')]
class SessionController extends Controller
{
    protected const LOGIN_FAIL_LIMIT = 5;
    protected const LOGIN_LOCK_SECONDS = 900;
    protected const ERROR_AUTH_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';
    protected const ERROR_AUTH_LOGIN_LOCKED = 'AUTH_LOGIN_LOCKED';
    protected const ERROR_AUTH_ACCOUNT_INACTIVE = 'AUTH_ACCOUNT_INACTIVE';
    protected const ERROR_AUTH_ACCOUNT_LOCKED = 'AUTH_ACCOUNT_LOCKED';
    protected const ERROR_AUTH_IP_NOT_WHITELISTED = 'AUTH_IP_NOT_WHITELISTED';

    #[Autowired] protected AppInterface $app;
    #[Autowired] protected CaptchaInterface $captcha;
    #[Autowired] protected AdminRepository $adminRepository;
    #[Autowired] protected RoleRepository $roleRepository;
    #[Autowired] protected AdminRoleRepository $adminRoleRepository;
    #[Autowired] protected AdminLoginLogRepository $adminLoginLogRepository;
    #[Autowired] protected UrlGeneratorInterface $urlGenerator;
    #[Autowired] protected SessionInterface $session;
    #[Autowired] protected RandomInterface $random;

    #[GetMapping]
    public function captchaAction(): array
    {
        return $this->captcha->create();
    }

    #[ViewMapping('/login')]
    public function loginAction(): array
    {
        $vars = [];

        $vars['redirect'] = $this->request->get('redirect', $this->urlGenerator->generate('/'));
        $vars['admin_name'] = $this->cookies->get('admin_name');
        $vars['app_name'] = trim($this->app->name()) !== '' ? $this->app->name() : 'Switon Admin';
        $vars['app_id'] = $this->app->id();
        $vars['i18n'] = [
            'title' => t('auth.login_title'),
            'subtitle' => t('auth.login_subtitle'),
            'account' => t('auth.account'),
            'username' => t('auth.username'),
            'password' => t('auth.password'),
            'remember_me' => t('auth.remember_me'),
            'login' => t('auth.login'),
            'forgot' => t('auth.password_forgot'),
            'caps_lock_warning' => t('auth.caps_lock_warning'),
            'invalid_credentials' => t('auth.invalid_credentials'),
            'network_error' => t('common.network_error_retry'),
            'login_failed_retry' => t('auth.login_failed_retry'),
            'login_retry_hint' => t('auth.login_retry_hint', ['count' => '{count}']),
            'login_locked_hint' => t('auth.login_locked_hint', ['minutes' => '{minutes}']),
            'ip_not_whitelisted_hint' => t('auth.ip_not_whitelisted_hint'),
        ];

        return $vars;
    }

    #[PostMapping('/login')]
    public function doLoginAction(string $admin_name, string $password): ResponseInterface|AdminLoginLog
    {
        $udid = $this->cookies->get('CLIENT_UDID');
        if (!$udid) {
            $udid = $this->random->chars(16);
            $this->cookies->set('CLIENT_UDID', $udid, strtotime('10 year'), '/');
        }

        $admin = $this->adminRepository->first(['admin_name' => $admin_name]);
        if (!$admin) {
            return $this->loginError(
                self::ERROR_AUTH_INVALID_CREDENTIALS,
                t('auth.invalid_credentials'),
                'password'
            );
        }

        $now = time();
        if ($admin->isTemporarilyLocked($now)) {
            $minutes = (int)ceil(($admin->locked_until - $now) / 60);
            return $this->loginError(
                self::ERROR_AUTH_LOGIN_LOCKED,
                t('auth.login_locked', ['minutes' => $minutes]),
                'password',
                ['minutes' => $minutes]
            );
        }

        if (!$admin->verifyPassword($password)) {
            return $this->recordFailedLogin($admin, $now);
        }

        if ($admin->status === Admin::STATUS_INIT) {
            return $this->loginError(
                self::ERROR_AUTH_ACCOUNT_INACTIVE,
                t('auth.account_inactive')
            );
        } elseif ($admin->status === Admin::STATUS_LOCKED) {
            return $this->loginError(
                self::ERROR_AUTH_ACCOUNT_LOCKED,
                t('auth.account_locked')
            );
        }

        $client_ip = $this->request->ip();

        if (!Network::contains($admin->white_ip, $client_ip)) {
            return $this->loginError(
                self::ERROR_AUTH_IP_NOT_WHITELISTED,
                t('auth.ip_not_whitelisted', ['ip' => $client_ip]),
                '',
                ['ip' => $client_ip]
            );
        }

        if ($admin->admin_id === 1) {
            $roles = [Authorize::SUPERUSER];
        } else {
            $roles = $this->adminRoleRepository->values(['admin_id' => $admin->admin_id], 'role_code');
            $roles = $this->roleRepository->values(['enabled' => 1, 'role_code' => $roles], 'role_code');
        }

        $identity = ['id' => $admin->admin_id, 'name' => $admin->admin_name, 'roles' => $roles];
        $this->identity->set($identity['id'], $identity['name'], $identity['roles']);
        $this->session->set('auth', $identity);

        $session_id = $this->session->getId();
        if ($admin->session_id && $session_id !== $admin->session_id) {
            $this->session->destroy($admin->session_id);
        }

        $admin->login_ip = $client_ip;
        $admin->login_time = $now;
        $admin->session_id = $session_id;
        $admin->fail_count = 0;
        $admin->failed_at = 0;
        $admin->locked_until = 0;
        $this->adminRepository->update($admin);

        $adminLoginLog = new AdminLoginLog();

        $adminLoginLog->admin_id = $admin->admin_id;
        $adminLoginLog->admin_name = $admin->admin_name;
        $adminLoginLog->client_ip = $client_ip;
        $adminLoginLog->client_udid = $udid;
        $adminLoginLog->user_agent = substr($this->request->header('user-agent'), 0, 255);

        return $this->adminLoginLogRepository->create($adminLoginLog);
    }

    protected function recordFailedLogin(Admin $admin, int $now): ResponseInterface
    {
        $admin->fail_count = (int)$admin->fail_count + 1;
        $admin->failed_at = $now;
        $limit = static::LOGIN_FAIL_LIMIT;
        if ($admin->fail_count >= $limit) {
            $admin->locked_until = $now + static::LOGIN_LOCK_SECONDS;
            $admin->fail_count = 0;
        }
        $this->adminRepository->update($admin);

        if ($admin->locked_until > $now) {
            $minutes = (int)ceil(($admin->locked_until - $now) / 60);
            return $this->loginError(
                self::ERROR_AUTH_LOGIN_LOCKED,
                t('auth.login_locked', ['minutes' => $minutes]),
                'password',
                ['minutes' => $minutes]
            );
        }

        $remain = $limit - (int)$admin->fail_count;
        return $this->loginError(
            self::ERROR_AUTH_INVALID_CREDENTIALS,
            $remain > 0
                ? t('auth.invalid_credentials_with_retry', ['count' => $remain])
                : t('auth.invalid_credentials'),
            'password',
            $remain > 0 ? ['count' => $remain] : []
        );
    }

    /**
     * @param array<string, scalar|null> $meta
     */
    protected function loginError(string $error, string $message, string $field = '', array $meta = []): ResponseInterface
    {
        $payload = [
            'code' => 1,
            'error' => $error,
            'msg' => $message,
        ];
        if ($field !== '') {
            $payload['field'] = $field;
        }
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $this->response->json($payload);
    }

    #[Authorize(Authorize::AUTHENTICATED)]
    #[GetMapping(['/logout', '/admin/session/logout'])]
    public function logoutAction(): ResponseInterface
    {
        $this->identity->clear();
        $this->session->destroy();

        return $this->response->redirect('/');
    }
}
