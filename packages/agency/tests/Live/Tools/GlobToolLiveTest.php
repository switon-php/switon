<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\GlobTool;

/**
 * Live behavior checks for {@see GlobTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter GlobToolLiveTest
 */
#[CoversClass(GlobTool::class)]
class GlobToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected GlobTool $globFind;

    #[Autowired] protected BashTool $bash;

    public function testFindsUniqueFilenamePreferringGlobFind(): void
    {
        $this->assertLiveLlmBound();

        $dir = $this->registerFixtureDir();
        $token = 'SWITON_GLOB_' . strtoupper(bin2hex(random_bytes(4)));
        $needleFile = $dir . '/' . $token . '.txt';
        file_put_contents($needleFile, "noise\n");
        file_put_contents($dir . '/other.md', "markdown\n");

        $globFind = $this->recording('glob', $this->globFind);
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['glob', 'bash']),
            "Under {$dir}, find the absolute path of the .txt file whose name contains {$token}. "
            . 'Report that path. Do not invent paths.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $globs = $this->callsNamed($calls, 'glob');
        $this->assertNotEmpty($globs, 'expected glob; calls=' . json_encode($calls));

        $bashes = $this->callsNamed($calls, 'bash');
        foreach ($bashes as $bashCall) {
            $command = (string) ($bashCall['arguments']['command'] ?? '');
            $this->assertDoesNotMatchRegularExpression(
                '/\b(find|ls|locate|fd)\b/',
                $command,
                'bash should not find/ls the files; command=' . $command,
            );
        }

        $answer = (string) ($data['answer'] ?? '');
        $this->assertStringContainsString($token, $answer);
    }
}
