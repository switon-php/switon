<?php

declare(strict_types=1);

namespace App\Widget;

use App\Areas\Menu\Repository\GroupRepository;
use Switon\Authorizing\AuthorizationInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Orm\AdditionalRelationCriteria;

use function array_values;

class SideMenuWidget extends Widget
{
    #[Autowired] protected AuthorizationInterface $authorization;
    #[Autowired] protected GroupRepository $groupRepository;

    public function run($vars = []): array
    {
        $fields = ['group_id', 'group_name', 'icon',
                   'items' => AdditionalRelationCriteria::of(
                       ['item_id', 'item_name', 'url', 'icon', 'group_id', 'permission_code'],
                       ['display_order' => SORT_DESC, 'item_id' => SORT_ASC]
                   )];
        $orders = ['display_order' => SORT_DESC, 'group_id' => SORT_ASC];

        $groups = $this->groupRepository->all([], $fields, $orders);

        $menu = [];
        foreach ($groups as $group) {
            $items = $group->items;
            foreach ($items as $k => $item) {
                $permission_code = $item->permission_code;
                if ($permission_code === '' || !$this->authorization->can($permission_code)) {
                    unset($items[$k]);
                }
            }

            if (!$items) {
                continue;
            }

            $group->items = array_values($items);
            $menu[] = $group;
        }

        return ['menu' => $menu];
    }
}
