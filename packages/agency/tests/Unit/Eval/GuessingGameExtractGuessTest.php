<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Eval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Switon\Agency\Tests\Eval\GuessingGameEvalRunner;
use Switon\Testing\Container;
use Switon\Core\App;
use ReflectionMethod;

class GuessingGameExtractGuessTest extends TestCase
{
    protected function setUp(): void
    {
        App::setContainer(new Container());
    }

    #[DataProvider('guessReplies')]
    public function testExtractGuess(string $reply, ?int $expected): void
    {
        $runner = new GuessingGameEvalRunner(App::getContainer());
        $method = new ReflectionMethod(GuessingGameEvalRunner::class, 'extractGuess');

        $this->assertSame($expected, $method->invoke($runner, $reply));
    }

    /** @return iterable<string, array{0: string, 1: int|null}> */
    public static function guessReplies(): iterable
    {
        yield 'markdown bold' => ["**50**\n二分法", 50];
        yield 'chinese prefix' => ['猜37，在剩余范围内取中间值继续探测。', 37];
        yield 'english prose' => ['Guessed 68; upper-middle range.', 68];
        yield 'range mention only' => ['请再猜一个 1-100 的整数', null];
        yield 'empty' => ['', null];
    }
}
