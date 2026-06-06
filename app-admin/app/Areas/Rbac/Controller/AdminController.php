<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Controller;

use App\Controller\Controller;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use Switon\Session\SessionInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Http\RequestInterface;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use App\Areas\Rbac\Repository\AdminRoleRepository;
use App\Areas\Rbac\Repository\RoleRepository;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[Authorize]
#[RequestMapping('/rbac/admin')]
class AdminController extends Controller
{
    #[Autowired] protected AdminRepository $adminRepository;
    #[Autowired] protected RoleRepository $roleRepository;
    #[Autowired] protected AdminRoleRepository $adminRoleRepository;
    #[Autowired] protected SessionInterface $session;

    #[ViewGetMapping]
    #[Filters(['status'])]
    #[Fields([
        'admin_id', 'admin_name', 'email', 'status', 'login_ip', 'login_time', 'session_id', 'fail_count', 'failed_at', 'locked_until', 'avatar_id',
        'roles' => ['role_id', 'role_name'],
        'avatar' => ['asset_id', 'asset_name', 'asset_url', 'mime_type'],
    ])]
    #[Orders(['admin_id' => SORT_DESC])]
    #[Keyword(['admin_name', 'email'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->adminRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    public function detailAction(Admin $admin): Admin
    {
        return $admin;
    }

    #[GetMapping]
    public function listAction(): array
    {
        return $this->adminRepository->pluck([], 'admin_name');
    }

    #[GetMapping]
    #[Filters(['status'])]
    #[Fields(['admin_id', 'admin_name', 'email', 'avatar' => ['asset_id', 'asset_url']])]
    #[Orders(['admin_name' => SORT_ASC, 'admin_id' => SORT_ASC])]
    #[Keyword(['admin_name', 'email'])]
    public function optionsAction(Criteria $criteria): array
    {
        return $this->adminRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function lockAction(int $admin_id): string|Admin
    {
        if ((int) $this->identity->getId() === $admin_id) {
            return t('auth.self_lock_forbidden');
        }

        return $this->adminRepository->updateById($admin_id, ['status' => Admin::STATUS_LOCKED]);
    }

    #[PostMapping]
    public function activeAction(int $admin_id): Admin
    {
        return $this->adminRepository->updateById($admin_id, ['status' => Admin::STATUS_ACTIVE]);
    }

    #[PostMapping]
    public function unlockLoginAction(int $admin_id): string|Admin
    {
        return $this->adminRepository->updateById($admin_id, [
            'fail_count' => 0,
            'failed_at' => 0,
            'locked_until' => 0,
        ]);
    }

    #[PostMapping]
    public function kickAction(Admin $admin): string|Admin
    {
        if ((int) $this->identity->getId() === $admin->admin_id) {
            return t('auth.self_kick_forbidden');
        }

        if ($admin->session_id !== '') {
            $this->session->destroy($admin->session_id);
        }

        return $this->adminRepository->updateById($admin->admin_id, [
            'session_id' => '',
        ]);
    }

    #[PostMapping]
    public function createAction(RequestInterface $request, ?int $role_id = 0): Admin
    {
        $admin = $this->adminRepository->create($request->all());

        if ($role_id > 0) {
            $this->adminRoleRepository->attach(Admin::class, $admin->admin_id, [$role_id]);
        }

        return $admin;
    }

    #[PostMapping]
    public function editAction(RequestInterface $request, int $admin_id, array $role_ids = [], string $password = ''): Admin
    {
        $admin = new Admin();
        $admin->admin_id = $admin_id;
        $admin->assign($request->all(), ['email', 'white_ip', 'avatar_id']);

        if ($password !== '') {
            $admin->password = $password;
        }

        $admin = $this->adminRepository->update($admin);
        $this->adminRoleRepository->sync(Admin::class, $admin_id, $role_ids);

        return $admin;
    }

    #[GetMapping]
    #[Fields(['role_id', 'role_name'])]
    public function rolesAction(Criteria $criteria): array
    {
        $criteria->filters['role_code!='] = [Authorize::ANONYMOUS, Authorize::AUTHENTICATED];
        return $this->roleRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }
}
