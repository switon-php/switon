<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Eval;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Agency\Tests\Eval\ScenarioEvaluator;
use Switon\Agency\Tests\Eval\Scenarios\SkillsScenarios;
use Switon\Agency\Tests\Eval\SkillsEvalRunner;
use Switon\Agency\Tests\Eval\TranscriptInspector;
use Switon\Agency\Tests\AgentContainerTestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;

#[CoversClass(TranscriptInspector::class)]
#[CoversClass(ScenarioEvaluator::class)]
class SkillsEvalStubTest extends AgentContainerTestCase
{
    public function testTranscriptInspectorReadsToolResults(): void
    {
        $thread = new Thread('eval', turns: [Turn::of('t0', [
                Completion::of(new ToolMessage([new ToolResult('{"content":"secret"}', 'c1', 'read')])),
                Completion::of(UserMessage::of('done'), new AssistantMessage('secret')),
            ], TurnStatus::Completed),
        ]);

        $this->assertTrue(TranscriptInspector::calledTool($thread, 'read'));
        $this->assertSame('secret', TranscriptInspector::lastAssistantText($thread));
    }

    public function testStubSkillChainScenarioPasses(): void
    {
        if (!interface_exists(\Switon\Skill\SkillsInterface::class)) {
            $this->markTestSkipped('switon/skill is a separate package');
        }

        $skillsRoot = $this->writeStubSkill('eval-stub', 'EVAL-STUB-991');

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('skill_load', ['id' => 'eval-stub']);
        $stub->responses[] = StubLlm::assistantWithToolCall('read', [
            'path' => $skillsRoot . '/eval-stub/references/secret.txt',
        ]);
        $stub->responses[] = StubLlm::assistant('EVAL-STUB-991');
        $this->container->set(LlmInterface::class, $stub);

        $scenario = SkillsScenarios::stubChain()[0];
        $scenario['skills_root'] = $skillsRoot;

        $result = (new SkillsEvalRunner($this->container))->run($scenario);

        $this->assertTrue($result->passed, implode('; ', $result->failures));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function stubExpectationProvider(): iterable
    {
        yield 'missing skill_load' => [[
            'id' => 'negative',
            'expect' => ['skill_load' => true],
        ]];
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('stubExpectationProvider')]
    public function testEvaluatorDetectsMissingTools(array $scenario): void
    {
        $thread = new Thread('eval', turns: [Turn::of('t', completions: [
                Completion::of(UserMessage::of(''), new AssistantMessage('no tools')),
            ], status: TurnStatus::Completed),
        ]);

        $result = ScenarioEvaluator::evaluateSkills($thread, $scenario);

        $this->assertFalse($result->passed);
        $this->assertNotSame([], $result->failures);
    }

    protected function writeStubSkill(string $name, string $secret): string
    {
        $root = sys_get_temp_dir() . '/switon-agent-eval-' . uniqid('', true);
        $dir = $root . '/' . $name;
        mkdir($dir . '/references', 0777, true);
        file_put_contents($dir . '/SKILL.md', <<<MD
---
id: {$name}
description: Eval stub skill for CI harness.
---

# Eval stub

1. Note the `Skill root:` path at the top of this message.
2. Read `references/secret.txt` with `read`.
3. Reply with only the secret code.

MD);
        file_put_contents($dir . '/references/secret.txt', $secret . "\n");

        return $root;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/switon-agent-eval-*') ?: [] as $dir) {
            $this->removeDirectory($dir);
        }

        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
