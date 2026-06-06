<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit\Fragment;

use PHPUnit\Framework\TestCase;
use Switon\Db\Fragment\Increment;

class IncrementTest extends TestCase
{
    public function testByUsesSignedValueWithDefaultPlusOperator(): void
    {
        $fragment = Increment::by(-3)->setField('score');

        $this->assertSame('score = score + :score', $fragment->getExpression());
        $this->assertSame(['score' => -3], $fragment->getBind());
    }

    public function testConstructorSupportsExplicitMinusOperator(): void
    {
        $fragment = new Increment(5, '-');
        $fragment->setField('balance');

        $this->assertSame('balance = balance - :balance', $fragment->getExpression());
        $this->assertSame(['balance' => 5], $fragment->getBind());
    }

    public function testSetFieldIsFluentAndExpressionUsesGivenField(): void
    {
        $fragment = new Increment(1);

        $returned = $fragment->setField('login_count');

        $this->assertSame($fragment, $returned);
        $this->assertSame('login_count = login_count + :login_count', $fragment->getExpression());
    }
}
