<?php

declare(strict_types=1);

namespace Switon\Agency\Tests;

use Switon\Agency\Tests\Fixtures\StubToolAgent;
use Switon\Ai\AgentInterface;
use Switon\Agency\Toolkit\AsToolToolkit;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Core\Json;
use Switon\Core\Runtime;
use Switon\Ai\Completion;
use Switon\Ai\Message\UserMessage;
use Switon\Testing\TestCase as BaseTestCase;
use Switon\Tooling\ArgumentBinder;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolResult;
use Throwable;

/** Base for tool unit tests (no Swoole scheduler). */
abstract class ToolsTestCase extends BaseTestCase
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
     * @return array<string, class-string>
     */
    protected function builtinToolkitConfig(): array
    {
        return [
            'agent' => AsToolToolkit::class,
        ];
    }

    protected function stubAgent(): AgentInterface
    {
        return StubToolAgent::in();
    }

    protected function runTool(
        ToolInterface $tool,
        string $name,
        array $arguments = [],
        ?AgentInterface $agent = null,
    ): mixed {
        return $tool->call($agent ?? $this->stubAgent(), new ToolCall(name: $name), $arguments);
    }

    /**
     * Dispatch with no LLM round — empty completion shell and call name only.
     *
     * Bind first, exactly as {@see \Switon\Agency\Loop::completeStep()} does: handlers rely on
     * schema defaults being applied before they run.
     */
    protected function callStandalone(ToolsInterface $tools, string $name, array $arguments): ToolResult
    {
        $schema = $tools->get($name);
        $this->assertNotNull($schema);
        $call = new ToolCall(name: $name, arguments: (new ArgumentBinder())->bind($schema, $arguments));

        $dir = sys_get_temp_dir() . '/switon-agency-tool-test-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tool-result', 0777, true);

        return $tools->call($this->stubAgent(), $call, $schema);
    }

    /** Decode {@see ToolResult::$content} for assertions on handler payloads. */
    protected function toolPayload(ToolResult $result): mixed
    {
        $content = $result->content;
        if (is_array($content)) {
            return $content;
        }
        if ($content === '') {
            return $content;
        }

        try {
            $payload = Json::parse((string) $content);
        } catch (Throwable) {
            return $content;
        }

        if (!is_array($payload)) {
            return $payload;
        }

        return $payload;
    }
}
