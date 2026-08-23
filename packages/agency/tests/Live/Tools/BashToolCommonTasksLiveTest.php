<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\BashTool;

/**
 * Seven common bash workflows against a real LLM (goal prompts; no param coaching).
 *
 *   bash tests/run.sh agency live -- --filter BashToolCommonTasksLiveTest
 */
#[CoversClass(BashTool::class)]
class BashToolCommonTasksLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected BashTool $bash;

    /** @var list<int> */
    protected array $pidsToKill = [];

    protected function tearDown(): void
    {
        foreach ($this->pidsToKill as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }
        $this->pidsToKill = [];
        parent::tearDown();
    }

    public function testEchoesUniqueToken(): void
    {
        $this->assertLiveLlmBound();

        $token = 'SWITON_ECHO_' . strtoupper(bin2hex(random_bytes(3)));
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['bash'], 4),
            "Print the exact token {$token} via a shell and report it back. Do not invent it.",
            $this->responseProperties(),
        );

        $this->assertNotEmpty($this->callsNamed($this->recordedCalls(), 'bash'));
        $this->assertStringContainsString($token, (string) ($data['answer'] ?? ''));
    }

    public function testReadsFileInDirectory(): void
    {
        $this->assertLiveLlmBound();

        $dir = $this->registerFixtureDir();
        $marker = 'SWITON_CWD_' . strtoupper(bin2hex(random_bytes(3)));
        file_put_contents($dir . '/marker.txt', $marker . "\n");

        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['bash'], 6),
            "In directory {$dir}, read marker.txt and report its exact contents. Do not invent the text.",
            $this->responseProperties(),
        );

        $this->assertNotEmpty($this->callsNamed($this->recordedCalls(), 'bash'));
        $this->assertStringContainsString($marker, (string) ($data['answer'] ?? ''));
    }

    public function testRunsPipeline(): void
    {
        $this->assertLiveLlmBound();

        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['bash'], 4),
            'Via a shell pipeline, count how many bytes are in the five characters hello with no trailing newline. '
            . 'Report the number only.',
            $this->responseProperties(),
        );

        $bashes = $this->callsNamed($this->recordedCalls(), 'bash');
        $this->assertNotEmpty($bashes);
        $piped = false;
        foreach ($bashes as $call) {
            if (str_contains((string) ($call['arguments']['command'] ?? ''), '|')) {
                $piped = true;
                break;
            }
        }
        $this->assertTrue($piped, 'expected a pipeline; calls=' . json_encode($bashes));
        $this->assertMatchesRegularExpression('/\b5\b/', (string) ($data['answer'] ?? ''));
    }

    public function testReportsNonZeroExitCode(): void
    {
        $this->assertLiveLlmBound();

        $bash = $this->recording('bash', $this->bash);

        $this->instructor()->ask(
            $this->toolProfile(['bash'], 8),
            'Run a shell command that fails (non-zero exit) and report the exit code.',
            $this->responseProperties(),
        );

        $this->assertNotEmpty($this->callsNamed($this->recordedCalls(), 'bash'));

        $evidence = '';
        foreach ($bash->calls as $recorded) {
            $result = $recorded['result'] ?? null;
            if (!is_array($result)) {
                continue;
            }
            if ((int) ($result['exit'] ?? 0) !== 0) {
                $evidence .= ' exit=' . $result['exit'];
            }
            $evidence .= (string) ($result['stdout'] ?? '') . (string) ($result['stderr'] ?? '');
        }

        $this->assertTrue(
            preg_match('/\b[1-9]\d*\b/', $evidence) === 1
            || stripos($evidence, 'non-zero') !== false
            || stripos($evidence, 'nonzero') !== false
            || stripos($evidence, 'fail') !== false
            || stripos($evidence, 'error') !== false
            || stripos($evidence, 'no such file') !== false,
            'expected failure evidence in tool output; evidence=' . $evidence,
        );
    }

    public function testWaitsForOutputThenStops(): void
    {
        $this->assertLiveLlmBound();

        $token = 'SWITON_PAT_' . strtoupper(bin2hex(random_bytes(3)));
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['bash']),
            "Start a shell command that prints {$token} and then sleeps for 30 seconds. "
            . 'Return as soon as that token appears (do not wait for the sleep to finish). '
            . 'The process does not need to keep running afterward.',
            $this->responseProperties(),
        );

        $bashes = $this->callsNamed($this->recordedCalls(), 'bash');
        $this->assertNotEmpty($bashes, 'expected bash; calls=' . json_encode($bashes));

        $matchedCall = null;
        foreach ($bash->calls as $recorded) {
            $args = $recorded['arguments'];
            $pattern = $args['pattern'] ?? null;
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            $result = $recorded['result'] ?? null;
            if (!is_array($result) || ($result['matched'] ?? false) !== true) {
                continue;
            }
            $matchedCall = $recorded;
            if (isset($result['pid']) && is_int($result['pid'])) {
                $this->pidsToKill[] = $result['pid'];
            }
            break;
        }
        $this->assertNotNull(
            $matchedCall,
            'expected a pattern match that returned early; calls=' . json_encode($bashes),
        );
        $this->assertStringContainsString($token, (string) ($data['answer'] ?? '') . json_encode($matchedCall['result']));
    }

    public function testBackgroundJobReturnsPid(): void
    {
        $this->assertLiveLlmBound();

        $bash = $this->recording('bash', $this->bash);

        $started = microtime(true);
        $data = $this->instructor()->ask(
            $this->toolProfile(['bash'], 6),
            'Start a 60-second sleep in the background and report its pid without waiting for it to finish.',
            $this->responseProperties(),
        );
        $elapsed = microtime(true) - $started;

        $bashes = $this->callsNamed($this->recordedCalls(), 'bash');
        $this->assertNotEmpty($bashes);
        $this->assertLessThan(25.0, $elapsed, 'should not block on the 60s sleep');

        $pid = null;
        foreach ($bash->calls as $recorded) {
            $result = $recorded['result'] ?? null;
            if (!is_array($result) || !isset($result['pid']) || !is_int($result['pid']) || $result['pid'] <= 0) {
                continue;
            }
            $pid = $result['pid'];
            $this->pidsToKill[] = $pid;
            $this->assertTrue($result['daemon'] ?? false, 'expected detached run; result=' . json_encode($result));
            break;
        }
        $this->assertNotNull($pid, 'expected a pid from a detached run; calls=' . json_encode($bashes));
        $this->assertMatchesRegularExpression(
            '/\b' . preg_quote((string) $pid, '/') . '\b/',
            (string) ($data['answer'] ?? ''),
        );
    }

    public function testReadyThenKeepAliveThenKill(): void
    {
        $this->assertLiveLlmBound();

        $token = 'SWITON_KILL_' . strtoupper(bin2hex(random_bytes(3)));
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['bash'], 12),
            "Start a long-running command that prints {$token} then sleeps for 60 seconds. "
            . 'Wait until that token appears, keep the process running, report its pid, '
            . 'then kill that process and confirm it is gone.',
            $this->responseProperties(),
        );

        $bashes = $this->callsNamed($this->recordedCalls(), 'bash');
        $this->assertGreaterThanOrEqual(2, count($bashes), 'expected start + kill; calls=' . json_encode($bashes));

        $startedPid = null;
        foreach ($bash->calls as $recorded) {
            $args = $recorded['arguments'];
            $result = $recorded['result'] ?? null;
            if (!is_array($result) || !isset($result['pid']) || !is_int($result['pid'])) {
                continue;
            }
            if (($result['daemon'] ?? false) !== true) {
                continue;
            }
            $this->pidsToKill[] = $result['pid'];
            // Prefer wait+keep-alive combo; fall back to any detached pid.
            if (($result['matched'] ?? false) === true && is_string($args['pattern'] ?? null) && $args['pattern'] !== '') {
                $startedPid = $result['pid'];
                break;
            }
            $startedPid ??= $result['pid'];
        }
        $this->assertNotNull($startedPid, 'expected a detached pid; calls=' . json_encode($bashes));

        $killed = false;
        foreach ($bashes as $call) {
            $command = (string) ($call['arguments']['command'] ?? '');
            if (
                str_contains($command, (string) $startedPid)
                && preg_match('/\b(kill|pkill)\b/', $command) === 1
            ) {
                $killed = true;
                break;
            }
        }
        $this->assertTrue($killed, 'expected kill of pid ' . $startedPid . '; calls=' . json_encode($bashes));

        if (function_exists('posix_kill')) {
            $this->assertFalse(@posix_kill($startedPid, 0), 'pid should be dead after kill');
        }

        $blob = (string) ($data['answer'] ?? '') . json_encode($bash->calls);
        $this->assertStringContainsString($token, $blob);
        $this->assertMatchesRegularExpression('/\b\d{2,}\b/', (string) ($data['answer'] ?? ''));
    }
}
