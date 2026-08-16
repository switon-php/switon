<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\LuaLintDriver;
use Switon\Lint\ProcessInterface;
use Switon\Lint\Tests\TestCase;

#[CoversClass(LuaLintDriver::class)]
class LuaLintDriverTest extends TestCase
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
                    'stderr' => "luac: {$command[2]}:2: <name> or '...' expected near <eof>\n",
                ];
            }
        };

        $result = $this->make(LuaLintDriver::class, ['process' => $process])->lint('/tmp/bad.lua', static fn (): bool => false);

        $this->assertSame([['name' => 'luac -p', 'ok' => false]], $result['tools']);
        $this->assertSame(2, $result['diagnostics'][0]['line']);
        $this->assertStringContainsString('expected', $result['diagnostics'][0]['message']);
    }
}
