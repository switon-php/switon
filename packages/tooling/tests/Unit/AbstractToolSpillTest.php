<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\PathAliasInterface;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Spiller;
use Switon\Tooling\Tests\Fixtures\ToolTestAgent;
use Switon\Tooling\Tests\ToolTestCase;

#[CoversClass(AbstractTool::class)]
#[CoversClass(Spiller::class)]
class AbstractToolSpillTest extends ToolTestCase
{
    public function testSpillFieldDerivesRecallFromCallIdAndChannel(): void
    {
        $tool = $this->make(SpillProbeTool::class, ['budget' => 1]);
        $datadir = $this->threadDir();
        $agent = $this->toolAgent($datadir);
        $callId = 'call_x1';
        $body = str_repeat('x', 2048);

        $stdout = $tool->exposeSpill($callId, $agent, $body, 'stdout');
        $stderr = $tool->exposeSpill($callId, $agent, $body, 'stderr');

        $this->assertStringContainsString('recall: @thread/tool-result/call_x1-stdout.txt', $stdout);
        $this->assertStringContainsString('recall: @thread/tool-result/call_x1-stderr.txt', $stderr);
        $this->assertFileExists($datadir . '/tool-result/call_x1-stdout.txt');
        $this->assertFileExists($datadir . '/tool-result/call_x1-stderr.txt');
    }

    public function testSpillFieldWithoutThreadDatadirStaysInMemory(): void
    {
        $tool = $this->make(SpillProbeTool::class, ['budget' => 1]);
        $agent = new ToolTestAgent(Thread::of('test-thread'));

        $out = $tool->exposeSpill('c1', $agent, str_repeat('x', 2048), 'stdout');

        $this->assertNull($agent->thread()->datadir);
        $this->assertStringContainsString('[truncated:', $out);
        $this->assertStringNotContainsString('recall:', $out);
    }

    public function testSpillFieldUnderBudgetSkipsDisk(): void
    {
        $tool = $this->make(SpillProbeTool::class);

        $out = $tool->exposeSpill('c1', ToolTestAgent::in(), 'tiny', 'stdout');

        $this->assertSame('tiny', $out);
    }

    public function testThreadDatadirIsNotPreexpanded(): void
    {
        $root = sys_get_temp_dir() . '/switon-alias-' . bin2hex(random_bytes(4));
        mkdir($root . '/fixture/tool-result', 0777, true);
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        $tool = $this->make(SpillProbeTool::class, ['budget' => 1]);
        $agent = new ToolTestAgent(Thread::of('test-thread', '@threads/fixture'));

        // Alias form must survive: Filesystem expands on write, so resolving here would be wasted work.
        $this->assertSame('@threads/fixture', $agent->thread()->datadir);
        $this->assertStringContainsString(
            'recall: @thread/tool-result/c9.txt',
            $tool->exposeSpill('c9', $agent, str_repeat('y', 2048), ''),
        );
    }
}

/** @internal */
class SpillProbeTool extends AbstractTool
{
    public function schema(string $name): ?ToolSchema
    {
        return null;
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        unset($agent, $call, $arguments);

        return null;
    }

    public function exposeSpill(string $callId, AgentInterface $agent, string $data, string $channel): string
    {
        return $this->spillField($data, $callId, $agent, $channel);
    }
}
