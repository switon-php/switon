<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tool\FormAskTool;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\ToolCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Ai\TurnStatus;
use Switon\Tooling\Tools;

#[CoversClass(FormAskTool::class)]
#[Group('coroutine')]
class FormAskToolTest extends TestCase
{
    protected const string FORM_ID = 'stack-pick';

    /** @var list<string> */
    protected const array FORM_ASK_TYPES = [
        'input',
        'text',
        'textarea',
        'number',
        'email',
        'tel',
        'url',
        'password',
        'select',
        'radio',
        'checkbox',
        'switch',
        'choice',
        'slider',
        'rate',
        'tags',
        'date',
        'datetime',
        'time',
        'month',
        'range',
        'date-range',
        'time-range',
        'datetime-range',
        'month-range',
        'color',
    ];

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->replace(FormAskTool::class, [
            'class' => FormAskTool::class,
            'types' => self::FORM_ASK_TYPES,
        ]);
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'form_ask' => FormAskTool::class,
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    protected function host(): RecordingRootAgent
    {
        $host = $this->make(RecordingRootAgent::class);
        $this->bindProfileOverride($host, $this->profileVariant($host->profile(), [
            'tools' => [...$host->profile()->tools, 'block'],
        ]));

        return $host;
    }

    public function testToolsCallReturnsToolReplyInterrupt(): void
    {
        $host = $this->host();
        $call = new ToolCall('call_ask', 'form_ask', [
            'id' => self::FORM_ID,
            'fields' => [[
                'name' => 'pick',
                'label' => 'Pick one',
                'type' => 'radio',
                'options' => ['a', 'b'],
            ]],
        ]);

        $dispatch = \Switon\Ai\Completion::of(\Switon\Ai\Message\UserMessage::of(''));
        $turn = \Switon\Ai\Turn::of($dispatch->completeId, status: TurnStatus::Running, model: 'main');
        $tools = $this->container->get(ToolsInterface::class);
        $schema = $tools->get('form_ask');
        $this->assertNotNull($schema);
        $outcome = $tools->call($host, $call, $schema);

        $this->assertInstanceOf(ToolReplyInterrupt::class, $outcome);
        $this->assertNull($turn->interrupt, 'Loop suspends Turn::$interrupt; Tools only returns Interrupt');
        $this->assertSame('call_ask', $outcome->callId);
        $this->assertSame(self::FORM_ID, $outcome->arguments['id'] ?? null);
        $this->assertSame('pick', $outcome->arguments['fields'][0]['name'] ?? null);
    }

    public function testTitleAndMultipleFieldsWireThroughTools(): void
    {
        $host = $this->host();

        $result = $this->invokeTool($host, new ToolCall('call_multi', 'form_ask', [
            'id' => 'setup-form',
            'title' => 'Setup',
            'fields' => [
                [
                    'name' => 'mode',
                    'label' => 'Mode?',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'agent', 'label' => 'Agent'],
                        ['value' => 'plan', 'label' => 'Plan'],
                    ],
                ],
                [
                    'name' => 'notes',
                    'label' => 'Notes',
                    'type' => 'textarea',
                    'placeholder' => 'Optional',
                ],
            ],
        ]));

        $this->assertSame('Setup', $result['arguments']['title'] ?? null);
        $this->assertSame('setup-form', $result['arguments']['id'] ?? null);
        $this->assertCount(2, $result['arguments']['fields'] ?? []);
        $this->assertSame('textarea', $result['arguments']['fields'][1]['type'] ?? null);
    }

    public function testPassthroughFieldsWithAttrs(): void
    {
        $host = $this->host();

        $result = $this->invokeTool($host, new ToolCall('call_fields', 'form_ask', [
            'id' => 'pick-stack',
            'title' => 'Pick',
            'fields' => [
                [
                    'name' => 'stack',
                    'label' => 'Stack?',
                    'type' => 'choice',
                    'options' => [
                        ['id' => 'php', 'label' => 'PHP'],
                        ['id' => 'node', 'label' => 'Node'],
                    ],
                    'allow_multiple' => true,
                ],
                [
                    'name' => 'when',
                    'label' => 'When?',
                    'type' => 'date',
                ],
            ],
        ]));

        $this->assertSame('pick-stack', $result['arguments']['id'] ?? null);
        $this->assertSame('stack', $result['arguments']['fields'][0]['name'] ?? null);
        $this->assertSame('Stack?', $result['arguments']['fields'][0]['label'] ?? null);
        $this->assertSame('choice', $result['arguments']['fields'][0]['type'] ?? null);
        $this->assertTrue($result['arguments']['fields'][0]['allow_multiple'] ?? false);
        $this->assertSame('date', $result['arguments']['fields'][1]['type'] ?? null);
    }

    public function testSchemaLocksCoreFieldsAndAllowsPassthroughAttrs(): void
    {
        $tool = $this->container->get(FormAskTool::class);
        $this->assertInstanceOf(FormAskTool::class, $tool);

        $schema = $tool->schema('form_ask')?->jsonSchema() ?? [];
        $fieldItem = $schema['properties']['fields']['items'] ?? null;

        $this->assertIsArray($fieldItem);
        $this->assertSame('object', $fieldItem['type'] ?? null);
        $this->assertTrue($fieldItem['additionalProperties'] ?? false);
        $this->assertSame(['name', 'label', 'type'], $fieldItem['required'] ?? null);

        $properties = $fieldItem['properties'];
        $this->assertSame(['name', 'label', 'type', 'options'], array_keys($properties));

        $optionsSchema = $properties['options'] ?? null;
        $this->assertIsArray($optionsSchema);
        $this->assertSame('array', $optionsSchema['type'] ?? null);
        $this->assertArrayHasKey('description', $optionsSchema);
        $this->assertArrayNotHasKey('items', $optionsSchema);

        $typeSchema = $properties['type'] ?? null;
        $this->assertIsArray($typeSchema);
        $this->assertSame('string', $typeSchema['type'] ?? null);
        $enum = $typeSchema['enum'] ?? [];
        $this->assertContains('choice', $enum);
        $this->assertContains('tags', $enum);
        $this->assertContains('date-range', $enum);
        $this->assertArrayNotHasKey('description', $typeSchema);

        $fieldItemDescription = $fieldItem['description'] ?? '';
        $this->assertIsString($fieldItemDescription);
        $this->assertStringContainsString('HTML5', $fieldItemDescription);
        $this->assertStringContainsString('placeholder', $fieldItemDescription);
        $this->assertStringContainsString('allow_multiple', $fieldItemDescription);
        $this->assertStringContainsString('needs-reply', $tool->schema('form_ask')?->definition['description'] ?? null ?? '');
    }

    public function testSchemaNullWhenTypesEmpty(): void
    {
        $this->container->replace(FormAskTool::class, [
            'class' => FormAskTool::class,
            'types' => [],
        ]);

        $tool = $this->container->get(FormAskTool::class);
        $this->assertInstanceOf(FormAskTool::class, $tool);
        $this->assertNull($tool->schema('form_ask'));
    }

    public function testSchemaTypesFollowDiInjection(): void
    {
        $this->container->replace(FormAskTool::class, [
            'class' => FormAskTool::class,
            'types' => ['choice', 'address'],
        ]);

        $tool = $this->container->get(FormAskTool::class);
        $this->assertInstanceOf(FormAskTool::class, $tool);

        $enum = $tool->schema('form_ask')?->jsonSchema()['properties']['fields']['items']['properties']['type']['enum'] ?? [];
        $this->assertSame(['choice', 'address'], $enum);
    }

    public function testDirectCallReturnsToolReplyInterrupt(): void
    {
        $tool = $this->container->get(FormAskTool::class);
        $this->assertInstanceOf(FormAskTool::class, $tool);

        $interrupt = $tool->call(
            \Switon\Agency\Tests\Fixtures\StubToolAgent::in(),
            new ToolCall('call_x', 'form_ask'),
            [
                'id' => 'x',
                'fields' => [['name' => 'a', 'label' => 'A', 'type' => 'input']],
            ],
        );

        $this->assertInstanceOf(ToolReplyInterrupt::class, $interrupt);
        $this->assertSame('call_x', $interrupt->callId);
        $this->assertSame('x', $interrupt->arguments['id'] ?? null);
    }
}
