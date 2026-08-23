<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use Switon\Agency\Tests\CatalogTestCase;
use Switon\Agency\Agent;
use Switon\Agency\Charters;
use Switon\Agency\Exception;
use Switon\Agency\Segments;
use Switon\Ai\AgentInterface;
use Switon\Ai\Profile;
use Switon\Ai\PromptRendererInterface;
use Switon\Ai\PromptVariablesInterface;
use Switon\Ai\Segment;
use Switon\Ai\Suspension\Interrupt;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolkitInterface;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Tooling\ToolsInterface;

#[CoversClass(Segments::class)]
#[CoversClass(Charters::class)]
class SegmentsChartersTest extends CatalogTestCase
{
    public function testSetGetHasAndAll(): void
    {
        $segments = new Segments();
        $segment = Segment::decode(['id' => 'extra', 'tools' => ['echo']]);

        $this->assertFalse($segments->has('extra'));
        $segments->set($segment);
        $this->assertTrue($segments->has('extra'));
        $this->assertSame($segment, $segments->get('extra'));
        $this->assertSame(['extra' => $segment], $segments->all());
    }

    public function testGetMergesProfileAndRegistrySegments(): void
    {
        $segments = new Segments();
        $segments->set(Segment::decode(['id' => 'mcp', 'tools' => ['mcp_servers', 'mcp_call']]));
        $segments->set(Segment::decode(['id' => 'session.prompt']));

        $profile = Profile::decode([
            'id' => 'host',
            'prompt' => 'Role.',
            'tools' => ['echo'],
        ]);
        $charters = $this->makeCharters($segments, new class () implements PromptRendererInterface {
            public function render(AgentInterface $agent, string $prompt = '', array $segments = [], array $data = []): string
            {
                unset($segments, $data);

                return $prompt;
            }

            public function registerVariables(PromptVariablesInterface $variables): void
            {
                unset($variables);
            }
        }, new class () implements ToolsInterface {
            public function get(string $name): ?ToolSchema
            {
                unset($name);

                return null;
            }

            public function pick(array $names): array
            {
                $out = [];
                foreach ($names as $name) {
                    $out[$name] = new ToolSchema(description: 't');
                }

                return $out;
            }

            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }

            public function call(AgentInterface $agent, ToolCall $call, ToolSchema $schema): ToolResult|Interrupt
            {
                return new ToolResult();
            }

            public function addTool(string $name, \Switon\Ai\ToolInterface $tool, bool $overwrite = false): void
            {
            }

            public function removeTool(string $name): void
            {
            }

            public function addToolkit(string $prefix, ToolkitInterface $toolkit): void
            {
            }

            public function removeToolkit(string $prefix): void
            {
            }

            public function reload(): void
            {
            }
        });

        $charter = $charters->get($this->agent($profile, ['mcp', 'session.prompt']));

        $this->assertArrayHasKey('echo', $charter->tools);
        $this->assertArrayHasKey('mcp_call', $charter->tools);
        $this->assertArrayHasKey('mcp_servers', $charter->tools);
        $this->assertArrayNotHasKey('demo_ping', $charter->tools);
        $this->assertSame('Role.', $charter->prompt);
    }

    public function testGetPrefersDeskSegmentOverSharedLeaf(): void
    {
        $segments = new Segments();
        $segments->set(Segment::decode(['id' => 'risk', 'prompt' => 'Shared.']));
        $segments->set(Segment::decode(['id' => 'demo.risk', 'prompt' => 'Desk.']));
        $segments->set(Segment::decode(['id' => 'commentary', 'prompt' => 'Note.']));
        $seen = [];
        $renderer = new class ($seen) implements PromptRendererInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function render(AgentInterface $agent, string $prompt = '', array $segments = [], array $data = []): string
            {
                unset($agent, $prompt, $data);
                $this->seen = $segments;

                return '';
            }

            public function registerVariables(PromptVariablesInterface $variables): void
            {
                unset($variables);
            }
        };
        $charters = $this->makeCharters($segments, $renderer, $this->emptyTools());
        $profile = Profile::decode([
            'id' => 'demo.chat',
            'segments' => ['risk', 'commentary', 'demo.risk'],
        ]);

        $charters->get($this->agent($profile));

        $this->assertSame(['Desk.', 'Note.'], $seen);
    }

    public function testGetExposesAgentIsRootFlag(): void
    {
        $seen = [];
        $renderer = new class ($seen) implements PromptRendererInterface {
            /** @param list<bool> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function render(AgentInterface $agent, string $prompt = '', array $segments = [], array $data = []): string
            {
                $this->seen[] = $data['agent']['is_root'];

                return $prompt;
            }

            public function registerVariables(PromptVariablesInterface $variables): void
            {
                unset($variables);
            }
        };
        $charters = $this->makeCharters(
            new Segments(),
            $renderer,
            $this->emptyTools(),
        );
        $profile = Profile::decode(['id' => 'host', 'prompt' => 'Role.']);

        $this->assertSame('Role.', $charters->get($this->agent($profile))->prompt);
        $this->assertSame('Role.', $charters->get($this->agent($profile, root: false))->prompt);
        $this->assertSame([true, false], $seen);
    }

    public function testGetMissingSegmentRaises(): void
    {
        $charters = $this->makeCharters(new Segments(), new class () implements PromptRendererInterface {
            public function render(AgentInterface $agent, string $prompt = '', array $segments = [], array $data = []): string
            {
                unset($segments, $data);

                return '';
            }

            public function registerVariables(PromptVariablesInterface $variables): void
            {
                unset($variables);
            }
        }, new class () implements ToolsInterface {
            public function get(string $name): ?ToolSchema
            {
                unset($name);

                return null;
            }

            public function pick(array $names): array
            {
                unset($names);

                return [];
            }

            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }

            public function call(AgentInterface $agent, ToolCall $call, ToolSchema $schema): ToolResult|Interrupt
            {
                return new ToolResult();
            }

            public function addTool(string $name, \Switon\Ai\ToolInterface $tool, bool $overwrite = false): void
            {
            }

            public function removeTool(string $name): void
            {
            }

            public function addToolkit(string $prefix, ToolkitInterface $toolkit): void
            {
            }

            public function removeToolkit(string $prefix): void
            {
            }

            public function reload(): void
            {
            }
        });

        $this->expectException(Exception::class);
        $charters->get($this->agent(Profile::decode(['id' => 'h']), ['missing']));
    }

    public function testGetRejectsUnknownSegment(): void
    {
        $segments = new Segments();
        $segments->set(Segment::decode(['id' => 'extra', 'tools' => ['echo']]));

        $charters = $this->makeCharters($segments, new class () implements PromptRendererInterface {
            public function render(AgentInterface $agent, string $prompt = '', array $segments = [], array $data = []): string
            {
                unset($segments, $data);

                return $prompt;
            }

            public function registerVariables(PromptVariablesInterface $variables): void
            {
                unset($variables);
            }
        }, new class () implements ToolsInterface {
            public function get(string $name): ?ToolSchema
            {
                unset($name);

                return null;
            }

            public function pick(array $names): array
            {
                $out = [];
                foreach ($names as $name) {
                    $out[$name] = new ToolSchema(description: 't');
                }

                return $out;
            }

            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }

            public function call(AgentInterface $agent, ToolCall $call, ToolSchema $schema): ToolResult|Interrupt
            {
                return new ToolResult();
            }

            public function addTool(string $name, \Switon\Ai\ToolInterface $tool, bool $overwrite = false): void
            {
            }

            public function removeTool(string $name): void
            {
            }

            public function addToolkit(string $prefix, ToolkitInterface $toolkit): void
            {
            }

            public function removeToolkit(string $prefix): void
            {
            }

            public function reload(): void
            {
            }
        });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Segment "missing" not found.');
        $charters->get($this->agent(Profile::decode([
            'id' => 'host',
            'prompt' => 'Role.',
            'tools' => ['echo'],
        ]), ['extra', 'missing']));
    }


    protected function makeCharters(
        Segments $segments,
        PromptRendererInterface $renderer,
        ToolsInterface $tools,
    ): Charters {
        $charters = (new ReflectionClass(Charters::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($charters);
        $ref->getProperty('segments')->setValue($charters, $segments);
        $ref->getProperty('promptRenderer')->setValue($charters, $renderer);
        $ref->getProperty('tools')->setValue($charters, $tools);

        return $charters;
    }

    /** @param list<string> $segments */
    protected function agent(Profile $profile, array $segments = [], bool $root = true): Agent
    {
        $agent = $this->bareAgent($profile, $segments);

        if (!$root) {
            $parent = $this->bareAgent($profile);
            (new ReflectionClass($agent))->getProperty('parent')->setValue($agent, $parent);
        }

        return $agent;
    }

    /** @param list<string> $segments */
    protected function bareAgent(Profile $profile, array $segments = []): Agent
    {
        if ($segments !== []) {
            $profile = clone $profile;
            $profile->segments = [...$profile->segments, ...$segments];
        }
        $agent = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($agent);
        $ref->getProperty('thread')->setValue($agent, Thread::of($profile->id));
        $ref->getProperty('profile')->setValue($agent, $profile);
        $ref->getProperty('workspace')->setValue($agent, '');
        $ref->getProperty('parent')->setValue($agent, null);

        return $agent;
    }

    protected function emptyTools(): ToolsInterface
    {
        return new class () implements ToolsInterface {
            public function get(string $name): ?ToolSchema
            {
                unset($name);

                return null;
            }

            public function pick(array $names): array
            {
                unset($names);

                return [];
            }

            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }

            public function call(AgentInterface $agent, ToolCall $call, ToolSchema $schema): ToolResult|Interrupt
            {
                return new ToolResult();
            }

            public function addTool(string $name, \Switon\Ai\ToolInterface $tool, bool $overwrite = false): void
            {
            }

            public function removeTool(string $name): void
            {
            }

            public function addToolkit(string $prefix, ToolkitInterface $toolkit): void
            {
            }

            public function removeToolkit(string $prefix): void
            {
            }

            public function reload(): void
            {
            }
        };
    }
}
