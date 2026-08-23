<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\TestAgent;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\EnvironmentVariables;
use Switon\Agency\PromptRenderer;
use Switon\Ai\Profile;
use Switon\Ai\AgentInterface;
use Switon\Ai\PromptRendererInterface;
use Switon\Ai\PromptVariablesInterface;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class AgentSystemPromptTest extends TestCase
{
    protected function systemPrompt(Agent $agent): string
    {
        return $agent->charter()->prompt;
    }

    public function testRenderReplacesRuntimePlaceholders(): void
    {
        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(
                prompt: '{{env.os}}|{{env.os.name}}|{{env.date_line}}|{{env.hostname}}|{{env.user}}|{{env.php}}',
            ),
        ]);

        $rendered = $this->systemPrompt($agent);
        $parts = explode('|', $rendered);

        $this->assertSame(PHP_OS_FAMILY, $parts[0]);
        $this->assertSame(PHP_OS, $parts[1]);
        $this->assertSame(date('Y-m-d (D), e'), $parts[2]);
        $this->assertSame(gethostname() ?: '', $parts[3]);
        $this->assertSame(get_current_user(), $parts[4]);
        $this->assertSame(PHP_VERSION, $parts[5]);
    }

    public function testRenderReplacesExtendedRuntimePlaceholders(): void
    {
        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(
                prompt: '{{env.date_line}}|{{env.arch}}|{{env.home}}|{{env.shell}}|{{env.lang}}|{{env.locale}}',
            ),
        ]);

        $rendered = $this->systemPrompt($agent);
        $parts = explode('|', $rendered);

        $this->assertSame(date('Y-m-d (D), e'), $parts[0]);
        $this->assertSame(php_uname('m'), $parts[1]);
        $this->assertSame(getenv('HOME') ?: getenv('USERPROFILE') ?: '', $parts[2]);
        $this->assertSame(getenv('SHELL') ?: '', $parts[3]);
        $this->assertSame(getenv('LANG') ?: getenv('LC_ALL') ?: '', $parts[4]);
        $this->assertNotSame('en_US_POSIX', $parts[5]);
    }

    public function testRendererVarsOverrideEnvLocale(): void
    {
        $env = $this->container->make(EnvironmentVariables::class, ['localeTag' => 'zh-CN']);
        $renderer = $this->container->make(PromptRenderer::class);
        $renderer->registerVariables($env);

        $this->assertSame('zh-CN', $renderer->render($this->createStub(AgentInterface::class), '{{ env.locale }}'));
    }

    public function testRenderReplacesDateLinePlaceholder(): void
    {
        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(
                prompt: '{{env.date_line}}',
            ),
        ]);

        $expected = date('Y-m-d (D), e');
        $this->assertSame($expected, $this->systemPrompt($agent));
    }

    public function testJsonExamplesUnchanged(): void
    {
        $prompt = 'Example: {"name": "value", "items": [1, 2]}';
        $rendered = $this->systemPrompt($this->make(TestAgent::class, [
            'profile' => $this->testProfile(prompt: $prompt),
        ]));

        $this->assertSame($prompt, $rendered);
    }

    public function testRendererVarsUsedWhenNoAgentOverride(): void
    {
        $this->container->get(PromptRendererInterface::class)->registerVariables(new class implements PromptVariablesInterface {
            public function variables(AgentInterface $agent): array
            {
                unset($agent);

                return [
                    'app.name' => 'Global',
                    'env.date_line' => 'fixed-date-line',
                ];
            }
        });

        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(prompt: 'app={{app.name}} date={{env.date_line}}'),
        ]);

        $this->assertSame('app=Global date=fixed-date-line', $this->systemPrompt($agent));
    }

    public function testUnsetProjectPlaceholderStaysLiteral(): void
    {
        $rendered = $this->systemPrompt($this->make(TestAgent::class, [
            'profile' => $this->testProfile(prompt: 'rules={{project.plan}} json={"ok":true}'),
        ]));

        $this->assertSame('rules={{project.plan}} json={"ok":true}', $rendered);
    }

    public function testTaskAgentRendersAssignmentsWhenSegmentHung(): void
    {
        $parent = $this->make(Agent::class, []);
        $task = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $parent,
            'profile' => $this->testProfile(id: 'lite', prompt: $this->segmentPrompt('assignment')),
        ]);

        $rendered = $this->systemPrompt($task);
        $this->assertStringContainsString('## Assignments', $rendered);
        $this->assertStringContainsString('agent_*', $rendered);
        $this->assertStringNotContainsString('| `lite` |', $rendered);
    }

    public function testRenderAssignmentSegmentStaysStatic(): void
    {
        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(
                tools: ['task_deliver'],
                prompt: $this->segmentPrompt('assignment') . "\n\nunknown={{missing}}",
            ),
        ]);

        $rendered = $this->systemPrompt($agent);

        $this->assertStringContainsString('Every `agent_*` call is an intelligent tool', $rendered);
        $this->assertStringContainsString('Message', $rendered);
        $this->assertSame(1, substr_count($rendered, '### Message'));
        $this->assertStringContainsString('## Assignments', $rendered);
        $this->assertStringNotContainsString('### Configured profiles', $rendered);
        $this->assertStringNotContainsString('| Profile | Description | Max steps |', $rendered);
        $this->assertStringNotContainsString('## Delegation', $rendered);
        $this->assertStringNotContainsString('Routing rules', $rendered);
        $this->assertStringContainsString('unknown={{missing}}', $rendered);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \([A-Za-z]{3}\), /', $this->systemPrompt(
            $this->make(TestAgent::class, [
                'profile' => $this->testProfile(prompt: '{{env.date_line}}'),
            ]),
        ));
    }

    public function testOrchestratorRoleAndEnvAlwaysIncludeCoordinationCopy(): void
    {
        $rendered = $this->systemPrompt($this->make(TestAgent::class, [
            'profile' => $this->testProfile(
                prompt: "You are the orchestrator.\n\nYou coordinate a profile-based agent system.\n\n"
                    . implode("\n\n", [
                        $this->segmentPrompt('response'),
                        $this->segmentPrompt('assignment'),
                        $this->segmentPrompt('workspace'),
                    ]),
            ),
        ]));
        $this->assertStringContainsString('You are the orchestrator.', $rendered);
        $this->assertStringContainsString('You coordinate a profile-based agent system.', $rendered);
        $this->assertStringContainsString('Claimed facts need an identifiable source', $rendered);
        $this->assertStringContainsString('one coherent final answer', $rendered);
        $this->assertStringContainsString('Workspace:', $rendered);
    }

    public function testSignalPromptDefinesGenericEnvelope(): void
    {
        $rendered = $this->systemPrompt($this->make(TestAgent::class, [
            'profile' => $this->testProfile(prompt: $this->segmentPrompt('signal')),
        ]));

        $this->assertStringContainsString('Runtime signals', $rendered);
        $this->assertStringContainsString('[signal:<name>]', $rendered);
        $this->assertStringContainsString('`key: value`', $rendered);
        $this->assertStringContainsString('Body — optional text', $rendered);
        $this->assertStringContainsString('`<name>` identifies the signal', $rendered);
        $this->assertStringNotContainsString('task_deliver', $rendered);
        $this->assertStringNotContainsString('task_finish', $rendered);
        $this->assertStringNotContainsString('form_ask', $rendered);
    }

    public function testFormAskPromptDefinesReplyOutcomes(): void
    {
        $rendered = $this->systemPrompt($this->make(TestAgent::class, [
            'profile' => $this->testProfile(
                tools: ['form_ask'],
                prompt: $this->segmentPrompt('form-ask'),
            ),
        ]));

        $this->assertStringContainsString('ToolReplyInterrupt', $rendered);
        $this->assertStringContainsString('"outcome":"answered"', $rendered);
        $this->assertStringContainsString('"outcome":"skipped"', $rendered);
        $this->assertStringContainsString('"outcome":"cancelled"', $rendered);
        $this->assertStringContainsString('| Cancelled |', $rendered);
    }

    public function testDispatchPromptFilesAreRendered(): void
    {
        $parent = $this->make(Agent::class, [
            'profile' => $this->testProfile(
                prompt: 'host' . "\n\n" . implode("\n\n", [
                    $this->segmentPrompt('assignment'),
                    $this->segmentPrompt('task-deliver'),
                ]),
                tools: ['task_deliver'],
            ),
        ]);
        $child = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $parent,
        ]);

        $hostPrompt = $this->systemPrompt($parent);
        $childPrompt = $this->systemPrompt($child);

        $this->assertStringContainsString('Assignments', $hostPrompt);
        $this->assertStringNotContainsString('Task run', $hostPrompt);
        $this->assertStringContainsString('Task delivery', $hostPrompt);
        $this->assertStringContainsString('`as_reply: true`', $hostPrompt);
        $this->assertStringContainsString('After `task_deliver`, do not poll', $hostPrompt);
        $this->assertStringContainsString('[signal:task_finish]', $hostPrompt);
        $this->assertStringNotContainsString('to: parent', $hostPrompt);
        $this->assertStringNotContainsString('Sub-agent role', $hostPrompt);

        $this->assertStringNotContainsString('Task delivery', $childPrompt);
        $this->assertStringNotContainsString('Sub-agent role', $childPrompt);
        $this->assertStringNotContainsString('Finish with `task_finish` only', $childPrompt);
        $this->assertStringContainsString('Signals', $childPrompt);
    }

    public function testSwitchProfileDropsCharterCache(): void
    {
        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(id: 'host', prompt: 'host body'),
        ]);

        $first = $agent->charter();
        $agent->switchProfile($this->testProfile(id: 'host', prompt: 'same id'));
        $this->assertSame($first, $agent->charter());

        $agent->switchProfile($this->testProfile(id: 'other', prompt: 'other body'));
        $second = $agent->charter();

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('other body', $second->prompt);
    }
}
