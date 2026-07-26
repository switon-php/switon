<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * SQL clause inputs for {@see Query} (SELECT/FROM/JOIN/WHERE/GROUP/HAVING/ORDER/LIMIT/OFFSET).
 *
 * Owned by {@see AbstractQuery::$clauses}. Predicates are the parsed filter IR
 * owned by Query; conditions/bind are compiled from predicates before SQL compile.
 *
 * @see \Switon\Query\SqlBuilderInterface
 */
class Clauses
{
    public ?Table $table = null;

    public ?string $alias = null;

    /** @var array<string, string>|null */
    public ?array $columnMap = null;

    /** @var string|list<string>|null */
    public null|string|array $fields = null;

    public ?int $limit = null;

    public int $offset = 0;

    public bool $distinct = false;

    /** @var array<string, int>|null */
    public ?array $order = null;

    /** @var list<string>|null */
    public ?array $group = null;

    /**
     * JOIN rows: [Table, condition, alias, type] for sharding; first element may be string during SQL compile.
     *
     * @var list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}>
     */
    public array $joins = [];

    public ?string $having = null;

    public bool $forUpdate = false;

    /** @var array<int, array<string, mixed>> Parsed condition predicates (Query-owned IR). */
    public array $predicates = [];

    /** @var list<string> */
    public array $conditions = [];

    /** @var array<string, mixed> */
    public array $bind = [];

    /** @var array<string, mixed> Additional bind parameters (merged into compiled bind). */
    public array $extraBind = [];

    public function copy(): self
    {
        $c = new self();
        $c->table = $this->table;
        $c->alias = $this->alias;
        $c->columnMap = $this->columnMap;
        $c->fields = $this->fields;
        $c->limit = $this->limit;
        $c->offset = $this->offset;
        $c->distinct = $this->distinct;
        $c->order = $this->order;
        $c->group = $this->group;
        $c->joins = $this->joins;
        $c->having = $this->having;
        $c->forUpdate = $this->forUpdate;
        $c->predicates = $this->predicates;
        $c->conditions = $this->conditions;
        $c->bind = $this->bind;
        $c->extraBind = $this->extraBind;

        return $c;
    }
}
