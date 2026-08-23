<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Tests\AgentContainerTestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Agency\Tests\Unit\Fixtures\StubLlmProviders;
use Switon\Agency\Titler;
use Switon\Agency\TitlerInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\PathAliasInterface;
use Switon\Llm\LlmProvidersInterface;

#[CoversClass(Titler::class)]
class TitlerTest extends AgentContainerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $alias = $this->container->get(PathAliasInterface::class);
        if (!$alias->has('@agency')) {
            $alias->set('@agency', dirname(__DIR__, 2) . '/resources');
        }
    }

    protected function bindStubLlm(StubLlm $stub): StubLlmProviders
    {
        $providers = new class ($stub) extends StubLlmProviders {
            public string $requested = '';

            public function get(string $model): LlmInterface
            {
                $this->requested = $model;

                return parent::get($model);
            }
        };
        $this->container->set(LlmInterface::class, $stub);
        // The parent test case already replaced this interface; `set()` cannot touch a resolved one.
        $this->container->replace(LlmProvidersInterface::class, $providers);

        return $providers;
    }

    /** @param array<string, mixed> $properties */
    protected function titler(array $properties = []): TitlerInterface
    {
        return $this->make(TitlerInterface::class, $properties);
    }

    public function testTitleReturnsTrimmedModelOutput(): void
    {
        $stub = new class () extends StubLlm {
            public ?Thread $lastThread = null;

            public function complete(Thread $thread, Charter $charter): Completion
            {
                $this->lastThread = $thread;

                return parent::complete($thread, $charter);
            }
        };
        $stub->titleResult = '  修复登录 404  ';
        $providers = $this->bindStubLlm($stub);

        $thread = new Thread('chat-1', turns: [Turn::of('t1', [
            Completion::of(UserMessage::of('用户发来很长的问题关于登录页面404错误')),
        ], TurnStatus::Completed),
        ]);

        $title = $this->titler()->title($thread);

        $this->assertSame('修复登录 404', $title);
        $this->assertSame(1, $stub->completeCalls);
        $this->assertSame('fast', $providers->requested);
        $this->assertSame('fast', $stub->lastThread?->turns[0]->model);
    }

    public function testTitleReturnsEmptyForEmptyConversation(): void
    {
        $stub = new StubLlm();
        $this->bindStubLlm($stub);

        $title = $this->titler()->title(new Thread('chat-1'));

        $this->assertSame('', $title);
        $this->assertSame(0, $stub->completeCalls);
    }

    public function testTitlePassesUserMessagesAndTitlerPromptToLlm(): void
    {
        $stub = new StubLlm();
        $stub->titleResult = 'Explore codebase';
        $this->bindStubLlm($stub);

        $thread = new Thread('chat-1', turns: [Turn::of('t1', [
            Completion::of(UserMessage::of('Map the repo layout'), new AssistantMessage('On it')),
        ], TurnStatus::Completed),
        ]);

        $this->titler()->title($thread);

        $wire = $stub->requests[0];
        $this->assertInstanceOf(SystemMessage::class, $wire[0]);
        $this->assertStringContainsString('conversation titler', (string) $wire[0]->content);
        $this->assertStringContainsString('Title the conversation below', $wire[1]->wire()[0]['text']);
        $this->assertStringContainsString('Map the repo layout', $wire[1]->wire()[0]['text']);
        $this->assertCount(2, $wire);
    }

    public function testTitleCapsPromptsAtMax(): void
    {
        $stub = new StubLlm();
        $stub->titleResult = 'Long thread';
        $this->bindStubLlm($stub);

        $turns = [];
        for ($i = 1; $i <= 5; $i++) {
            $turns[] = Turn::of('t' . $i, [
                Completion::of(UserMessage::of('user-' . $i), new AssistantMessage('assistant-' . $i)),
            ], TurnStatus::Completed);
        }

        $this->titler(['max' => 3])->title(new Thread('chat-1', turns: $turns));

        $wire = $stub->requests[0];
        $combined = $wire[1]->wire()[0]['text'];
        $this->assertStringContainsString('user-1', $combined);
        $this->assertStringContainsString('user-2', $combined);
        $this->assertStringContainsString('user-3', $combined);
        $this->assertStringNotContainsString('user-4', $combined);
        $this->assertCount(2, $wire);
    }

    public function testModelPropertyIsUsedForProviderLookup(): void
    {
        $stub = new class () extends StubLlm {
            public ?Thread $lastThread = null;

            public function complete(Thread $thread, Charter $charter): Completion
            {
                $this->lastThread = $thread;

                return parent::complete($thread, $charter);
            }
        };
        $stub->titleResult = 'Custom model title';
        $providers = $this->bindStubLlm($stub);

        $thread = new Thread('chat-1', turns: [Turn::of('t1', [
            Completion::of(UserMessage::of('Long enough opening message for sidebar title generation')),
        ], TurnStatus::Completed),
        ]);

        $title = $this->titler(['model' => 'glm-flash'])->title($thread);

        $this->assertSame('Custom model title', $title);
        $this->assertSame(1, $stub->completeCalls);
        $this->assertSame('glm-flash', $providers->requested);
        $this->assertSame('glm-flash', $stub->lastThread?->turns[0]->model);
    }

    public function testTitleSkipsNonUserAndBlankAsks(): void
    {
        $stub = new StubLlm();
        $stub->titleResult = 'Kept';
        $this->bindStubLlm($stub);

        $thread = new Thread('chat-1', turns: [Turn::of('t1', [
            Completion::of(new AssistantMessage('not an ask')),
            Completion::of(UserMessage::of('')),
            Completion::of(UserMessage::of('real ask')),
        ], TurnStatus::Completed),
        ]);

        $this->assertSame('Kept', $this->titler()->title($thread));
        $combined = $stub->requests[0][1]->wire()[0]['text'];
        $this->assertStringContainsString('real ask', $combined);
        $this->assertStringNotContainsString('not an ask', $combined);
    }
}
