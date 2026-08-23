<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Loop;
use Switon\Agency\Tests\Fixtures\TestAgent;
use Switon\Agency\Tests\TestCase;
use Switon\Ai\ToolSchema;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\ToolsInterface;

#[CoversClass(Loop::class)]
#[CoversClass(ArgumentBinder::class)]
#[Group('coroutine')]
class StepPrepareTest extends TestCase
{
    protected function toolSchema(string $name, array $tools = ['task_deliver']): ToolSchema
    {
        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(tools: $tools),
        ]);
        $toolsService = $this->container->get(ToolsInterface::class);

        return $toolsService->pick(
            $this->agentProp($agent, 'profileTools'),
        )[$name];
    }

    public function testBindAppliesSchemaDefault(): void
    {
        $schema = $this->toolSchema('task_deliver');

        $arguments = (new ArgumentBinder())->bind($schema, [
            '.caption' => 'Assign',
            'agent' => 'lite',
            'message' => 'Work',
        ]);

        $this->assertArrayHasKey('message', $arguments);
    }

    public function testBindRejectsMissingRequired(): void
    {
        $schema = $this->toolSchema('task_deliver');

        $this->expectException(ArgumentBindException::class);
        (new ArgumentBinder())->bind($schema, ['.caption' => 'Assign']);
    }

    public function testBindRejectsNumericStringWithoutCoerce(): void
    {
        $schema = $this->toolSchema('sleep', ['sleep']);

        $this->expectException(ArgumentBindException::class);
        (new ArgumentBinder())->bind($schema, [
            'seconds' => '1.5',
        ]);
    }
}
