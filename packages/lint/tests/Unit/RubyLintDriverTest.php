<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\RubyLintDriver;
use Switon\Lint\ProcessInterface;
use Switon\Lint\Tests\TestCase;

#[CoversClass(RubyLintDriver::class)]
class RubyLintDriverTest extends TestCase
{
    public function testSyntaxError(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                return [
                    'exit' => 1,
                    'stdout' => '',
                    'stderr' => "{$command[2]}:1: syntax error, unexpected end-of-input\n",
                ];
            }
        };

        $result = $this->make(RubyLintDriver::class, ['process' => $process])->lint('/tmp/bad.rb', static fn (): bool => false);

        $this->assertSame([['name' => 'ruby -c', 'ok' => false]], $result['tools']);
        $this->assertSame(1, $result['diagnostics'][0]['line']);
        $this->assertStringContainsString('syntax error', $result['diagnostics'][0]['message']);
    }
}
