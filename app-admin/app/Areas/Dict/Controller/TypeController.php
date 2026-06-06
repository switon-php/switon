<?php

declare(strict_types=1);

namespace App\Areas\Dict\Controller;

use App\Areas\Dict\Entity\Type;
use App\Areas\Dict\Repository\ItemRepository;
use App\Areas\Dict\Repository\TypeRepository;
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
#[RequestMapping('/dict/type')]
class TypeController extends Controller
{
    #[Autowired] protected TypeRepository $typeRepository;
    #[Autowired] protected ItemRepository $itemRepository;

    #[ViewGetMapping]
    #[Filters(['type_id', 'type_code', 'type_name', 'value_type', 'builtin', 'status'])]
    #[Orders(['display_order' => SORT_DESC, 'type_id' => SORT_ASC])]
    #[Keyword(['type_code', 'type_name'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->typeRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Filters(['type_id', 'type_code', 'type_name', 'value_type', 'builtin', 'status'])]
    #[Fields(['type_id', 'type_code', 'type_name', 'value_type', 'builtin'])]
    #[Orders(['display_order' => SORT_DESC, 'type_id' => SORT_ASC])]
    public function listAction(Criteria $criteria): array
    {
        return $this->typeRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): Type
    {
        return $this->typeRepository->create($request->all());
    }

    #[PostMapping]
    public function editAction(Type $type): Type
    {
        return $this->typeRepository->update($type);
    }

    #[PostMapping]
    public function deleteAction(Type $type): Type
    {
        $this->itemRepository->deleteAll(['type_id' => (int)$type->type_id]);
        return $this->typeRepository->delete($type);
    }
}
