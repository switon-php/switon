<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Tests\Eval\EvalLiveTestCase;
use Switon\Agency\Tests\Eval\GuessingGameEvalRunner;
use Switon\Agency\Tests\Eval\Scenarios\GuessingGameScenarios;

require_once dirname(__DIR__) . '/manual/live_bootstrap.php';

/**
 * Live eval: multi-turn 1–100 guessing game.
 *
 * @group eval
 * @group live
 */
#[CoversClass(GuessingGameEvalRunner::class)]
#[Group('eval')]
#[Group('live')]
class GuessingGameEvalLiveTest extends EvalLiveTestCase
{
    public function testGuessingGame(): void
    {
        liveBindTools($this->container, []);

        $result = (new GuessingGameEvalRunner($this->container))->run(GuessingGameScenarios::defaultGame());

        $this->assertTrue(
            $result->passed,
            $result->id . ': ' . implode('; ', $result->failures) . ' '
            . json_encode($result->metrics, JSON_UNESCAPED_UNICODE),
        );
    }
}
