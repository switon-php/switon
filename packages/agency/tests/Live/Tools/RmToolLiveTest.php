<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\RmTool;

/**
 * Live behavior checks for {@see RmTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter RmToolLiveTest
 */
#[CoversClass(RmTool::class)]
class RmToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected RmTool $fileRm;

    #[Autowired] protected BashTool $bash;

    public function testDeletesFilePreferringFileRm(): void
    {
        $this->assertLiveLlmBound();

        $dir = $this->registerFixtureDir();
        $token = 'SWITON_RM_' . strtoupper(bin2hex(random_bytes(4)));
        $path = $dir . '/' . $token . '.txt';
        file_put_contents($path, "delete-me\n");

        $fileRm = $this->recording('rm', $this->fileRm);
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['rm', 'bash']),
            "Delete the file at {$path}. Confirm it is gone. Do not invent paths.",
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $rms = $this->callsNamed($calls, 'rm');
        $this->assertNotEmpty($rms, 'expected rm; calls=' . json_encode($calls));
        $paths = $rms[0]['arguments']['paths'] ?? null;
        $this->assertIsArray($paths);
        $this->assertContains($path, $paths);

        $bashes = $this->callsNamed($calls, 'bash');
        foreach ($bashes as $bashCall) {
            $command = (string) ($bashCall['arguments']['command'] ?? '');
            $this->assertDoesNotMatchRegularExpression(
                '/\brm\b/',
                $command,
                'bash should not rm the file; command=' . $command,
            );
        }

        $this->assertFileDoesNotExist($path);
        $answer = (string) ($data['answer'] ?? '');
        $this->assertNotSame('', $answer);
    }
}
