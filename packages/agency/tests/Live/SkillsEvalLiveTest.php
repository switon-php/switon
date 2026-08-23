<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Tests\Eval\EvalLiveTestCase;
use Switon\Agency\Tests\Eval\EvalReport;
use Switon\Agency\Tests\Eval\Scenarios\SkillsScenarios;
use Switon\Agency\Tests\Eval\SkillsEvalRunner;

/**
 * Live eval: skills catalog routing against a real LLM.
 *
 * @group eval
 * @group live
 */
#[CoversClass(SkillsEvalRunner::class)]
#[Group('eval')]
#[Group('live')]
class SkillsEvalLiveTest extends EvalLiveTestCase
{
    public function testLiveDemoSuite(): void
    {
        $runner = new SkillsEvalRunner($this->container);
        $results = [];
        foreach (SkillsScenarios::liveDemo() as $scenario) {
            $results[] = $runner->run($scenario);
        }

        $report = new EvalReport($results);
        foreach ($report->results as $result) {
            $this->assertTrue(
                $result->passed,
                $result->id . ': ' . implode('; ', $result->failures) . ' assistant=' . $result->assistant,
            );
        }
    }
}
