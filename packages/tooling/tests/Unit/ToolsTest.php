<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use ArrayIterator;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use stdClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\Suspension\Interrupt;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolkitInterface;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Event\ToolFailed;
use Switon\Tooling\Event\ToolFinished;
use Switon\Tooling\Event\ToolMissing;
use Switon\Tooling\Event\ToolStarted;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\Exception\ArgumentRequiredException;
use Switon\Tooling\ExecToolkit;
use Switon\Tooling\Tests\Fixtures\ToolTestAgent;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tools;

#[CoversClass(Tools::class)]
#[CoversClass(ArgumentBinder::class)]
class ToolsTest extends ToolTestCase
{
    /** Full default registry must instantiate — catches tools whose signature drifted from {@see ToolInterface}. */
    public function testDefaultToolsAllInstantiate(): void
    {
        $tools = $this->make(Tools::class);

        $this->assertArrayHasKey('ls', $tools->pick(['ls']));
        $this->assertArrayHasKey('media', $tools->pick(['media']));
    }

    public function testExposeIncludesToolsAndToolkitWires(): void
    {
        $tools = $this->makeEmptyTools();
        $tools->addTool('alpha', $this->stubTool('Alpha'));
        $tools->addTool('off', $this->stubTool(disabled: true));
        $tools->addToolkit('site', $this->stubToolkit(['site_zhihu' => 'Zhihu', 'site_github' => 'GitHub']));

        $this->assertSame('Alpha', $tools->pick(['alpha'])['alpha']->definition['description']);
        $this->assertSame('Zhihu', $tools->pick(['site_zhihu'])['site_zhihu']->definition['description']);
        $this->assertArrayHasKey('site_github', $tools->pick(['site_github']));
        $this->assertArrayNotHasKey('off', $tools->pick(['off']));
    }

    public function testExposeInjectsCaptionIntoSchema(): void
    {
        $original = new ToolSchema(description: 'Echo', properties: [
            'text' => ['type' => 'string'],
        ], required: ['text']);
        $tools = $this->makeEmptyTools();
        $tools->addTool('echo', $this->stubTool(schema: $original));

        $exposed = $tools->pick(['echo']);

        $this->assertSame($original, $exposed['echo']);
        $props = $exposed['echo']->definition['inputSchema']['properties'];
        $this->assertArrayHasKey('.caption', $props);
        $this->assertSame('string', $props['.caption']['type']);
        $this->assertContains('.caption', $exposed['echo']->definition['inputSchema']['required']);
        $this->assertArrayHasKey('.caption', $original->definition['inputSchema']['properties']);
    }

    public function testExposeSkipsCaptionInjectWhenAlreadyPresent(): void
    {
        $schema = new ToolSchema(description: 'Has caption', properties: [
            '.caption' => ['type' => 'string', 'description' => 'custom'],
            'text' => ['type' => 'string'],
        ], required: ['.caption']);
        $tools = $this->makeEmptyTools();
        $tools->addTool('cap', $this->stubTool(schema: $schema));

        $exposed = $tools->pick(['cap']);

        $this->assertSame('custom', $exposed['cap']->definition['inputSchema']['properties']['.caption']['description']);
        $this->assertSame(['.caption'], $exposed['cap']->definition['inputSchema']['required']);
    }

    /**
     * Spec tools/list example `get_weather` (2025-11-25 server/tools): properties + required.
     */
    public function testPickInjectsCaptionWithMcpWeatherSchema(): void
    {
        $input = $this->pickMcpTool('get_weather', [
            'name' => 'get_weather',
            'title' => 'Weather Information Provider',
            'description' => 'Get current weather information for a location',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'City name or zip code',
                    ],
                ],
                'required' => ['location'],
            ],
        ]);

        $this->assertSame('string', $input['properties']['location']['type']);
        $this->assertSame('City name or zip code', $input['properties']['location']['description']);
        $this->assertSame('string', $input['properties']['.caption']['type']);
        $this->assertSame(['location', '.caption'], $input['required']);
        $this->assertSame('object', $input['type']);
    }

    /**
     * Spec schema example `calculate_sum` with explicit draft-07 `$schema`.
     */
    public function testPickInjectsCaptionWithMcpDraft07Schema(): void
    {
        $input = $this->pickMcpTool('calculate_sum', [
            'name' => 'calculate_sum',
            'description' => 'Add two numbers',
            'inputSchema' => [
                '$schema' => 'http://json-schema.org/draft-07/schema#',
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'number'],
                    'b' => ['type' => 'number'],
                ],
                'required' => ['a', 'b'],
            ],
        ]);

        $this->assertSame('http://json-schema.org/draft-07/schema#', $input['$schema']);
        $this->assertSame('string', $input['properties']['.caption']['type']);
        $this->assertSame('number', $input['properties']['a']['type']);
        $this->assertSame('number', $input['properties']['b']['type']);
        $this->assertSame(['a', 'b', '.caption'], $input['required']);
    }

    /**
     * Spec schema example `get_current_time`: no parameters, closed object.
     * `properties` and `required` are both absent.
     */
    public function testPickInjectsCaptionWithMcpClosedEmptySchema(): void
    {
        $schema = $this->exposeMcpTool('get_current_time', [
            'name' => 'get_current_time',
            'description' => 'Returns the current server time',
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
        ]);
        $input = $schema->definition['inputSchema'];

        $this->assertFalse($input['additionalProperties']);
        $this->assertSame('object', $input['type']);
        $this->assertSame(['.caption'], array_keys($input['properties']));
        $this->assertSame(['.caption'], $input['required']);
        $this->assertArrayHasKey('.caption', $schema->jsonSchema()['properties']);
    }

    /**
     * Spec no-parameter alternative: `{ "type": "object" }` accepts any object.
     */
    public function testPickInjectsCaptionWithMcpBareObjectSchema(): void
    {
        $schema = $this->exposeMcpTool('bare_object', [
            'inputSchema' => [
                'type' => 'object',
            ],
        ]);
        $input = $schema->definition['inputSchema'];

        $this->assertSame('object', $input['type']);
        $this->assertArrayNotHasKey('additionalProperties', $input);
        $this->assertSame(['.caption'], array_keys($input['properties']));
        $this->assertSame(['.caption'], $input['required']);
        $this->assertArrayHasKey('.caption', $schema->jsonSchema()['properties']);
    }

    public function testExposeDedupesNamesAndSupportsToolkitAndExec(): void
    {
        $tools = $this->makeEmptyTools();
        $tools->addToolkit('site', $this->stubToolkit(['site_zhihu' => 'Zhihu']));

        $exposed = $tools->pick(['site_zhihu', 'site_zhihu', 'echo']);

        $this->assertSame(['site_zhihu', 'echo'], array_keys($exposed));
        $this->assertArrayHasKey('.caption', $exposed['echo']->definition['inputSchema']['properties']);
        $this->assertStringContainsString('Unlike `bash`', $exposed['echo']->definition['description']);
    }

    public function testExposeOmitsUnknownAndEmitsToolMissingOnce(): void
    {
        $tools = $this->makeEmptyTools();
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $exposed = $tools->pick(['NotAValidExec', 'NotAValidExec', 'echo']);

        $this->assertSame(['echo'], array_keys($exposed));
        $events = $dispatcher->getEventsByClass(ToolMissing::class);
        $this->assertCount(1, $events);
        $this->assertSame('NotAValidExec', $events[0]->name);
    }

    public function testExposeOmitsDisabledSchemaAndEmitsToolMissing(): void
    {
        $tools = $this->makeEmptyTools();
        $tools->addTool('off', $this->stubTool(disabled: true));
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $this->assertSame([], $tools->pick(['off']));
        $events = $dispatcher->getEventsByClass(ToolMissing::class);
        $this->assertCount(1, $events);
        $this->assertSame('off', $events[0]->name);
    }

    public function testExposeOmitsUnclaimedToolkitName(): void
    {
        $toolkit = $this->createStub(ToolkitInterface::class);
        $toolkit->method('schema')->willReturn(null);

        $tools = $this->makeEmptyTools();
        $tools->addToolkit('tk', $toolkit);
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $this->assertSame([], $tools->pick(['tk_m']));
        $this->assertSame('tk_m', $dispatcher->getEventsByClass(ToolMissing::class)[0]->name);
    }

    public function testCallWrapsMixedResultWithTimingIdAndName(): void
    {
        $tools = $this->makeEmptyTools();
        $tools->addTool('echo', $this->stubTool(callResult: ['ok' => true]));
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $result = $this->callTools($tools, 'echo');

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame(['ok' => true], $result->content);
        $this->assertSame('c1', $result->id);
        $this->assertSame('echo', $result->name);
        $this->assertNotNull($result->timing);
        $this->assertTrue($dispatcher->hasDispatched(ToolStarted::class));
        $this->assertTrue($dispatcher->hasDispatched(ToolFinished::class));
        $this->assertFalse($dispatcher->hasDispatched(ToolFailed::class));
    }

    public function testCallKeepsExistingToolResultAndEnriches(): void
    {
        $inner = new ToolResult(content: 'kept', id: 'old', name: 'old');
        $tools = $this->makeEmptyTools();
        $tools->addTool('echo', $this->stubTool(callResult: $inner));

        $result = $this->callTools($tools, 'echo');

        $this->assertSame($inner, $result);
        $this->assertSame('c1', $result->id);
        $this->assertSame('echo', $result->name);
        $this->assertNotNull($result->timing);
        $this->assertSame('kept', $result->content);
    }

    public function testCallReturnsInterruptAsIsWithoutFinished(): void
    {
        $call = new ToolCall(id: 'c1', name: 'suspend', arguments: []);
        $interrupt = ToolReplyInterrupt::of($call);
        $tools = $this->makeEmptyTools();
        $tools->addTool('suspend', $this->stubTool(callResult: $interrupt));
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $result = $this->callTools($tools, 'suspend');

        $this->assertSame($interrupt, $result);
        $this->assertTrue($dispatcher->hasDispatched(ToolStarted::class));
        $this->assertFalse($dispatcher->hasDispatched(ToolFinished::class));
    }

    public function testCallDispatchesToolkitMember(): void
    {
        $toolkit = $this->createMock(ToolkitInterface::class);
        $toolkit->method('schema')->willReturn(new ToolSchema($toolkit, description: 'm'));
        $toolkit->expects($this->once())
            ->method('call')
            ->with(
                $this->isInstanceOf(AgentInterface::class),
                $this->callback(static fn (ToolCall $c): bool => $c->id === 'c1' && $c->name === 'tk_m'),
                $this->anything(),
            )
            ->willReturn(['from' => 'toolkit']);

        $tools = $this->makeEmptyTools();
        $tools->addToolkit('tk', $toolkit);

        $result = $this->callTools($tools, 'tk_m');

        $this->assertSame(['from' => 'toolkit'], $result->content);
    }

    public function testCallDispatchesExecForMatchingId(): void
    {
        $tools = $this->makeEmptyTools();

        $result = $this->callTools($tools, 'echo', ['argv' => ['hi']]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertIsArray($result->content);
    }

    public function testCallRunsHandlerWhenArgumentsAreUnbound(): void
    {
        $schema = new ToolSchema(description: 'need', properties: [
            'text' => ['type' => 'string'],
        ], required: ['text']);
        $tools = $this->makeEmptyTools();
        $tools->addTool('need', $this->stubTool(schema: $schema));

        $result = $this->callTools($tools, 'need', []);

        $this->assertSame(['ok' => true], $result->content);
    }

    public function testCallLandsArgumentExceptionAsResultAndToolFailed(): void
    {
        $tools = $this->makeEmptyTools();
        $tools->addTool('need', $this->stubTool(callResult: static function (): never {
            ArgumentRequiredException::raise('{field} required', ['field' => 'message']);
        }));
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $result = $this->callTools($tools, 'need');

        $this->assertInstanceOf(ArgumentRequiredException::class, $result->content);
        $this->assertTrue($result->isError);
        $this->assertTrue($dispatcher->hasDispatched(ToolFailed::class));
        $this->assertTrue($dispatcher->hasDispatched(ToolFinished::class));
    }

    public function testCallLandsOtherThrowableAsResultAndToolFailed(): void
    {
        $tools = $this->makeEmptyTools();
        $tools->addTool('boom', $this->stubTool(callResult: static function (): never {
            throw new RuntimeException('native down');
        }));
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $result = $this->callTools($tools, 'boom');

        $this->assertInstanceOf(RuntimeException::class, $result->content);
        $this->assertSame('native down', $result->content->getMessage());
        $this->assertTrue($result->isError);
        $this->assertTrue($dispatcher->hasDispatched(ToolFailed::class));
        $this->assertTrue($dispatcher->hasDispatched(ToolFinished::class));
    }

    public function testCallEmitsToolFailedWhenResultIsMarkedError(): void
    {
        $tools = $this->makeEmptyTools();
        $marked = new ToolResult('backend failed');
        $marked->isError = true;
        $tools->addTool('remote', $this->stubTool(callResult: $marked));
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $result = $this->callTools($tools, 'remote');

        $this->assertTrue($result->isError);
        $this->assertSame('backend failed', $result->content);
        $this->assertTrue($dispatcher->hasDispatched(ToolFailed::class));
        $failed = $dispatcher->getEventsByClass(ToolFailed::class);
        $this->assertSame('backend failed', $failed[0]->message());
    }

    public function testIsReadonlyRoutesToolkitMember(): void
    {
        $toolkit = $this->createMock(ToolkitInterface::class);
        $toolkit->method('schema')->willReturn(new ToolSchema($toolkit));
        $toolkit->expects($this->once())
            ->method('isReadonly')
            ->with('tk_m', ['q' => 1])
            ->willReturn(true);

        $tools = $this->makeEmptyTools();
        $tools->addToolkit('tk', $toolkit);

        $this->assertTrue($tools->isReadonly('tk_m', ['q' => 1]));
    }

    public function testIsReadonlyRoutesExecId(): void
    {
        $tools = $this->makeEmptyTools();

        $this->assertFalse($tools->isReadonly('echo', ['argv' => ['hi']]));
    }

    public function testAddToolkitRegistersUnderAnyLabel(): void
    {
        $tools = $this->makeEmptyTools();
        $toolkit = $this->stubToolkit(['site_m' => 'Member']);

        $tools->addToolkit('site', $toolkit);
        $this->assertArrayHasKey('site_m', $tools->pick(['site_m']));

        // Label is bookkeeping — an underscore is fine, and the label never reaches a wire id.
        $tools->addToolkit('bad_prefix', $toolkit);
        $this->assertArrayHasKey('site_m', $tools->pick(['site_m']));
    }

    public function testRemoveToolkitUnregistersByLabel(): void
    {
        $tools = $this->makeEmptyTools();
        $tools->addToolkit('site', $this->stubToolkit(['site_m' => 'Member']));
        $this->assertArrayHasKey('site_m', $tools->pick(['site_m']));

        $tools->removeToolkit('site');

        $this->assertArrayNotHasKey('site_m', $tools->pick(['site_m']));
    }

    public function testReloadCallsEachToolkit(): void
    {
        $a = $this->createMock(ToolkitInterface::class);
        $a->expects($this->once())->method('reload');
        $b = $this->createMock(ToolkitInterface::class);
        $b->expects($this->once())->method('reload');

        $tools = $this->makeEmptyTools();
        $tools->addToolkit('a', $a);
        $tools->addToolkit('b', $b);

        $tools->reload();
    }

    public function testBindArgumentsAppliesDefaultsAndSkipsDotFields(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'msg' => ['type' => 'string', 'default' => 'hi'],
            '.caption' => ['type' => 'string', 'default' => 'skip'],
        ], required: ['.caption', 'msg']);

        $bound = $this->bind($schema, []);

        $this->assertSame(['msg' => 'hi'], $bound);
        $this->assertArrayNotHasKey('.caption', $bound);
    }

    public function testBindArgumentsRaisesWhenRequiredMissingOrNull(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'msg' => ['type' => 'string'],
        ], required: ['msg']);

        $this->expectException(ArgumentBindException::class);
        $this->bind($schema, ['msg' => null]);
    }

    public function testBindArgumentsRaisesOnTypeMismatch(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'flag' => ['type' => 'boolean'],
        ]);

        $this->expectException(ArgumentBindException::class);
        $this->bind($schema, ['flag' => 1]);
    }

    public function testBindArgumentsAcceptsNullValueWithoutTypeCheck(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'opt' => ['type' => 'string'],
        ]);

        $bound = $this->bind($schema, ['opt' => null]);

        $this->assertSame(['opt' => null], $bound);
    }

    public function testBindArgumentsFillsDefaultForExplicitNull(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'timeout' => ['type' => 'number', 'default' => 3600],
        ]);

        $bound = $this->bind($schema, ['timeout' => null]);

        $this->assertSame(['timeout' => 3600], $bound);
    }

    public function testBindArgumentsAcceptsUnknownTypeAndObjectShapes(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'x' => ['type' => 'custom'],
            'objArr' => ['type' => 'object'],
            'objStd' => ['type' => 'object'],
        ]);
        $std = new stdClass();

        $bound = $this->bind($schema, [
            'x' => 123,
            'objArr' => ['a' => 1],
            'objStd' => $std,
        ]);

        $this->assertSame(123, $bound['x']);
        $this->assertSame(['a' => 1], $bound['objArr']);
        $this->assertSame($std, $bound['objStd']);
    }

    public function testBindArgumentsAcceptsScalarAndArrayTypes(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            's' => ['type' => 'string'],
            'i' => ['type' => 'integer'],
            'n' => ['type' => 'number'],
            'b' => ['type' => 'boolean'],
            'a' => ['type' => 'array'],
        ]);

        $bound = $this->bind($schema, [
            's' => 'ok',
            'i' => 1,
            'n' => 1.5,
            'b' => false,
            'a' => [1],
        ]);

        $this->assertSame('ok', $bound['s']);
        $this->assertSame(1.5, $bound['n']);
    }

    public function testBindArgumentsEnforcesEnumAndStringLength(): void
    {
        $enumSchema = new ToolSchema(description: 'd', properties: [
            'mode' => ['type' => 'string', 'enum' => ['a', 'b']],
        ]);

        $this->assertSame(['mode' => 'a'], $this->bind($enumSchema, ['mode' => 'a']));

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('mode must be one of ["a","b"]');
        $this->bind($enumSchema, ['mode' => 'z']);
    }

    public function testBindArgumentsEnforcesMinAndMaxLength(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            's' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 3],
        ]);

        $this->assertSame(['s' => 'ab'], $this->bind($schema, ['s' => 'ab']));

        try {
            $this->bind($schema, ['s' => 'a']);
            $this->fail('expected minLength');
        } catch (ArgumentBindException) {
        }

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('s longer than 3');
        $this->bind($schema, ['s' => 'abcd']);
    }

    public function testBindArgumentsEnforcesNumericBounds(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'n' => [
                'type' => 'number',
                'minimum' => 1,
                'maximum' => 10,
                'exclusiveMinimum' => 1,
                'exclusiveMaximum' => 10,
            ],
        ]);

        $this->assertSame(['n' => 5.0], $this->bind($schema, ['n' => 5.0]));

        foreach ([0.5, 10.5, 1.0, 10.0] as $bad) {
            try {
                $this->bind($schema, ['n' => $bad]);
                $this->fail("expected invalid for {$bad}");
            } catch (ArgumentBindException) {
            }
        }
    }

    public function testBindArgumentsEnforcesIntegerMinimumMaximum(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'n' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 4],
        ]);

        $this->assertSame(['n' => 3], $this->bind($schema, ['n' => 3]));

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('n below 2');
        $this->bind($schema, ['n' => 1]);
    }

    public function testBindArgumentsEnforcesArrayItemCount(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'a' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2],
        ]);

        $this->assertSame(['a' => [1]], $this->bind($schema, ['a' => [1]]));

        try {
            $this->bind($schema, ['a' => []]);
            $this->fail('expected minItems');
        } catch (ArgumentBindException) {
        }

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('a has more than 2 items');
        $this->bind($schema, ['a' => [1, 2, 3]]);
    }

    public function testBindArgumentsEnforcesArrayItemTypeAndLength(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'a' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 3],
            ],
        ]);

        $this->assertSame(['a' => ['ab']], $this->bind($schema, ['a' => ['ab']]));

        try {
            $this->bind($schema, ['a' => [1]]);
            $this->fail('expected item type');
        } catch (ArgumentBindException) {
        }

        try {
            $this->bind($schema, ['a' => ['a']]);
            $this->fail('expected item minLength');
        } catch (ArgumentBindException) {
        }

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('a.0 longer than 3');
        $this->bind($schema, ['a' => ['abcd']]);
    }

    public function testBindArgumentsEnforcesNestedItemRequiredFields(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'edits' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['old', 'new'],
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'old' => ['type' => 'string', 'minLength' => 1],
                        'new' => ['type' => 'string'],
                        'all' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ]);

        $args = ['edits' => [['old' => 'a', 'new' => 'b']]];
        $this->assertSame($args, $this->bind($schema, $args));

        try {
            $this->bind($schema, ['edits' => [['old' => 'a']]]);
            $this->fail('expected nested required');
        } catch (ArgumentBindException $e) {
            $this->assertStringContainsString('new', $e->getMessage());
        }

        $this->expectException(ArgumentBindException::class);
        $this->bind($schema, ['edits' => [['old' => 'a', 'new' => 123]]]);
    }

    public function testBindArgumentsTreatsObjectItemsWithoutPropertiesAsOpaque(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
        ]);

        $std = new stdClass();
        $bound = $this->bind($schema, ['items' => [['any' => 1], $std]]);

        $this->assertSame(['any' => 1], $bound['items'][0]);
        $this->assertSame($std, $bound['items'][1]);
    }

    public function testBindAcceptsIntegralFloatAsInteger(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'n' => ['type' => 'integer', 'enum' => [1]],
        ]);

        $this->assertSame(['n' => 1.0], $this->bind($schema, ['n' => 1.0]));

        $this->expectException(ArgumentBindException::class);
        $this->bind($schema, ['n' => 1.5]);
    }

    public function testBindCountsStringLengthInCharacters(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            's' => ['type' => 'string', 'maxLength' => 1],
        ]);

        $this->assertSame(['s' => 'é'], $this->bind($schema, ['s' => 'é']));

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('s longer than 1');
        $this->bind($schema, ['s' => 'ab']);
    }

    public function testBindAcceptsNullWhenTypeIncludesNull(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'msg' => ['type' => ['string', 'null']],
        ], required: ['msg']);

        $this->assertSame(['msg' => null], $this->bind($schema, ['msg' => null]));
    }

    public function testBindRejectsValueMatchingNoListedType(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['type' => ['string', 'integer']],
        ]);

        $this->assertSame(['v' => 1.0], $this->bind($schema, ['v' => 1.0]));

        $this->expectException(ArgumentBindException::class);
        $this->bind($schema, ['v' => true]);
    }

    public function testBindDoesNotRejectUnknownTypeKeyword(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['type' => ['string', 'widget']],
        ]);

        $this->assertSame(['v' => true], $this->bind($schema, ['v' => true]));
    }

    public function testBindDoesNotRejectOneOfBranch(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ]],
        ]);

        $this->assertSame(['v' => 1], $this->bind($schema, ['v' => 1]));
        $this->assertSame(['v' => 'a'], $this->bind($schema, ['v' => 'a']));
    }

    public function testBindChecksNestedObjectRequiredAndTypesBeforeValues(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'mode' => ['type' => 'string', 'enum' => ['a']],
            'meta' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
        ]);

        try {
            $this->bind($schema, ['mode' => 'z', 'meta' => ['id' => 'x']]);
            $this->fail('expected bind errors');
        } catch (ArgumentBindException $e) {
            $this->assertSame([
                'mode' => 'mode must be one of ["a"]',
                'meta.id' => 'meta.id type invalid',
            ], $e->errors);
        }
    }

    public function testBindKeepsOneFailurePerPathAndSkipsChildrenOfAFailedParent(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'mode' => ['type' => 'string', 'enum' => ['a', 'b']],
            'n' => ['type' => 'integer', 'minimum' => 2],
            'meta' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
            'edits' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['old', 'new'],
                    'properties' => [
                        'old' => ['type' => 'string'],
                        'new' => ['type' => 'string'],
                    ],
                ],
            ],
        ], required: ['mode', 'n']);

        try {
            $this->bind($schema, [
                'mode' => 'z',
                'n' => 'nope',
                'meta' => 'nope',
                'edits' => [['old' => 'a']],
            ]);
            $this->fail('expected bind errors');
        } catch (ArgumentBindException $e) {
            $this->assertSame([
                'mode' => 'mode must be one of ["a","b"]',
                'n' => 'n type invalid',
                'meta' => 'meta type invalid',
                'edits.0.new' => 'edits.0.new required',
            ], $e->errors);
            $this->assertArrayNotHasKey('meta.id', $e->errors);
            $this->assertArrayNotHasKey('n.minimum', $e->errors);
        }
    }

    public function testBindRejectsMissingNestedRequired(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'meta' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->expectException(ArgumentBindException::class);
        $this->bind($schema, ['meta' => []]);
    }

    public function testBindRejectsPropertiesClosedBySchema(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'a' => ['type' => 'string'],
        ], additionalProperties: false);

        $this->assertSame(
            ['a' => 'x', '.caption' => 'Label'],
            $this->bind($schema, ['a' => 'x', '.caption' => 'Label']),
        );

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('extra is not allowed');
        $this->bind($schema, ['a' => 'x', 'extra' => 1]);
    }

    public function testBindEnforcesConst(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'mode' => ['type' => 'string', 'const' => 'a'],
        ]);

        $this->assertSame(['mode' => 'a'], $this->bind($schema, ['mode' => 'a']));

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('mode must be "a"');
        $this->bind($schema, ['mode' => 'z']);
    }

    /**
     * @param array<string, mixed> $definition MCP `tools/list` tool object
     *
     * @return array<string, mixed>
     */
    protected function pickMcpTool(string $name, array $definition): array
    {
        return $this->exposeMcpTool($name, $definition)->definition['inputSchema'];
    }

    /**
     * @param array<string, mixed> $definition MCP `tools/list` tool object
     */
    protected function exposeMcpTool(string $name, array $definition): ToolSchema
    {
        $tools = $this->makeEmptyTools();
        $tools->addTool($name, $this->stubTool(schema: ToolSchema::of($definition)));

        return $tools->pick([$name])[$name];
    }

    protected function makeEmptyTools(): Tools
    {
        return $this->make(Tools::class, [
            'tools' => new ArrayIterator([]),
            'toolkits' => new ArrayIterator(['exec' => ExecToolkit::class]),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callTools(Tools $tools, string $name, array $arguments = []): ToolResult|Interrupt
    {
        $call = new ToolCall(id: 'c1', name: $name, arguments: $arguments);
        $schema = $tools->get($name);
        $this->assertNotNull($schema);

        return $tools->call($this->toolAgent(), $call, $schema);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function bind(ToolSchema $schema, array $arguments): array
    {
        return (new ArgumentBinder())->bind($schema, $arguments);
    }

    protected function stubTool(
        string $description = 'd',
        mixed $callResult = ['ok' => true],
        ?ToolSchema $schema = null,
        bool $readonly = false,
        bool $disabled = false,
    ): ToolInterface {
        return new class ($description, $callResult, $schema, $readonly, $disabled) implements ToolInterface {
            public function __construct(
                protected string $description,
                protected mixed $callResult,
                protected ?ToolSchema $schema,
                protected bool $readonly,
                protected bool $disabled,
            ) {
                if ($this->schema !== null) {
                    $this->schema->handler = $this;
                }
            }

            public function schema(string $name): ?ToolSchema
            {
                unset($name);
                if ($this->disabled) {
                    return null;
                }

                return $this->schema ??= new ToolSchema($this, description: $this->description);
            }

            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                unset($call, $arguments, $agent);
                if ($this->callResult instanceof Closure) {
                    return ($this->callResult)();
                }

                return $this->callResult;
            }

            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return $this->readonly;
            }
        };
    }

    /**
     * @param array<string, string> $members member => description
     */
    protected function stubToolkit(array $members): ToolkitInterface
    {
        return new class ($members) implements ToolkitInterface {
            /** @param array<string, string> $members */
            public function __construct(protected array $members)
            {
            }

            public function schema(string $name): ?ToolSchema
            {
                if (!isset($this->members[$name])) {
                    return null;
                }

                return new ToolSchema($this, description: $this->members[$name]);
            }

            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                unset($agent, $arguments);

                return ['member' => $call->name, 'arguments' => $arguments];
            }

            public function reload(): void
            {
            }

            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }
        };
    }
}
