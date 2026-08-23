<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Ai\AbstractExtension;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;

#[CoversClass(AbstractExtension::class)]
class AbstractExtensionTest extends TestCase
{
    public function testDefaultsRunWithoutReview(): void
    {
        $extension = new class () extends AbstractExtension {
        };
        $agent = $this->createStub(AgentInterface::class);
        $call = new ToolCall('c1', 'bash', ['command' => 'rm -rf /']);

        // No side effects, and no approval required.
        $extension->beforeTool($agent, $call);
        $this->assertFalse($extension->reviewTool($agent, $call));
    }
}
