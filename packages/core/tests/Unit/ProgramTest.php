<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\ProcessTree;
use Switon\Core\Program;

use const PHP_BINARY;

class ProgramTest extends TestCase
{
    public function testRunReturnsStdoutAndExitCode(): void
    {
        $result = Program::run([PHP_BINARY, '-r', 'echo "ok";']);

        $this->assertSame(0, $result['code']);
        $this->assertSame('ok', $result['output']);
    }

    public function testRunDiscardsStderrUnlessMerged(): void
    {
        $script = 'fwrite(STDERR, "err"); echo "out";';

        $discarded = Program::run([PHP_BINARY, '-r', $script]);
        $merged = Program::run([PHP_BINARY, '-r', $script], true);

        $this->assertSame('out', $discarded['output']);
        $this->assertStringContainsString('out', $merged['output']);
        $this->assertStringContainsString('err', $merged['output']);
    }

    public function testRunReturnsMinusOneWhenProgramIsMissing(): void
    {
        $result = Program::run(['switon-program-missing']);

        $this->assertSame(-1, $result['code']);
        $this->assertSame('', $result['output']);
    }

    public function testPassthruReturnsExitCode(): void
    {
        $this->assertSame(3, Program::passthru([PHP_BINARY, '-r', 'exit(3);']));
    }

    public function testStartReturnsPidOfARunningProcess(): void
    {
        $pid = Program::start([PHP_BINARY, '-r', 'sleep(30);']);

        try {
            $this->assertGreaterThan(0, $pid);
            $this->assertTrue(ProcessTree::alive($pid));
        } finally {
            if ($pid > 0) {
                ProcessTree::terminate($pid, 0.0);
            }
        }
    }
}
