<?php

declare(strict_types=1);

namespace App\Areas\Dict\Controller;

use App\Areas\Dict\Entity\Item;
use App\Areas\Dict\Repository\ItemRepository;
use App\Areas\Dict\Repository\TypeRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Dict\OptionReaderInterface;
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
#[RequestMapping('/dict/item')]
class ItemController extends Controller
{
    #[Autowired] protected ItemRepository $itemRepository;
    #[Autowired] protected TypeRepository $typeRepository;
    #[Autowired] protected OptionReaderInterface $optionReader;

    #[ViewGetMapping]
    #[Filters(['item_id', 'type_id', 'item_code', 'item_name', 'item_value', 'status', 'is_default'])]
    #[Fields([
        'item_id', 'type_id', 'item_code', 'item_name', 'item_value', 'tag', 'status', 'is_default', 'display_order',
        'updated_by', 'updated_at',
        'type' => ['type_id', 'type_name', 'builtin'],
    ])]
    #[Orders(['display_order' => SORT_DESC, 'item_id' => SORT_ASC])]
    #[Keyword(['item_code', 'item_name', 'item_value'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->itemRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Filters(['item_id', 'type_id', 'item_code', 'item_name', 'item_value', 'status', 'is_default'])]
    #[Fields(['item_id', 'type_id', 'item_code', 'item_name', 'item_value'])]
    #[Orders(['display_order' => SORT_DESC, 'item_id' => SORT_ASC])]
    public function listAction(Criteria $criteria): array
    {
        return $this->itemRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    public function optionsAction(string $type_code): array
    {
        return $this->optionReader->getOptions($type_code, false);
    }

    #[GetMapping]
    public function detailAction(Item $item): Item
    {
        return $item;
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): string|Item
    {
        $payload = $request->all();
        $typeId = (int)($payload['type_id'] ?? 0);
        $type = $this->typeRepository->all(['type_id' => $typeId], ['type_id', 'value_type'])[0] ?? null;
        if (
            $type !== null
            && $type->value_type === 'int'
            && preg_match('/^-?\d+$/', (string)($payload['item_value'] ?? '')) !== 1
        ) {
            return t('dict.integer_value_required');
        }

        $created = $this->itemRepository->create($payload);
        $this->clearOtherDefaults((int)$created->type_id, (int)$created->item_id, (int)$created->is_default);

        return $created;
    }

    #[PostMapping]
    public function editAction(Item $item): string|Item
    {
        $type = $this->typeRepository->all(['type_id' => (int)$item->type_id], ['type_id', 'value_type'])[0] ?? null;
        if (
            $type !== null
            && $type->value_type === 'int'
            && preg_match('/^-?\d+$/', $item->item_value) !== 1
        ) {
            return t('dict.integer_value_required');
        }

        $updated = $this->itemRepository->update($item);
        $this->clearOtherDefaults((int)$updated->type_id, (int)$updated->item_id, (int)$updated->is_default);

        return $updated;
    }

    #[PostMapping]
    public function deleteAction(Item $item): Item
    {
        return $this->itemRepository->delete($item);
    }

    protected function clearOtherDefaults(int $typeId, int $keepItemId, int $isDefault): void
    {
        if ($isDefault !== 1) {
            return;
        }

        $items = $this->itemRepository->all(
            ['type_id' => $typeId, 'is_default' => 1],
            ['item_id']
        );
        foreach ($items as $item) {
            if ((int)$item->item_id === $keepItemId) {
                continue;
            }

            $this->itemRepository->updateById((int)$item->item_id, ['is_default' => 0]);
        }
    }
}
