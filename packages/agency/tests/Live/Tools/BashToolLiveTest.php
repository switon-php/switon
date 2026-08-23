<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\ReadTool;

/**
 * Live behavior checks for {@see BashTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter BashToolLiveTest
 */
#[CoversClass(BashTool::class)]
class BashToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected ReadTool $fileRead;

    #[Autowired] protected BashTool $bash;

    public function testPrefersFileReadOverCatForFileContents(): void
    {
        $this->assertLiveLlmBound();

        $path = $this->writeLineFixture(20);
        $fileRead = $this->recording('read', $this->fileRead);
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['read', 'bash']),
            "Read the contents of {$path} and report the exact text of line 5 (LINE-5). "
            . 'Do not invent content.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $reads = $this->callsNamed($calls, 'read');
        $this->assertNotEmpty($reads, 'expected read; calls=' . json_encode($calls));

        $bashes = $this->callsNamed($calls, 'bash');
        foreach ($bashes as $bashCall) {
            $command = (string) ($bashCall['arguments']['command'] ?? '');
            $this->assertDoesNotMatchRegularExpression(
                '/\b(cat|head|tail|sed)\b/',
                $command,
                'bash should not cat/head/tail the file; command=' . $command,
            );
        }

        $this->assertStringContainsString('LINE-5', (string) ($data['answer'] ?? ''));
    }

    public function testKeepsOutputNarrowWhenInspectingLargeFile(): void
    {
        $this->assertLiveLlmBound();

        $dir = $this->registerFixtureDir();
        $path = $dir . '/big.txt';
        $lines = [];
        for ($i = 1; $i <= 4000; $i++) {
            $lines[] = "ROW-{$i}";
        }
        file_put_contents($path, implode("\n", $lines) . "\n");

        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['bash']),
            "Using bash only, report how many lines are in {$path} and the exact text of the last line. "
            . 'Do not invent values.',
            $this->responseProperties(),
        );

        $bashes = $this->callsNamed($this->recordedCalls(), 'bash');
        $this->assertNotEmpty($bashes, 'expected bash; calls=' . json_encode($bashes));

        $dumpedAll = false;
        $narrowed = false;
        foreach ($bashes as $call) {
            $command = (string) ($call['arguments']['command'] ?? '');
            if (
                preg_match('/\b(wc|tail|head|sed|awk)\b/', $command) === 1
                || str_contains($command, '|')
            ) {
                $narrowed = true;
            }
            if (
                preg_match('/\bcat\b/', $command) === 1
                && !str_contains($command, '|')
                && str_contains($command, 'big.txt')
            ) {
                $dumpedAll = true;
            }
        }
        $this->assertTrue($narrowed, 'expected narrowed inspection (wc/tail/pipe); calls=' . json_encode($bashes));
        $this->assertFalse($dumpedAll, 'should not cat the whole file; calls=' . json_encode($bashes));

        foreach ($bash->calls as $recorded) {
            $result = $recorded['result'] ?? null;
            if (!is_array($result)) {
                continue;
            }
            $blob = (string) ($result['stdout'] ?? '') . (string) ($result['stderr'] ?? '');
            $this->assertLessThan(
                8000,
                strlen($blob),
                'tool stdout/stderr should stay small; got=' . strlen($blob),
            );
        }

        $answer = (string) ($data['answer'] ?? '');
        $this->assertMatchesRegularExpression('/\b4000\b/', $answer);
        $this->assertStringContainsString('ROW-4000', $answer);
    }

    public function testUsesDaemonAndPatternThenReportsPid(): void
    {
        $this->assertLiveLlmBound();

        $token = 'SWITON_READY_' . strtoupper(bin2hex(random_bytes(3)));
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['bash'], 10),
            "Start a long-running shell command that prints the exact token {$token} then sleeps for 60 seconds. "
            . 'Wait until that token appears, keep the process running afterward, and report its pid.',
            $this->responseProperties(),
        );

        $bashes = $this->callsNamed($this->recordedCalls(), 'bash');
        $this->assertNotEmpty($bashes, 'expected bash; calls=' . json_encode($bashes));

        $combo = null;
        foreach ($bashes as $bashCall) {
            $args = $bashCall['arguments'];
            if (($args['daemon'] ?? false) !== true) {
                continue;
            }
            $pattern = $args['pattern'] ?? null;
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            $combo = $args;
            break;
        }
        $this->assertNotNull($combo, 'expected bash with daemon+pattern; calls=' . json_encode($bashes));
        $this->assertStringContainsString($token, (string) ($combo['command'] ?? ''));

        $answer = (string) ($data['answer'] ?? '');
        $this->assertMatchesRegularExpression('/\b\d{2,}\b/', $answer, 'expected pid in answer; answer=' . $answer);

        $pidSeen = false;
        foreach ($bash->calls as $recorded) {
            $result = $recorded['result'] ?? null;
            if (!is_array($result) || !isset($result['pid']) || !is_int($result['pid'])) {
                continue;
            }
            $pidSeen = true;
            $this->assertTrue($result['matched'] ?? false, 'expected matched on daemon+pattern result');
            if (function_exists('posix_kill')) {
                @posix_kill($result['pid'], 9);
            }
        }
        $this->assertTrue($pidSeen, 'expected tool result with pid');
    }
}
