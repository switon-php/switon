<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Contract for SQL fragments used in UPDATE and UPSERT field assignments.
 *
 * Client calls <code>setField()</code> before <code>getExpression()</code> and <code>getBind()</code>.
 *
 * Guidance: Return only the field-assignment expression; do not include <code>SET</code>, <code>WHERE</code>, or statement-level SQL.
 *
 * Road-signs:
 * - Client::update() and Client::upsert() are the call sites
 * - setField() runs before getExpression()/getBind()
 * - getExpression() contributes one SET-clause assignment
 * - getBind() is merged into the statement bind array
 * - Fragment\Increment is the built-in example
 *
 * @see \Switon\Db\Fragment\Increment
 * @see \Switon\Db\Client
 */
interface SqlFragmentInterface
{
    /**
     * Set the target field name.
     */
    public function setField(string $field): static;

    /**
     * Return the SQL expression snippet for a SET clause.
     */
    public function getExpression(): string;

    /**
     * @return array<string, mixed> Bind values required by the expression.
     */
    public function getBind(): array;
}
