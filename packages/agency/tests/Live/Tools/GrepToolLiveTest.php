<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\GrepTool;

/**
 * Live behavior checks for {@see GrepTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter GrepToolLiveTest
 */
#[CoversClass(GrepTool::class)]
class GrepToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected GrepTool $fileGrep;

    #[Autowired] protected BashTool $bash;

    public function testFindsUniqueNeedleWithFileGrep(): void
    {
        $this->assertLiveLlmBound();

        [$dir, $needleFile, $needle] = $this->writeGrepFixture();
        $fileGrep = $this->recording('grep', $this->fileGrep);
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['grep', 'bash']),
            "Search under {$dir} for the exact token {$needle}. "
            . 'Report the absolute path of the file that contains it. Do not invent paths.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $greps = $this->callsNamed($calls, 'grep');
        $this->assertNotEmpty($greps, 'expected grep; calls=' . json_encode($calls));

        $args = $greps[0]['arguments'];
        $pattern = (string) ($args['pattern'] ?? '');
        $this->assertStringContainsString($needle, $pattern);

        $path = (string) ($args['path'] ?? '');
        $this->assertTrue(
            $path === '' || $path === '.' || str_starts_with($path, $dir),
            'expected path under fixture dir; got=' . json_encode($args),
        );

        $answer = (string) ($data['answer'] ?? '');
        $this->assertStringContainsString(basename($needleFile), $answer);
    }
}
