<?php

declare(strict_types=1);

namespace Switon\Db\Fragment;

use Switon\Db\SqlFragmentInterface;

/**
 * SQL fragment for signed field increments in UPDATE and UPSERT statements.
 *
 * @see \Switon\Db\SqlFragmentInterface
 * @see \Switon\Db\Client
 */
class Increment implements SqlFragmentInterface
{
    protected int|float $value;
    protected string $field = '';
    protected string $operator = '+';

    /**
     * @param int|float $value Positive to increment, negative to decrement.
     * @param string $operator Legacy operator argument ('+' or '-').
     */
    public function __construct(int|float $value, string $operator = '+')
    {
        $this->value = $value;
        $this->operator = $operator;
    }

    /** Creates an increment/decrement fragment from a signed value. */
    public static function by(int|float $value): static
    {
        return new static($value);
    }

    public function setField(string $field): static
    {
        $this->field = $field;
        return $this;
    }

    public function getExpression(): string
    {
        return "$this->field = $this->field $this->operator :$this->field";
    }

    public function getBind(): array
    {
        return [$this->field => $this->value];
    }
}
