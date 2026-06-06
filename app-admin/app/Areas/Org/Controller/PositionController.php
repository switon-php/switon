<?php

declare(strict_types=1);

namespace App\Areas\Org\Controller;

use App\Areas\Org\Entity\Position;
use App\Areas\Org\Repository\PositionRepository;
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
#[RequestMapping('/org/position')]
class PositionController extends Controller
{
    #[Autowired] protected PositionRepository $positionRepository;

    #[ViewGetMapping]
    #[Filters(['position_id', 'position_code', 'position_name', 'display_order', 'status'])]
    #[Orders(['display_order' => SORT_DESC, 'position_id' => SORT_ASC])]
    #[Keyword(['position_code', 'position_name'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->positionRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Fields(['position_id', 'position_name'])]
    public function listAction(Criteria $criteria): array
    {
        return $this->positionRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): Position
    {
        return $this->positionRepository->create($request->all());
    }

    #[PostMapping]
    public function editAction(Position $position): Position
    {
        return $this->positionRepository->update($position);
    }

    #[PostMapping]
    public function deleteAction(Position $position): Position
    {
        return $this->positionRepository->delete($position);
    }
}
