<?php

declare(strict_types=1);

namespace App\Areas\Menu\Controller;

use App\Areas\Menu\Entity\Group;
use App\Areas\Menu\Repository\GroupRepository;
use App\Areas\Menu\Repository\ItemRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[Authorize]
#[RequestMapping('/menu/group')]
class GroupController extends Controller
{
    #[Autowired] protected GroupRepository $groupRepository;
    #[Autowired] protected ItemRepository $itemRepository;

    #[ViewGetMapping]
    #[Filters(['group_id'])]
    #[Orders(['display_order' => SORT_DESC, 'group_id' => SORT_ASC])]
    public function indexAction(Criteria $criteria): array
    {
        return $this->groupRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
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
        $this->itemRepository->deleteAll(['group_id' => (int)$group->group_id]);
        return $this->groupRepository->delete($group);
    }
}
