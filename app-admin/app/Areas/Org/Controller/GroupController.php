<?php

declare(strict_types=1);

namespace App\Areas\Org\Controller;

use App\Areas\Org\Entity\Group;
use App\Areas\Org\Repository\GroupRepository;
use App\Controller\Controller;
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
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[Authorize]
#[RequestMapping('/org/group')]
class GroupController extends Controller
{
    #[Autowired] protected GroupRepository $groupRepository;

    #[ViewGetMapping]
    #[Filters(['group_id', 'group_code', 'group_name', 'type', 'display_order', 'status'])]
    #[Orders(['display_order' => SORT_DESC, 'group_id' => SORT_ASC])]
    #[Keyword(['group_code', 'group_name', 'type'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->groupRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Fields(['group_id', 'group_name'])]
    public function listAction(Criteria $criteria): array
    {
        return $this->groupRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): Group
    {
        return $this->groupRepository->create($request->all());
    }

    #[PostMapping]
    public function editAction(Group $group): Group
    {
        return $this->groupRepository->update($group);
    }

    #[PostMapping]
    public function deleteAction(Group $group): Group
    {
        return $this->groupRepository->delete($group);
    }
}
