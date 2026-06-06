<?php

declare(strict_types=1);

namespace App\Areas\Dict\Service;

use App\Areas\Dict\Entity\Item;
use App\Areas\Dict\Entity\Type;
use App\Areas\Dict\Repository\ItemRepository;
use App\Areas\Dict\Repository\TypeRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Dict\DictEntry;
use Switon\Dict\DictReaderInterface;
use Switon\Dict\DictType;
use Switon\Dict\OptionReaderInterface;

class DictGuard implements DictReaderInterface, OptionReaderInterface
{
    #[Autowired] protected TypeRepository $typeRepository;
    #[Autowired] protected ItemRepository $itemRepository;

    public function getType(string $typeCode, bool $builtin = true): ?DictType
    {
        $type = $this->getTypeEntity($typeCode, $builtin);
        if (!$type instanceof Type) {
            return null;
        }

        return new DictType(
            typeCode: $type->type_code,
            typeName: $type->type_name,
            valueType: $type->value_type,
            builtin: (int)$type->builtin === 1,
            status: (int)$type->status === 1,
        );
    }

    public function getItem(string $typeCode, string|int|float|bool|null $value, bool $builtin = true): ?DictEntry
    {
        if ($typeCode === '' || $value === null || $value === '') {
            return null;
        }

        $type = $this->getTypeEntity($typeCode, $builtin);
        if (!$type instanceof Type) {
            return null;
        }

        /** @var Item|null $item */
        $item = $this->itemRepository->first([
            'type_id' => $type->type_id,
            'item_value' => $this->normalizeValue($value),
            'status' => 1,
        ]);

        return $item instanceof Item ? $this->toDictEntry($type->type_code, $item) : null;
    }

    public function getDefaultItem(string $typeCode, bool $builtin = true): ?DictEntry
    {
        if ($typeCode === '') {
            return null;
        }

        $type = $this->getTypeEntity($typeCode, $builtin);
        if (!$type instanceof Type) {
            return null;
        }

        $items = $this->itemRepository->all(
            ['type_id' => $type->type_id, 'status' => 1, 'is_default' => 1],
            ['item_code', 'item_name', 'item_value', 'tag', 'status', 'is_default'],
            ['display_order' => SORT_DESC, 'item_id' => SORT_ASC]
        );
        /** @var Item|null $item */
        $item = $items[0] ?? null;

        return $item instanceof Item ? $this->toDictEntry($type->type_code, $item) : null;
    }

    public function getOptions(string $typeCode, bool $builtin = true): array
    {
        $type = $this->getTypeEntity($typeCode, $builtin);
        if (!$type instanceof Type) {
            return [];
        }

        return array_map(
            fn (Item $item): array => [
                'label' => $item->item_name,
                'value' => $item->item_value,
                'code' => $item->item_code,
                'tag' => $item->tag,
                'default' => (int)$item->is_default === 1,
            ],
            $this->itemRepository->all(
                ['type_id' => $type->type_id, 'status' => 1],
                ['item_code', 'item_name', 'item_value', 'tag', 'is_default'],
                ['display_order' => SORT_DESC, 'item_id' => SORT_ASC]
            )
        );
    }

    protected function normalizeValue(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string)$value;
    }

    protected function getTypeEntity(string $typeCode, bool $builtin = true): ?Type
    {
        $filters = [
            'type_code' => $typeCode,
            'status' => 1,
        ];
        if ($builtin) {
            $filters['builtin'] = 1;
        }

        return $this->typeRepository->first($filters);
    }

    protected function toDictEntry(string $typeCode, Item $item): DictEntry
    {
        return new DictEntry(
            typeCode: $typeCode,
            itemCode: $item->item_code,
            itemName: $item->item_name,
            itemValue: $item->item_value,
            tag: $item->tag,
            status: (int)$item->status === 1,
            isDefault: (int)$item->is_default === 1,
        );
    }
}
