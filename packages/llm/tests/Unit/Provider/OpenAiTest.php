<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\Message;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\AskPart;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\Thread;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\ClockInterface;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Llm\AbstractLlm;
use Switon\Llm\Event\CompletionDelta;
use Switon\Llm\Event\CompletionFailed;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Event\CompletionResponded;
use Switon\Llm\Exception\InvalidResponseException;
use Switon\Llm\Exception\InvalidToolCallException;
use Switon\Llm\Exception\ProviderErrorException;
use Switon\Llm\Http\HttpResponse;
use Switon\Llm\OpenAi;
use Switon\Llm\Tests\TestCase;
use Switon\Llm\Tests\Unit\Fixtures\StubHttpClient;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\MockClock;

#[CoversClass(OpenAi::class)]
class OpenAiTest extends TestCase
{
    public function testCompleteDispatchesLifecycleEvents(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient);
        $dispatcher = $this->eventDispatcher();
        $this->assertSame('https://api.openai.com/v1', $chatCompletions->baseUrl());
        $this->assertSame(128_000, $chatCompletions->window());

        $completion = $chatCompletions->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('Hello', $completion->output->content);

        $requestingEvents = $dispatcher->getEventsByClass(CompletionRequesting::class);
        $this->assertCount(1, $requestingEvents);
        $this->assertFalse($requestingEvents[0]->stream);
        $this->assertInstanceOf(UserMessage::class, $requestingEvents[0]->completion->input);
        $this->assertSame('Hi', $requestingEvents[0]->completion->input->wire()[0]['text']);

        $respondedEvents = $dispatcher->getEventsByClass(CompletionResponded::class);
        $this->assertCount(1, $respondedEvents);
        $this->assertSame('Hello', $respondedEvents[0]->completion->output->content);
        $this->assertSame('Hi', $respondedEvents[0]->completion->input->wire()[0]['text']);
    }

    public function testCompletePreservesCatalogModelOnCompletion(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient, ['model' => 'gpt-test']);
        $completion = Completion::of(UserMessage::of('Hi'));
        $thread = new Thread('test');
        $thread->turns[] = \Switon\Ai\Turn::of($completion->completeId, status: TurnStatus::Running, model: 'main');
        $chatCompletions->complete($this->hang($thread, $completion), Charter::of());

        $this->assertSame('main', $thread->lastTurn()?->model ?? '');
    }

    public function testCompleteReturnsMutatedResponseFromCompletionRespondedEvent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient);

        $this->container->get(ListenerProviderInterface::class)->on(
            null,
            static function (CompletionResponded $event): void {
                $event->completion->output->content = 'mutated';
            },
        );

        $this->assertSame('mutated', $chatCompletions->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of())->output->content);
    }

    public function testCompletePostsChatCompletionsRequest(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient, [
            'baseUrl' => 'https://api.example.test/v1',
            'apiKey' => 'secret',
            'model' => 'gpt-test',
            'extra' => ['temperature' => 0.2],
        ]);

        $chatCompletions->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Ping'))), Charter::of());

        $this->assertCount(1, $httpClient->posts);
        $this->assertSame('https://api.example.test/v1/chat/completions', $httpClient->posts[0]['url']);
        $this->assertSame('Bearer secret', $httpClient->posts[0]['headers']['Authorization']);

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('gpt-test', $body['model']);
        $this->assertFalse($body['stream']);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertSame('Ping', $body['messages'][0]['content']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('tool_choice', $body);
        $this->assertArrayNotHasKey('top_p', $body);
        $this->assertArrayNotHasKey('stop', $body);
        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertArrayNotHasKey('response_format', $body);
    }

    public function testCompletePostsOptionalSamplingFields(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient, [
            'extra' => [
                'max_tokens' => 2048,
                'top_p' => 0.9,
                'stop' => ['END', 'STOP'],
                'reasoning_effort' => 'medium',
                'response_format' => ['type' => 'json_object'],
            ],
        ])->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame(2048, $body['max_tokens']);
        $this->assertSame(0.9, $body['top_p']);
        $this->assertSame(['END', 'STOP'], $body['stop']);
        $this->assertSame('medium', $body['reasoning_effort']);
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
    }

    public function testCharterEffortPostsReasoningEffort(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient, ['model' => 'gpt-test'])->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(effort: 'high'),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('high', $body['reasoning_effort']);
    }

    public function testCharterEffortWinsOverCatalogExtra(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient, [
            'model' => 'gpt-test',
            'extra' => ['reasoning_effort' => 'low'],
        ])->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(effort: 'high'),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('high', $body['reasoning_effort']);
    }

    public function testAbsentCharterEffortKeepsCatalogExtraLevel(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient, [
            'model' => 'gpt-test',
            'extra' => ['reasoning_effort' => 'low'],
        ])->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('low', $body['reasoning_effort']);
    }

    public function testExtraDoesNotOverrideFrameworkKeys(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient, [
            'model' => 'gpt-from-config',
            'extra' => [
                'model' => 'should-be-ignored',
                'messages' => [['role' => 'user', 'content' => 'hijack']],
                'stream' => true,
                'temperature' => 0.1,
            ],
        ])->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Ping'))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('gpt-from-config', $body['model']);
        $this->assertFalse($body['stream']);
        $this->assertSame('Ping', $body['messages'][0]['content']);
        $this->assertSame(0.1, $body['temperature']);
    }

    public function testCompleteEncodesUserImagePartsFromDatadirRef(): void
    {
        $root = sys_get_temp_dir() . '/switon-user-asset-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid/attachments', 0777, true);
        file_put_contents($root . '/tid/attachments/a.png', 'PNG');
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'chatcmpl-1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
            ], JSON_THROW_ON_ERROR);

            $this->makeOpenAi($httpClient)->complete(
                $this->hang(Thread::of('tid', '@threads/tid'), Completion::of(UserMessage::of('', [
                    AskPart::of('see chart'),
                    MediaPart::of('attachments/a.png', mime: 'image/png'),
                ]))),
                Charter::of(),
            );

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'see chart'],
                [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('PNG')],
                ],
            ], $body['messages'][0]['content']);
        } finally {
            @unlink($root . '/tid/attachments/a.png');
            @rmdir($root . '/tid/attachments');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteEncodesToolResultImageFromDatadir(): void
    {
        $root = sys_get_temp_dir() . '/switon-tool-asset-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid/tool-asset', 0777, true);
        file_put_contents($root . '/tid/tool-asset/shot.png', 'PNG');
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'chatcmpl-1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
            ], JSON_THROW_ON_ERROR);

            $thread = Thread::of('tid', '@threads/tid');
            $this->makeOpenAi($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([
                new ToolResult('shot ok', 'call_1', 'browser_take_screenshot', parts: [
                    MediaPart::of('tool-asset/shot.png', mime: 'image/png'),
                ]),
            ]))), Charter::of());

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'shot ok'],
                [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('PNG')],
                ],
            ], $body['messages'][0]['content']);
        } finally {
            @unlink($root . '/tid/tool-asset/shot.png');
            @rmdir($root . '/tid/tool-asset');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteEncodesToolResultImageFromAbsoluteRef(): void
    {
        $file = sys_get_temp_dir() . '/switon-abs-image-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($file, 'PNG');

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'chatcmpl-1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
            ], JSON_THROW_ON_ERROR);

            $thread = Thread::of('tid', '@threads/tid');
            $this->makeOpenAi($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([
                new ToolResult('shot ok', 'call_1', 'browser_take_screenshot', parts: [
                    MediaPart::of($file, mime: 'image/png'),
                ]),
            ]))), Charter::of());

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'shot ok'],
                [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('PNG')],
                ],
            ], $body['messages'][0]['content']);
        } finally {
            @unlink($file);
        }
    }

    public function testCompleteRejectsToolResultImageWithDotDotEscape(): void
    {
        $root = sys_get_temp_dir() . '/switon-tool-asset-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid', 0777, true);
        file_put_contents($root . '/secret.png', 'PNG');
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'chatcmpl-1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
            ], JSON_THROW_ON_ERROR);

            $ref = '../secret.png';
            $thread = Thread::of('tid', '@threads/tid');
            $this->makeOpenAi($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([
                new ToolResult('shot ok', 'call_1', 'browser_take_screenshot', parts: [
                    MediaPart::of($ref, mime: 'image/png'),
                ]),
            ]))), Charter::of());

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'shot ok'],
                ['type' => 'text', 'text' => AbstractLlm::UNLOADED_MEDIA_NOTICE . ' ref: ' . $ref],
            ], $body['messages'][0]['content']);
        } finally {
            @unlink($root . '/secret.png');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteUnloadsHistoricalToolResultImageOnWire(): void
    {
        $root = sys_get_temp_dir() . '/switon-tool-asset-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid/tool-asset', 0777, true);
        $ref = 'tool-asset/call_1-1.png';
        file_put_contents($root . '/tid/' . $ref, 'PNG');
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'chatcmpl-1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
            ], JSON_THROW_ON_ERROR);

            $toolResult = new ToolResult('shot ok', 'call_1', 'browser_take_screenshot', parts: [
                MediaPart::of($ref, mime: 'image/png'),
            ]);
            $thread = new Thread('tid', turns: [Turn::of('t', completions: [
                    Completion::of(UserMessage::of('Look'), new AssistantMessage('', [
                        new \Switon\Ai\ToolCall('call_1', 'browser_take_screenshot', []),
                    ])),
                    Completion::of(
                        ToolMessage::of([$toolResult]),
                        new AssistantMessage('seen'),
                    ),
                ], status: TurnStatus::Completed),
            ], datadir: '@threads/tid');

            $this->makeOpenAi($httpClient)->complete(
                $this->hang($thread, Completion::of(UserMessage::of('again'))),
                Charter::of(),
            );

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'shot ok'],
                ['type' => 'text', 'text' => AbstractLlm::UNLOADED_MEDIA_NOTICE . ' ref: ' . $ref],
            ], $body['messages'][2]['content']);
            $this->assertCount(1, $toolResult->parts);
            $this->assertInstanceOf(MediaPart::class, $toolResult->parts[0]);
        } finally {
            @unlink($root . '/tid/' . $ref);
            @rmdir($root . '/tid/tool-asset');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteEncodesRemoteImageUrl(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('', [
                MediaPart::of('https://cdn.example.com/a.png', mime: 'image/png'),
            ]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'https://cdn.example.com/a.png',
                ],
            ],
        ], $body['messages'][0]['content']);
    }

    public function testCompletePassesMediaExtraOntoImageUrl(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('', [
                MediaPart::of('https://cdn.example.com/a.png', mime: 'image/png', extra: ['detail' => 'low']),
            ]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'https://cdn.example.com/a.png',
                    'detail' => 'low',
                ],
            ],
        ], $body['messages'][0]['content']);
    }

    public function testCompleteEncodesRemoteVideoUrl(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('describe', [
                MediaPart::of('https://cdn.example.com/a.mp4', mime: 'video/mp4', extra: ['fps' => 1.0]),
            ]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'describe'],
            [
                'type' => 'video_url',
                'video_url' => [
                    'url' => 'https://cdn.example.com/a.mp4',
                    'fps' => 1,
                ],
            ],
        ], $body['messages'][0]['content']);
    }

    public function testCompleteRejectsNonHttpImageSchemes(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $dataRef = 'data:image/png;base64,' . base64_encode('PNG');
        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('', [
                MediaPart::of($dataRef, 'image/png'),
                MediaPart::of('file:///tmp/a.png', 'image/png'),
            ]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $unloaded = AbstractLlm::UNLOADED_MEDIA_NOTICE;
        $this->assertSame([
            ['type' => 'text', 'text' => $unloaded . ' ref: ' . $dataRef],
            ['type' => 'text', 'text' => $unloaded . ' ref: file:///tmp/a.png'],
        ], $body['messages'][0]['content']);
    }

    public function testCompleteDoesNotSendMetaOnWire(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = Completion::of(SystemMessage::of('Earlier summary.'), new AssistantMessage('ack'));
        $completion->completeId = 'test-id';
        $completion->attributes = ['covers' => [1, 3]];
        $thread = new Thread('test', turns: [Turn::of('t', completions: [
                $completion,
            ], status: TurnStatus::Completed),
        ]);
        $this->makeOpenAi($httpClient)->complete($this->hang($thread, Completion::of(UserMessage::of('Continue'))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('Earlier summary.', $body['messages'][0]['content']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertArrayNotHasKey('meta', $body['messages'][0]);
        $this->assertArrayNotHasKey('attributes', $body['messages'][0]);
        $this->assertSame('Continue', $body['messages'][2]['content']);
    }

    public function testCompleteSendsEveryFoldedMessageBlock(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $folded = new FoldedMessage('user: earlier', [
            new PinnedPart('skill:live-code', 'The verification number is 44426633.'),
        ]);
        $thread = new Thread('test', turns: [Turn::of('t', completions: [
            Completion::of($folded, new AssistantMessage('ack')),
        ], status: TurnStatus::Completed)]);
        $this->makeOpenAi($httpClient)->complete(
            $this->hang($thread, Completion::of(UserMessage::of('Continue'))),
            Charter::of(),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'user: earlier'],
            ['type' => 'text', 'text' => 'The verification number is 44426633.'],
        ], $body['messages'][0]['content']);
        $this->assertArrayNotHasKey('id', $body['messages'][0]);
    }

    public function testCompletePostsToolChoiceWhenProvided(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $tools = ['get_weather' => new ToolSchema()];

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of(tools: $tools, toolChoice: 'none'), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('none', $body['tool_choice']);
    }

    public function testCompletePostsForcedFunctionToolChoice(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of(tools: ['get_weather' => new ToolSchema()], toolChoice: 'get_weather'), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            'type' => 'function',
            'function' => ['name' => 'get_weather'],
        ], $body['tool_choice']);
    }

    public function testCompletePostsToolsWhenProvided(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $tools = [
            'get_weather' => new ToolSchema(
                description: 'Get weather for a location.',
                properties: ['location' => ['type' => 'string']],
                required: ['location'],
            ),
        ];

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of(tools: $tools), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('get_weather', $body['tools'][0]['function']['name']);
        $this->assertSame('Get weather for a location.', $body['tools'][0]['function']['description']);
        $this->assertSame('object', $body['tools'][0]['function']['parameters']['type']);
        $this->assertSame(['location' => ['type' => 'string']], $body['tools'][0]['function']['parameters']['properties']);
        $this->assertSame(['location'], $body['tools'][0]['function']['parameters']['required']);
    }

    public function testCompleteReencodesAnthropicToolHistoryAsFunctionCalls(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $thread = new Thread('test', turns: [Turn::of('t', completions: [
                Completion::of(UserMessage::of('Run tools'), new AssistantMessage('', [
                    new \Switon\Ai\ToolCall(
                        'toolu_1',
                        'get_weather',
                        ['location' => 'SF'],
                        passback: ['type' => 'tool_use'],
                    ),
                ])),
            ], status: TurnStatus::Completed),
        ]);

        $this->makeOpenAi($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([new ToolResult('{"temp":72}', 'toolu_1', 'get_weather')]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('function', $body['messages'][1]['tool_calls'][0]['type']);
    }

    public function testCompleteEncodeToolsUsesSchemaName(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(tools: [
                'get_weather' => new ToolSchema(), ]), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('get_weather', $body['tools'][0]['function']['name']);
        $this->assertSame('object', $body['tools'][0]['function']['parameters']['type']);
        $this->assertSame([], $body['tools'][0]['function']['parameters']['properties']);
    }

    public function testCompleteEncodeToolsIncludesEmptyParametersForNoArgTools(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(tools: [
                'task_deliver' => new ToolSchema(description: 'Assign a sub-agent task.'), ]), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('task_deliver', $body['tools'][0]['function']['name']);
        $this->assertSame('object', $body['tools'][0]['function']['parameters']['type']);
        $this->assertSame([], $body['tools'][0]['function']['parameters']['properties']);
        $this->assertArrayNotHasKey('required', $body['tools'][0]['function']['parameters']);
    }

    public function testStreamPostsToolsWhenProvided(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of(tools: [
                'get_weather' => new ToolSchema(), ]), );

        $body = Json::parse($httpClient->streams[0]['body']);
        $this->assertSame('get_weather', $body['tools'][0]['function']['name']);
    }

    public function testCompletionRequestingToolsCanBeMutatedBeforeWireCall(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->container->get(\Switon\Eventing\ListenerProviderInterface::class)->on(
            null,
            static function (CompletionRequesting $event): void {
                $event->charter->tools = [
                    'added_in_listener' => new ToolSchema(),
                ];
            },
        );

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('added_in_listener', $body['tools'][0]['function']['name']);
    }

    public function testCompletionRequestingInputCanBeMutatedBeforeWireCall(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->container->get(\Switon\Eventing\ListenerProviderInterface::class)->on(
            null,
            static function (CompletionRequesting $event): void {
                $event->charter->prompt = 'Mutated system.';
                $event->completion->input = UserMessage::of('Mutated input.');
            },
        );

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Original input.'))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('Mutated system.', $body['messages'][0]['content']);
        $this->assertSame('Mutated input.', $body['messages'][1]['content']);
        $this->assertCount(2, $body['messages']);

        $respondedEvents = $this->eventDispatcher()->getEventsByClass(CompletionResponded::class);
        $this->assertSame('Mutated input.', $respondedEvents[0]->completion->input->wire()[0]['text']);
    }

    public function testCompleteUsesDefaultModelWhenRequestModelEmpty(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient, ['model' => 'gpt-default']);
        $chatCompletions->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('gpt-default', $body['model']);
    }

    public function testStreamAggregatesDeltaContent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'Hel'],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'lo'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('Hello', $completion->output?->content);
        $this->assertSame('stop', $completion->finishReason);
    }

    public function testStreamStopsAfterProviderFinishReason(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-early-stop',
                'choices' => [[
                    'delta' => ['content' => 'Hi'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-early-stop',
                'choices' => [[
                    'delta' => ['content' => ' ignored'],
                    'finish_reason' => null,
                ]],
            ]),
            'data: [DONE]',
        ];

        $chunks = [];
        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), static function (\Switon\Ai\StreamDelta $streamDelta) use (&$chunks): void {
            $chunks[] = $streamDelta->content;
        }, );

        $this->assertSame(['Hi'], $chunks);
        $this->assertSame('Hi', $completion->output?->content);
        $this->assertSame('stop', $completion->finishReason);
    }

    public function testStreamAcceptsArrayCallableOnDelta(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-callable',
                'choices' => [[
                    'delta' => ['content' => 'Ok'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $receiver = new class () {
            /** @var list<string> */
            public array $chunks = [];

            public function onDelta(\Switon\Ai\StreamDelta $streamDelta): void
            {
                $this->chunks[] = $streamDelta->content;
            }
        };

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), [$receiver, 'onDelta'], );

        $this->assertSame(['Ok'], $receiver->chunks);
        $this->assertSame('Ok', $completion->output?->content);
    }

    public function testStreamWithNoChunksRaises(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = ['data: [DONE]'];

        $this->expectException(\Switon\Llm\Exception\InvalidResponseException::class);
        $this->expectExceptionMessage('LLM stream ended without an assistant response.');

        $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());
    }

    public function testStreamDeltaCarriesCompletionInput(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'Hi'],
                    'finish_reason' => null,
                ]],
            ]),
            'data: [DONE]',
        ];

        $dispatcher = $this->eventDispatcher();
        $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $events = $dispatcher->getEventsByClass(CompletionDelta::class);
        $this->assertCount(1, $events);
        $this->assertSame('Hi', $events[0]->completion->input->wire()[0]['text']);
        $this->assertSame(0, $events[0]->index);
    }

    public function testStreamLeavesUsageNullWhenApiOmitsUsage(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'Hello'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertNull($completion->usage);
    }

    public function testStreamRequestIncludesUsageOptions(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'Hi'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertCount(1, $httpClient->streams);
        $payload = Json::parse($httpClient->streams[0]['body']);
        $this->assertTrue($payload['stream']);
        $this->assertSame(['include_usage' => true], $payload['stream_options']);
    }

    public function testStreamParsesUsageDetailsFromFinalChunk(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'Hi'],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => [],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 150,
                    'total_tokens' => 250,
                    'completion_tokens_details' => ['reasoning_tokens' => 110],
                    'prompt_tokens_details' => ['cached_tokens' => 50],
                ],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), );

        $this->assertNotNull($completion->usage);
        $this->assertSame(100, $completion->usage->input);
        $this->assertSame(150, $completion->usage->output);
        $this->assertSame(250, $completion->usage->total());
        $this->assertSame(110, $completion->usage->reasoning);
        $this->assertSame(50, $completion->usage->uncached());
        $this->assertSame(50, $completion->usage->cacheRead);
        $this->assertSame(0, $completion->usage->cacheWrite);
    }

    public function testCompleteParsesReasoningAndCachedTokenDetails(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-usage',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Answer'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 150,
                'total_tokens' => 250,
                'completion_tokens_details' => [
                    'reasoning_tokens' => 110,
                    'accepted_prediction_tokens' => 0,
                    'rejected_prediction_tokens' => 0,
                ],
                'prompt_tokens_details' => ['cached_tokens' => 50],
            ],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), );

        $this->assertNotNull($completion->usage);
        $this->assertSame(100, $completion->usage->input);
        $this->assertSame(150, $completion->usage->output);
        $this->assertSame(250, $completion->usage->total());
        $this->assertSame(110, $completion->usage->reasoning);
        $this->assertSame(50, $completion->usage->uncached());
        $this->assertSame(50, $completion->usage->cacheRead);
        $this->assertSame(0, $completion->usage->cacheWrite);
    }

    public function testCompleteDerivesInputWhenCacheWritePresent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-cache-write',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Answer'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 2600,
                'completion_tokens' => 10,
                'total_tokens' => 2610,
                'prompt_tokens_details' => [
                    'cached_tokens' => 2000,
                    'cache_write_tokens' => 400,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), );

        $this->assertNotNull($completion->usage);
        $this->assertSame(2600, $completion->usage->input);
        $this->assertSame(200, $completion->usage->uncached());
        $this->assertSame(2000, $completion->usage->cacheRead);
        $this->assertSame(400, $completion->usage->cacheWrite);
    }

    public function testCompleteLeavesCacheZeroWithoutCacheBreakdown(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-no-cache',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Answer'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 10,
                'total_tokens' => 110,
            ],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), );

        $this->assertNotNull($completion->usage);
        $this->assertSame(100, $completion->usage->input);
        $this->assertSame(100, $completion->usage->uncached());
        $this->assertSame(0, $completion->usage->cacheRead);
        $this->assertSame(0, $completion->usage->cacheWrite);
    }

    public function testCompleteExposesToolCallsAndExtraOnResponse(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-tools',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Checking weather.',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"SF"}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('Checking weather.', $completion->output->content);
        $this->assertNull($completion->output->reasoning);
        $this->assertCount(1, $completion->output->toolCalls);
        $this->assertSame('call_1', $completion->output->toolCalls[0]->id);
        $this->assertSame([], $completion->output->passback);
        $this->assertNotNull($completion->usage);
    }

    public function testCompleteParsesReasoningContentField(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Answer.',
                    'reasoning_content' => 'Think first.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('Answer.', $completion->output->content);
        $this->assertSame('Think first.', $completion->output->reasoning);
    }

    public function testCompletePrefersReasoningContentOverReasoning(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Answer.',
                    'reasoning_content' => 'From content field.',
                    'reasoning' => 'From reasoning field.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('From content field.', $completion->output->reasoning);
    }

    public function testCompleteParsesLegacyReasoningField(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Answer.',
                    'reasoning' => 'OpenRouter style.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('OpenRouter style.', $completion->output->reasoning);
    }

    public function testCompletePreservesEmptyReasoningContent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'reasoning_content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'echo', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('', $completion->output->reasoning);
    }

    public function testCompleteLeavesReasoningNullWhenAbsent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-plain',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hi.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertNull($completion->output->reasoning);
    }

    public function testCompleteJoinsMultipartAssistantContentText(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-parts',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello '],
                        ['type' => 'text', 'text' => 'world'],
                        ['type' => 'refusal', 'refusal' => 'ignored-here'],
                    ],
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('Hello world', $completion->output->content);
    }

    public function testStreamJoinsMultipartAssistantContentText(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-parts',
                'choices' => [[
                    'delta' => [
                        'content' => [
                            ['type' => 'text', 'text' => 'A'],
                            ['type' => 'text', 'text' => 'B'],
                        ],
                    ],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('AB', $completion->output->content);
    }

    public function testCompleteLandsRefusalAsRefusalPart(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-refuse',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'refusal' => 'I cannot help with that.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertNull($completion->output->content);
        $this->assertInstanceOf(RefusalPart::class, $completion->output->parts[0]);
        $this->assertSame('I cannot help with that.', $completion->output->parts[0]->refusal);
    }

    public function testStreamAggregatesReasoningContentDelta(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-reason',
                'choices' => [[
                    'delta' => ['reasoning_content' => 'Th', 'content' => ''],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-reason',
                'choices' => [[
                    'delta' => ['reasoning_content' => 'ink', 'content' => 'Hi'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $dispatcher = $this->eventDispatcher();
        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('Think', $completion->output->reasoning);
        $this->assertSame('Hi', $completion->output->content);

        $events = $dispatcher->getEventsByClass(CompletionDelta::class);
        $this->assertSame('Th', $events[0]->streamDelta->reasoning);
        $this->assertSame('', $events[0]->streamDelta->content);
        $this->assertSame('ink', $events[1]->streamDelta->reasoning);
        $this->assertSame('Hi', $events[1]->streamDelta->content);
    }

    public function testStreamIgnoresEmptyFinishReasonUntilStop(): void
    {
        // kimitk / some OpenAI-compat relays send finish_reason:"" on every mid chunk.
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-empty-fr',
                'choices' => [[
                    'delta' => ['role' => 'assistant', 'reasoning_content' => 'think'],
                    'finish_reason' => '',
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-empty-fr',
                'choices' => [[
                    'delta' => ['role' => 'assistant', 'content' => '1+1=2'],
                    'finish_reason' => '',
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-empty-fr',
                'choices' => [[
                    'delta' => ['role' => 'assistant'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), );

        $this->assertSame('think', $completion->output?->reasoning);
        $this->assertSame('1+1=2', $completion->output?->content);
        $this->assertSame('stop', $completion->finishReason);
        $this->assertSame(3, $completion->usage?->total());
    }

    public function testStreamLeavesReasoningNullWhenNeverPresent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-plain',
                'choices' => [[
                    'delta' => ['content' => 'Hi'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('Hi', $completion->output->content);
        $this->assertNull($completion->output->reasoning);
    }

    public function testStreamPreservesEmptyReasoningContent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-reason',
                'choices' => [[
                    'delta' => ['reasoning_content' => ''],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-reason',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'echo', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('', $completion->output->reasoning);
        $this->assertCount(1, $completion->output->toolCalls);
    }

    public function testStreamRecordsReasoningElapsedUntilContent(): void
    {
        $clock = new MockClock(100.0);
        $this->container->replace(ClockInterface::class, $clock);

        $httpClient = new class ($clock) extends StubHttpClient {
            public function __construct(protected readonly MockClock $clock)
            {
            }

            public function stream(
                string $url,
                array $headers,
                string $body,
                int $timeout,
                callable $onLine,
                ?string &$finishReason = null,
                ?callable $shouldAbort = null,
            ): void {
                foreach ($this->streamLines as $index => $line) {
                    if ($index > 0) {
                        $this->clock->advance(0.6);
                    }

                    if ($finishReason !== null && $finishReason !== '') {
                        return;
                    }

                    if ($shouldAbort !== null && $shouldAbort()) {
                        return;
                    }

                    $onLine($line);

                    if ($finishReason !== null && $finishReason !== '') {
                        return;
                    }
                }
            }
        };
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-reason',
                'choices' => [[
                    'delta' => ['reasoning_content' => 'Th', 'content' => ''],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-reason',
                'choices' => [[
                    'delta' => ['reasoning_content' => 'ink', 'content' => ''],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-reason',
                'choices' => [[
                    'delta' => ['content' => 'Hi'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('Think', $completion->output->reasoning);
        $this->assertSame('Hi', $completion->output->content);
        $this->assertSame(1.2, $completion->timing?->reasoning);
    }

    public function testCompleteSetsReasoningElapsedWhenReasoningOnly(): void
    {
        $clock = new MockClock(100.0);
        $this->container->replace(ClockInterface::class, $clock);

        $httpClient = new class ($clock) extends StubHttpClient {
            public function __construct(protected readonly MockClock $clock)
            {
            }

            public function post(string $url, array $headers, string $body, int $timeout = 60): HttpResponse
            {
                $this->clock->advance(2.5);

                return parent::post($url, $headers, $body, $timeout);
            }
        };
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason-only',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'reasoning_content' => 'Think only.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('Think only.', $completion->output->reasoning);
        $this->assertSame(2.5, $completion->timing?->reasoning);
        $this->assertSame(2.5, $completion->timing?->wall);
        $this->assertGreaterThan(0, $completion->timing?->start);
    }

    public function testStreamAggregatesToolCallDeltasByIndex(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-tools',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => ''],
                        ]],
                    ],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-tools',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'function' => ['arguments' => '{"location"'],
                        ]],
                    ],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-tools',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'function' => ['arguments' => ':"SF"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());

        $this->assertCount(1, $completion->output->toolCalls);
        $this->assertSame('call_1', $completion->output->toolCalls[0]->id);
        $this->assertSame('get_weather', $completion->output->toolCalls[0]->name);
        $this->assertSame(['location' => 'SF'], $completion->output->toolCalls[0]->arguments);
        $this->assertSame('tool_calls', $completion->finishReason);
    }

    public function testStreamAbortDropsPartialToolCallsWithoutThrowing(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-abort',
                'choices' => [[
                    'delta' => ['content' => 'Looking up…'],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-abort',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => ''],
                        ]],
                    ],
                    'finish_reason' => null,
                ]],
            ]),
            // Healable if decoded (`{"location":"SF` + `}`) — cancel must drop, not land.
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-abort',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'function' => ['arguments' => '{"location":"SF'],
                        ]],
                    ],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-abort',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'function' => ['arguments' => '"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $polls = 0;
        $completion = $this->makeOpenAi($httpClient)->stream(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))),
            Charter::of(),
            shouldAbort: static function () use (&$polls): bool {
                $polls++;

                // StubHttpClient polls before each line: after content + announce + partial args.
                return $polls >= 4;
            },
        );

        $this->assertTrue($completion->isCancelled());
        $this->assertSame(Completion::REASON_CANCEL, $completion->finishReason);
        $this->assertSame('Looking up…', $completion->output?->content);
        $this->assertSame([], $completion->output?->toolCalls ?? ['sentinel']);
    }

    public function testStreamAggregatesParallelToolCallDeltasByIndex(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-tools',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [
                            [
                                'index' => 0,
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => ['name' => 'get_weather', 'arguments' => '{"location"'],
                            ],
                            [
                                'index' => 1,
                                'id' => 'call_2',
                                'type' => 'function',
                                'function' => ['name' => 'get_time', 'arguments' => '{"tz"'],
                            ],
                        ],
                    ],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-tools',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [
                            ['index' => 0, 'function' => ['arguments' => ':"SF"}']],
                            ['index' => 1, 'function' => ['arguments' => ':"UTC"}']],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather and time?'))), Charter::of());

        $this->assertCount(2, $completion->output->toolCalls);
        $this->assertSame('get_weather', $completion->output->toolCalls[0]->name);
        $this->assertSame(['location' => 'SF'], $completion->output->toolCalls[0]->arguments);
        $this->assertSame('get_time', $completion->output->toolCalls[1]->name);
        $this->assertSame(['tz' => 'UTC'], $completion->output->toolCalls[1]->arguments);
    }

    public function testChunkEventsKeepPartialToolCallDeltas(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-tools',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => ''],
                        ]],
                    ],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-tools',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'function' => ['arguments' => '{"location":"SF"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $dispatcher = $this->eventDispatcher();
        $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());

        $events = $dispatcher->getEventsByClass(CompletionDelta::class);
        $this->assertCount(2, $events);
        $this->assertSame(0, $events[0]->index);
        $this->assertSame(1, $events[1]->index);
        $this->assertSame('get_weather', $events[0]->streamDelta->toolCallDeltas[0]->name);
        $this->assertSame('', $events[0]->streamDelta->toolCallDeltas[0]->partialJson);
        $this->assertSame('{"location":"SF"}', $events[1]->streamDelta->toolCallDeltas[0]->partialJson);
    }

    public function testCompleteRaisesOnInvalidToolArgumentsJson(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-bad-args',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"location"'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidToolArgumentsException::class);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
    }

    public function testCompleteClosesToolArgumentsMissingTrailingBrace(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-unclosed-args',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"location":"S{F"'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());

        $this->assertSame(['location' => 'S{F'], $completion->output->toolCalls[0]->arguments);
    }

    public function testStreamClosesToolArgumentsMissingTrailingBraces(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-unclosed',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => '{"where":{"city":"SF"'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());

        $this->assertSame(['where' => ['city' => 'SF']], $completion->output->toolCalls[0]->arguments);
    }

    public function testCompleteRaisesWhenToolCallHasNoId(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-no-id',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('missing its id');

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
    }

    public function testCompleteRaisesWhenToolCallHasNoFunctionName(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-no-name',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('missing its function name');

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
    }

    public function testCompleteRaisesWhenToolCallHasNoFunctionObject(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-no-fn',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [['id' => 'call_1', 'type' => 'function']],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('missing its function object');

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
    }

    public function testStreamRaisesWhenToolCallNeverGetsAnId(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-no-id',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'index' => 0,
                            'type' => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('missing its id');

        $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
    }

    public function testStreamRaisesWhenToolCallFragmentHasNoIndexOrId(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-orphan',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [[
                            'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('neither index nor id');

        $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
    }

    public function testStreamNamesTheOffendingSlotWhenToolCallHasNoId(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-no-id',
                'choices' => [[
                    'delta' => [
                        'tool_calls' => [
                            [
                                'index' => 0,
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                            ],
                            [
                                'index' => 2,
                                'type' => 'function',
                                'function' => ['name' => 'get_time', 'arguments' => '{}'],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            'data: [DONE]',
        ];

        try {
            $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
            $this->fail('Expected InvalidToolCallException.');
        } catch (InvalidToolCallException $e) {
            $this->assertSame('id', $e->missing());
            $this->assertSame(2, $e->index());
        }
    }

    public function testCompleteToolCallDefectHasNoStreamIndex(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-no-id',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Weather?'))), Charter::of());
            $this->fail('Expected InvalidToolCallException.');
        } catch (InvalidToolCallException $e) {
            $this->assertSame('id', $e->missing());
            $this->assertNull($e->index());
        }
    }

    public function testCompleteRoundTripsReasoningContentInHistory(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Answer.',
                    'reasoning_content' => 'Think first.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient);
        $thread = new Thread('test');
        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Question?'))), Charter::of());

        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-follow',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Follow up.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of(''))), Charter::of());

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertSame('Think first.', $body['messages'][1]['reasoning_content']);
        $this->assertArrayNotHasKey('reasoning', $body['messages'][1]);
        $this->assertSame('Answer.', $body['messages'][1]['content']);
    }

    public function testCompleteRoundTripsEmptyReasoningContentInHistory(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'reasoning_content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'echo', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient);
        $thread = new Thread('test');
        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Go'))), Charter::of());
        $thread->lastTurn()->completions[] = Completion::of(ToolMessage::of([
            new ToolResult('ok', 'call_1', 'echo'),
        ]));

        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-follow',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Done.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of(''))), Charter::of());

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertSame('', $body['messages'][1]['reasoning_content']);
        $this->assertArrayHasKey('tool_calls', $body['messages'][1]);
    }

    public function testCompleteOmitsReasoningWhenAbsentEvenWithDefaultReplay(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-plain',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Answer.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient);
        $thread = new Thread('test');
        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Q'))), Charter::of());

        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-follow',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Next.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of(''))), Charter::of());

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertArrayNotHasKey('reasoning_content', $body['messages'][1]);
        $this->assertArrayNotHasKey('reasoning', $body['messages'][1]);
    }

    public function testCompletePassbackFalseDisablesEcho(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Answer.',
                    'reasoning_content' => 'Think first.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient, ['passbackReasoning' => false]);
        $thread = new Thread('test');
        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Q'))), Charter::of());

        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-follow',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Next.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of(''))), Charter::of());

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertSame('Think first.', $thread->completions()[0]->output->reasoning);
        $this->assertArrayNotHasKey('reasoning_content', $body['messages'][1]);
        $this->assertArrayNotHasKey('reasoning', $body['messages'][1]);
    }

    public function testCompleteOmitsClosedTurnReasoningWhenPassbackNull(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Answer.',
                    'reasoning_content' => 'Think first.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient, ['passbackReasoning' => null]);
        $thread = new Thread('test');
        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Q'))), Charter::of());
        $thread->lastTurn()->status = TurnStatus::Completed;

        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-follow',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Next.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Again'))), Charter::of());

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertSame('Think first.', $thread->turns[0]->completions[0]->output->reasoning);
        $this->assertArrayNotHasKey('reasoning_content', $body['messages'][1]);
    }

    public function testCompletePassbackTrueIncludesClosedTurnReasoning(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-reason',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Answer.',
                    'reasoning_content' => 'Think first.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient, ['passbackReasoning' => true]);
        $thread = new Thread('test');
        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Q'))), Charter::of());
        $thread->lastTurn()->status = TurnStatus::Completed;

        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-follow',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Next.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Again'))), Charter::of());

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertSame('Think first.', $body['messages'][1]['reasoning_content']);
    }

    public function testStreamLandsRefusalDeltaAsRefusalPart(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-refuse',
                'choices' => [[
                    'delta' => ['refusal' => 'I cannot '],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-refuse',
                'choices' => [[
                    'delta' => ['refusal' => 'help.'],
                    'finish_reason' => 'stop',
                ]],
            ]),
            'data: [DONE]',
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Bad ask'))), Charter::of());

        $this->assertNull($completion->output->content);
        $this->assertInstanceOf(RefusalPart::class, $completion->output->parts[0]);
        $this->assertSame('I cannot help.', $completion->output->parts[0]->refusal);
    }

    public function testCompleteRoundTripsToolCallHistoryFromMessageArray(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-tools',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"SF"}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions = $this->makeOpenAi($httpClient);
        $thread = new Thread('test');
        $completion = $chatCompletions->complete($this->hang($thread, Completion::of(UserMessage::of('Weather?'))), Charter::of());

        $httpClient->responseBody = (string) json_encode([
            'id' => 'chatcmpl-final',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'It is 72F.'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);

        $chatCompletions->complete($this->hang($thread, Completion::of(ToolMessage::of([new ToolResult('{"temp":72}', $completion->output->toolCalls[0]->id, $completion->output->toolCalls[0]->name)]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertSame('assistant', $body['messages'][1]['role']);
        $this->assertNull($body['messages'][1]['content']);
        $this->assertSame('call_1', $body['messages'][1]['tool_calls'][0]['id']);
        $this->assertSame('get_weather', $body['messages'][1]['tool_calls'][0]['function']['name']);
        $this->assertSame('tool', $body['messages'][2]['role']);
        $this->assertSame('call_1', $body['messages'][2]['tool_call_id']);
        $this->assertSame('{"temp":72}', $body['messages'][2]['content']);
    }

    public function testCompleteRaisesOnInvalidJsonResponse(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = 'not json';

        try {
            $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());
            $this->fail('Expected InvalidResponseException.');
        } catch (InvalidResponseException $e) {
            $this->assertStringContainsString('Invalid LLM JSON response', $e->getMessage());
        }
    }

    public function testCompleteRaisesOnMissingChoices(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode(['id' => 'chatcmpl-1'], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidResponseException::class);

        $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());
    }

    public function testCompleteRaisesProviderErrorForProviderErrorPayload(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'error' => [
                'message' => 'quota exceeded',
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->makeOpenAi($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());
            $this->fail('Expected ProviderErrorException.');
        } catch (ProviderErrorException $e) {
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
        }
    }

    public function testStreamRaisesProviderErrorForMidStreamErrorFrame(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [['delta' => ['content' => 'Hel']]],
            ]),
            'data: ' . Json::stringify(['error' => ['message' => 'rate limit reached']]),
        ];

        try {
            $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());
            $this->fail('Expected ProviderErrorException.');
        } catch (ProviderErrorException $e) {
            $this->assertStringContainsString('rate limit reached', $e->getMessage());
        }
    }

    public function testStreamDispatchesCompletionFailedForMidStreamErrorFrame(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify(['error' => ['message' => 'rate limit reached']]),
        ];

        $dispatcher = $this->eventDispatcher();

        try {
            $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());
        } catch (ProviderErrorException) {
        }

        $failed = $dispatcher->getEventsByClass(CompletionFailed::class);
        $this->assertCount(1, $failed);
        $this->assertInstanceOf(ProviderErrorException::class, $failed[0]->error);
    }

    public function testStreamIgnoresNonDataLinesAndBuffersPartialLine(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'event: ping',
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'Hel'],
                    'finish_reason' => null,
                ]],
            ]),
            'data: ' . Json::stringify([
                'id' => 'chatcmpl-stream',
                'choices' => [[
                    'delta' => ['content' => 'lo'],
                    'finish_reason' => 'stop',
                ]],
            ]) . "\r",
        ];

        $completion = $this->makeOpenAi($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(prompt: 'Stay concise.'), );

        $this->assertSame('Hello', $completion->output?->content);
        $this->assertSame('Stay concise.', Json::parse($httpClient->streams[0]['body'])['messages'][0]['content']);
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function makeOpenAi(StubHttpClient $httpClient, array $config = []): OpenAi
    {
        return $this->make(OpenAi::class, [
            'httpClient' => $httpClient,
            'baseUrl' => 'https://api.openai.com/v1',
            'apiKey' => 'test-key',
            'model' => 'gpt-4o-mini',
            'window' => 128_000,
            ...$config,
        ]);
    }
}
