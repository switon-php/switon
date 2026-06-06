<?php

declare(strict_types=1);

namespace App\Areas\Menu\Controller;

use App\Areas\Menu\Repository\GroupRepository;
use App\Controller\Controller;
use Switon\Authorizing\AuthorizationInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

use function array_values;
use function trim;

#[Authorize(Authorize::AUTHENTICATED)]
#[RequestMapping('/menu/my')]
class MyController extends Controller
{
    #[Autowired] protected GroupRepository $groupRepository;
    #[Autowired] protected AuthorizationInterface $authorization;

    #[GetMapping]
    #[Fields(['group_id', 'group_name', 'icon',
        'items' => ['item_id', 'item_name', 'url', 'icon', 'group_id', 'permission_code'],
    ])]
    #[Orders(['display_order' => SORT_DESC, 'group_id' => SORT_ASC])]
    public function indexAction(Criteria $criteria): array
    {
        $groups = $this->groupRepository->all([], $criteria->fields, $criteria->orders);

        $menu = [];
        foreach ($groups as $group) {
            $items = $group->items;
            foreach ($items as $index => $item) {
                $permissionCode = trim((string)$item->permission_code);
                if ($permissionCode === '' || !$this->authorization->can($permissionCode)) {
                    unset($items[$index]);
                }
            }

            if (!$items) {
                continue;
            }

            $group->items = array_values($items);
            $menu[] = $group;
        }

        return $menu;
    }
}
