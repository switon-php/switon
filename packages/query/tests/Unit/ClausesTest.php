<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Query\Clauses;
use Switon\Query\Table;

class ClausesTest extends TestCase
{
    public function testCopyPreservesValuesAndCreatesIndependentArrayState(): void
    {
        $original = new Clauses();
        $original->table = Table::of('orders', 'default');
        $original->alias = 'o';
        $original->columnMap = ['id' => 'order_id'];
        $original->fields = ['id', 'amount'];
        $original->limit = 20;
        $original->offset = 5;
        $original->distinct = true;
        $original->order = ['id' => 'DESC'];
        $original->group = ['status'];
        $original->joins = [['orders', 'o.id = p.order_id', 'p', 'left']];
        $original->having = 'COUNT(*) > 1';
        $original->forUpdate = true;
        $original->predicates = [['type' => 'eq', 'field' => 'tenant_id', 'value' => 9]];
        $original->conditions = ['tenant_id = :tenant_id'];
        $original->bind = ['tenant_id' => 9];
        $original->extraBind = ['limit' => 20];

        $copied = $original->copy();

        $this->assertNotSame($original, $copied);
        $this->assertSame($original->table, $copied->table);
        $this->assertSame('o', $copied->alias);
        $this->assertSame(['id' => 'order_id'], $copied->columnMap);
        $this->assertSame(['id', 'amount'], $copied->fields);
        $this->assertSame(20, $copied->limit);
        $this->assertSame(5, $copied->offset);
        $this->assertTrue($copied->distinct);
        $this->assertSame(['id' => 'DESC'], $copied->order);
        $this->assertSame(['status'], $copied->group);
        $this->assertSame([['orders', 'o.id = p.order_id', 'p', 'left']], $copied->joins);
        $this->assertSame('COUNT(*) > 1', $copied->having);
        $this->assertTrue($copied->forUpdate);
        $this->assertSame([['type' => 'eq', 'field' => 'tenant_id', 'value' => 9]], $copied->predicates);
        $this->assertSame(['tenant_id = :tenant_id'], $copied->conditions);
        $this->assertSame(['tenant_id' => 9], $copied->bind);
        $this->assertSame(['limit' => 20], $copied->extraBind);

        $copied->order['id'] = 'ASC';
        $copied->bind['tenant_id'] = 11;
        $copied->extraBind['limit'] = 10;
        $copied->conditions[] = 'status = :status';

        $this->assertSame('DESC', $original->order['id']);
        $this->assertSame(9, $original->bind['tenant_id']);
        $this->assertSame(20, $original->extraBind['limit']);
        $this->assertCount(1, $original->conditions);
    }
}
