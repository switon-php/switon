<?php

declare(strict_types=1);

namespace App\Areas\Menu\Controller;

use App\Areas\Menu\Entity\Item;
use App\Areas\Menu\Repository\ItemRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[Authorize]
#[RequestMapping('/menu/item')]
class ItemController extends Controller
{
    #[Autowired] protected ItemRepository $itemRepository;

    #[ViewGetMapping]
    #[Filters(['group_id'])]
    #[Orders(['display_order' => SORT_DESC, 'item_id' => SORT_ASC])]
    public function indexAction(Criteria $criteria): array
    {
        return $this->itemRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): Item
    {
        return $this->itemRepository->create($request->all());
    }

    #[PostMapping]
    public function editAction(Item $item): Item
    {
        return $this->itemRepository->update($item);
    }

    #[PostMapping]
    public function deleteAction(Item $item): Item
    {
        return $this->itemRepository->delete($item);
    }
}
