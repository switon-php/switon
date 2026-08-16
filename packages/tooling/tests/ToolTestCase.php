<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Core\Runtime;
use Switon\Testing\TestCase as BaseTestCase;
use Switon\Tooling\Tests\Fixtures\ToolTestAgent;

/** Base for tool unit tests (no agent loop / MCP registry). */
abstract class ToolTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (extension_loaded('swoole')) {
            \Swoole\Runtime::setHookFlags(0);
            Runtime::setCoroutineEnabled(false);
        }
    }

    /**
     * Invoke a tool against a throwaway thread directory.
     *
     * @param array<string, mixed> $arguments
     * @param ?string $workspace Absolute sandbox root for relative paths
     * @param ?string $datadir Thread dir to spill under; a fresh temp dir by default
     * @param string $callId Call id — becomes the spill filename (`tool-result/{id}.txt`)
     */
    protected function callTool(
        ToolInterface $tool,
        string $name,
        array $arguments = [],
        ?string $workspace = null,
        ?string $datadir = null,
        string $callId = 'test',
    ): mixed {
        $agent = $this->toolAgent($datadir, $workspace);

        return $tool->call($agent, new ToolCall(id: $callId, name: $name, arguments: $arguments), $arguments);
    }

    /** Fresh thread dir under the system temp root, with `tool-result/` ready. */
    protected function threadDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-tool-test-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tool-result', 0777, true);

        return $dir;
    }

    /** Host stub exposing thread datadir + workspace to a tool. */
    protected function toolAgent(?string $datadir = null, ?string $workspace = null): ToolTestAgent
    {
        return ToolTestAgent::in($datadir ?? $this->threadDir(), $workspace);
    }

    /** Call a tool with an explicit agent (agency / mcp-client style tests). */
    protected function callWithAgent(ToolInterface $tool, string $name, array $arguments, AgentInterface $agent): mixed
    {
        return $tool->call($agent, new ToolCall(name: $name, arguments: $arguments), $arguments);
    }
}
