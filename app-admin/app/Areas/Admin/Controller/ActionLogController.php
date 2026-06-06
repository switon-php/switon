<?php

declare(strict_types=1);

namespace App\Areas\Admin\Controller;

use App\Controller\Controller;
use App\Entity\AdminActionLog;
use App\Repository\AdminActionLogRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\IdentityFilter;
use Switon\Http\Attribute\IdentityFilterOrAuthorized;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[RequestMapping('/admin/action-log')]
class ActionLogController extends Controller
{
    #[Autowired] protected AdminActionLogRepository $adminActionLogRepository;

    #[Authorize]
    #[ViewGetMapping]
    #[Filters(['admin_name', 'handler*=', 'client_ip', 'created_at@=', 'tag'])]
    #[Orders(['id' => SORT_DESC])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->adminActionLogRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[Authorize(Authorize::AUTHENTICATED)]
    #[GetMapping]
    #[IdentityFilterOrAuthorized('admin_id')]
    public function detailAction(AdminActionLog $log): AdminActionLog|string
    {
        return $log;
    }

    #[Authorize(Authorize::AUTHENTICATED)]
    #[ViewGetMapping]
    #[Filters(['handler*=', 'client_ip', 'created_at@=', 'tag'])]
    #[IdentityFilter('admin_id')]
    #[Orders(['id' => SORT_DESC])]
    public function latestAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->adminActionLogRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }
}
