<?php

declare(strict_types=1);

namespace Switon\Console\Tests\Unit;

use Switon\Console\AnsiWidthCalculator;
use Switon\Console\Tests\TestCase;

final class AnsiWidthCalculatorTest extends TestCase
{
    public function testGetDisplayWidthWithPlainText(): void
    {
        $calculator = $this->makeCalculator();
        $this->assertSame(11, $calculator->publicGetDisplayWidth('Hello World'));
    }

    public function testGetDisplayWidthWithAnsiEscapeSequences(): void
    {
        $calculator = $this->makeCalculator();
        $this->assertSame(4, $calculator->publicGetDisplayWidth("\033[31mTest\033[0m"));
    }

    public function testGetDisplayWidthWithMultipleAnsiCodes(): void
    {
        $calculator = $this->makeCalculator();
        $this->assertSame(4, $calculator->publicGetDisplayWidth("\033[31;1mText\033[0m"));
    }

    public function testGetDisplayWidthWithEmptyString(): void
    {
        $calculator = $this->makeCalculator();
        $this->assertSame(0, $calculator->publicGetDisplayWidth(''));
    }

    private function makeCalculator(): object
    {
        return new class () {
            use AnsiWidthCalculator;

            public function publicGetDisplayWidth(string $text): int
            {
                return $this->getDisplayWidth($text);
            }
        };
    }
}
