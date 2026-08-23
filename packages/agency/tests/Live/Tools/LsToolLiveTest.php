<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\LsTool;

/**
 * Live behavior checks for {@see LsTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter LsToolLiveTest
 */
#[CoversClass(LsTool::class)]
class LsToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected LsTool $dirList;

    #[Autowired] protected BashTool $bash;

    public function testListsDirectoryPreferringLs(): void
    {
        $this->assertLiveLlmBound();

        $dir = $this->registerFixtureDir();
        $token = 'SWITON_DIR_' . strtoupper(bin2hex(random_bytes(4)));
        file_put_contents($dir . '/' . $token . '.txt', "noise\n");
        mkdir($dir . '/nested');

        $dirList = $this->recording('ls', $this->dirList);
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['ls', 'bash']),
            "List the directory {$dir} and report the exact name of the .txt entry. "
            . 'Do not invent names.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $lists = $this->callsNamed($calls, 'ls');
        $this->assertNotEmpty($lists, 'expected ls; calls=' . json_encode($calls));

        $bashes = $this->callsNamed($calls, 'bash');
        foreach ($bashes as $bashCall) {
            $command = (string) ($bashCall['arguments']['command'] ?? '');
            $this->assertDoesNotMatchRegularExpression(
                '/\b(ls|find|dir|tree)\b/',
                $command,
                'bash should not ls/find the directory; command=' . $command,
            );
        }

        $answer = (string) ($data['answer'] ?? '');
        $this->assertStringContainsString($token, $answer);
    }
}
