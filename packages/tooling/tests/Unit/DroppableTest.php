<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\GlobTool;
use Switon\Tooling\Tool\GrepTool;
use Switon\Tooling\Tool\LintTool;
use Switon\Tooling\Tool\LsTool;
use Switon\Tooling\Tool\ReadTool;
use Switon\Tooling\Tool\WriteTool;
use Switon\Tooling\Tools;

#[CoversClass(AbstractTool::class)]
#[CoversClass(Tools::class)]
class DroppableTest extends ToolTestCase
{
    public function testAbstractToolDefaultsFalse(): void
    {
        $tool = new class () extends AbstractTool {
            public function schema(string $name): ToolSchema
            {
                return new ToolSchema($this);
            }

            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                unset($call, $arguments, $agent);

                return '';
            }
        };

        $this->assertFalse($tool->isReadonly('x', []));
    }

    public function testReadToolsAreReadonly(): void
    {
        $this->assertTrue($this->make(ReadTool::class)->isReadonly('read', ['path' => '/tmp/a']));
        $this->assertTrue($this->make(GrepTool::class)->isReadonly('grep', ['pattern' => 'a']));
        $this->assertFalse($this->make(WriteTool::class)->isReadonly('write', ['path' => '/tmp/a', 'content' => '']));
        $this->assertTrue($this->make(GlobTool::class)->isReadonly('glob', ['pattern' => '*']));
        $this->assertTrue($this->make(LsTool::class)->isReadonly('ls', ['path' => '/tmp']));
        $this->assertTrue($this->make(LintTool::class)->isReadonly('lint', ['path' => '/tmp/a.php']));
    }

    public function testBashIsNotReadonly(): void
    {
        $this->assertFalse($this->make(BashTool::class)->isReadonly('bash', ['command' => 'true']));
    }

    public function testToolsFacadeRoutesReadonly(): void
    {
        $tools = $this->make(Tools::class);

        $this->assertTrue($tools->isReadonly('read', ['path' => '/tmp/a']));
        $this->assertFalse($tools->isReadonly('bash', ['command' => 'true']));
        $this->assertFalse($tools->isReadonly('unknown-tool', []));
    }
}
