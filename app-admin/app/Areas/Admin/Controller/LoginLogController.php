<?php

declare(strict_types=1);

namespace App\Areas\Admin\Controller;

use App\Controller\Controller;
use App\Repository\AdminLoginLogRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\IdentityFilter;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[RequestMapping('/admin/login-log')]
class LoginLogController extends Controller
{
    #[Autowired] protected AdminLoginLogRepository $adminLoginLogRepository;

    #[Authorize]
    #[ViewGetMapping]
    #[Filters(['admin_id', 'admin_name*=', 'client_ip', 'client_udid', 'created_at@='])]
    #[Fields(['login_id', 'admin_id', 'admin_name', 'client_udid', 'user_agent', 'client_ip', 'created_at'])]
    #[Orders(['login_id' => SORT_DESC])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->adminLoginLogRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[Authorize(Authorize::AUTHENTICATED)]
    #[ViewGetMapping]
    #[Filters(['created_at@='])]
    #[IdentityFilter('admin_id')]
    #[Fields(['login_id', 'client_udid', 'user_agent', 'client_ip', 'created_at'])]
    #[Orders(['login_id' => SORT_DESC])]
    public function latestAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->adminLoginLogRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }
}
