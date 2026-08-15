<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\Exception\ToolDeniedException;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\AskPart;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Llm\AbstractLlm;
use Switon\Llm\Anthropic;
use Switon\Llm\Event\CompletionDelta;
use Switon\Llm\Exception\InvalidResponseException;
use Switon\Llm\Exception\InvalidToolCallException;
use Switon\Llm\Exception\ProviderErrorException;
use Switon\Llm\Tests\TestCase;
use Switon\Llm\Tests\Unit\Fixtures\StubHttpClient;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

#[CoversClass(Anthropic::class)]
class AnthropicTest extends TestCase
{
    public function testCompletePostsMessagesWithAnthropicHeadersAndSystem(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $anthropic = $this->makeAnthropic($httpClient, [
            'baseUrl' => 'https://api.example.test/v1',
            'apiKey' => 'secret',
        ]);

        $anthropic->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Ping'))), Charter::of(prompt: 'Be helpful.'), );

        $this->assertCount(1, $httpClient->posts);
        $this->assertSame('https://api.example.test/v1/messages', $httpClient->posts[0]['url']);
        $this->assertSame('secret', $httpClient->posts[0]['headers']['x-api-key']);
        $this->assertSame('2023-06-01', $httpClient->posts[0]['headers']['anthropic-version']);

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('Be helpful.', $body['system']);
        $this->assertSame('Ping', $body['messages'][0]['content']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertArrayNotHasKey('stream', $body);
        $this->assertArrayNotHasKey('top_p', $body);
        $this->assertArrayNotHasKey('stop_sequences', $body);
    }

    public function testCompleteSendsEveryFoldedMessageBlock(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $folded = new FoldedMessage('user: earlier', [
            new PinnedPart('skill:live-code', 'The verification number is 44426633.'),
        ]);
        $thread = new Thread('test', turns: [Turn::of('t', completions: [
            Completion::of($folded, new AssistantMessage('ack')),
        ], status: TurnStatus::Completed)]);
        $this->makeAnthropic($httpClient)->complete(
            $this->hang($thread, Completion::of(UserMessage::of('Continue'))),
            Charter::of(),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'user: earlier'],
            ['type' => 'text', 'text' => 'The verification number is 44426633.'],
        ], $body['messages'][0]['content']);
    }

    public function testCompletePostsOptionalSamplingFields(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $this->makeAnthropic($httpClient, [
            'extra' => [
                'top_p' => 0.8,
                'stop_sequences' => ['END'],
            ],
        ])->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame(0.8, $body['top_p']);
        $this->assertSame(['END'], $body['stop_sequences']);
    }

    public function testCharterEffortPostsOutputConfigEffort(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $this->makeAnthropic($httpClient, ['model' => 'claude-test'])->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(effort: 'high'),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame(['effort' => 'high'], $body['output_config']);
    }

    public function testCharterEffortKeepsSiblingOutputConfigKeys(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $this->makeAnthropic($httpClient, [
            'model' => 'claude-test',
            'extra' => ['output_config' => ['format' => ['type' => 'json_schema']]],
        ])->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(effort: 'low'),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame(['format' => ['type' => 'json_schema'], 'effort' => 'low'], $body['output_config']);
    }

    public function testAbsentCharterEffortOmitsOutputConfig(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $this->makeAnthropic($httpClient, ['model' => 'claude-test'])->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertArrayNotHasKey('output_config', $body);
    }

    public function testCompleteParsesToolUseBlocksIntoToolCall(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_tools',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Checking weather.'],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'get_weather',
                    'input' => ['location' => 'SF'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());

        $this->assertSame('Checking weather.', $completion->output->content);
        $this->assertCount(1, $completion->output->toolCalls);
        $this->assertSame('toolu_1', $completion->output->toolCalls[0]->id);
        $this->assertSame('get_weather', $completion->output->toolCalls[0]->name);
        $this->assertSame(['location' => 'SF'], $completion->output->toolCalls[0]->arguments);
        $this->assertSame('tool_use', $completion->finishReason);
        $this->assertNotNull($completion->usage);
        $this->assertSame(10, $completion->usage->input);
        $this->assertSame(5, $completion->usage->output);
    }

    public function testCompleteEncodesToolResultCoalescingAfterAssistantToolUse(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_tools',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'get_weather',
                    'input' => ['location' => 'SF'],
                ],
            ],
            'stop_reason' => 'tool_use',
        ], JSON_THROW_ON_ERROR);

        $anthropic = $this->makeAnthropic($httpClient);
        $thread = new Thread('test');
        $completion = $anthropic->complete($this->hang($thread, Completion::of(UserMessage::of('Weather?'))), Charter::of());

        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_final',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => '72F.']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $anthropic->complete($this->hang($thread, Completion::of(ToolMessage::of([new ToolResult('{"temp":72}', $completion->output->toolCalls[0]->id, $completion->output->toolCalls[0]->name)]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[1]['body']);
        $this->assertSame('assistant', $body['messages'][1]['role']);
        $this->assertSame('tool_use', $body['messages'][1]['content'][0]['type']);
        $this->assertSame('toolu_1', $body['messages'][1]['content'][0]['id']);
        $this->assertSame('user', $body['messages'][2]['role']);
        $this->assertCount(1, $body['messages'][2]['content']);
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('toolu_1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('{"temp":72}', $body['messages'][2]['content'][0]['content']);
    }

    public function testCompleteEncodesCoalescedConsecutiveToolResults(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_final',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'done']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $thread = new Thread('test', turns: [Turn::of('t', completions: [
                Completion::of(UserMessage::of('Run tools'), new AssistantMessage('', [
                    new ToolCall('toolu_1', 'get_weather', ['location' => 'SF']),
                    new ToolCall('toolu_2', 'get_time', ['tz' => 'UTC']),
                ])),
            ], status: TurnStatus::Completed),
        ]);

        $this->makeAnthropic($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([
                new ToolResult('{"temp":72}', 'toolu_1', 'get_weather'),
                new ToolResult('{"time":"12:00"}', 'toolu_2', 'get_time'),
            ]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('user', $body['messages'][2]['role']);
        $this->assertCount(2, $body['messages'][2]['content']);
        $this->assertSame('toolu_1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('toolu_2', $body['messages'][2]['content'][1]['tool_use_id']);
    }

    public function testCompleteMarksExceptionContentAsToolError(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_final',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'done']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $thread = new Thread('test', turns: [Turn::of('t', completions: [
                Completion::of(UserMessage::of('Run tools'), new AssistantMessage('', [
                    new ToolCall('toolu_1', 'get_weather', ['location' => 'SF']),
                ])),
            ], status: TurnStatus::Completed),
        ]);

        $this->makeAnthropic($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([
            new ToolResult(ToolDeniedException::of('not allowed'), 'toolu_1', 'get_weather'),
        ]))), Charter::of());

        $body = Json::parse($httpClient->posts[0]['body']);
        $block = $body['messages'][2]['content'][0];
        $this->assertTrue($block['is_error']);
        $this->assertSame(
            Json::text(['class' => ToolDeniedException::class, 'message' => 'not allowed']),
            $block['content'],
        );
    }

    public function testStreamAggregatesTextDeltas(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => ['id' => 'msg_stream', 'type' => 'message', 'role' => 'assistant'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'Hel'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'lo'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 2],
            ]),
        ];

        $completion = $this->makeAnthropic($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('Hello', $completion->output?->content);
        $this->assertSame('end_turn', $completion->finishReason);
        $this->assertSame('text/event-stream', $httpClient->streams[0]['headers']['Accept']);
    }

    public function testStreamWithMessageStartOnlyLandsEmptyWithoutFinishReason(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => [
                    'id' => 'msg_prelude_only',
                    'type' => 'message',
                    'role' => 'assistant',
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                ],
            ]),
        ];

        $completion = $this->makeAnthropic($httpClient)->stream(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );

        $this->assertInstanceOf(AssistantMessage::class, $completion->output);
        $this->assertSame('', $completion->output->content);
        $this->assertNull($completion->finishReason);
    }

    public function testStreamIgnoresEmptyStopReasonUntilEndTurn(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => ['id' => 'msg_empty_sr', 'type' => 'message', 'role' => 'assistant'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => ''],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'Hello'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 1],
            ]),
        ];

        $completion = $this->makeAnthropic($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of(), );

        $this->assertSame('Hello', $completion->output?->content);
        $this->assertSame('end_turn', $completion->finishReason);
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
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ], JSON_THROW_ON_ERROR);

            $this->makeAnthropic($httpClient)->complete(
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
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/png',
                        'data' => base64_encode('PNG'),
                    ],
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
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ], JSON_THROW_ON_ERROR);

            $thread = new Thread('tid', turns: [Turn::of('t', completions: [
                    Completion::of(UserMessage::of('Look'), new AssistantMessage('', [
                        new ToolCall('toolu_1', 'browser_take_screenshot', []),
                    ])),
                ], status: TurnStatus::Completed),
            ], datadir: '@threads/tid');

            $this->makeAnthropic($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([
                new ToolResult('shot ok', 'toolu_1', 'browser_take_screenshot', parts: [
                    MediaPart::of('tool-asset/shot.png', mime: 'image/png'),
                ]),
            ]))), Charter::of());

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'shot ok'],
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/png',
                        'data' => base64_encode('PNG'),
                    ],
                ],
            ], $body['messages'][2]['content'][0]['content']);
        } finally {
            @unlink($root . '/tid/tool-asset/shot.png');
            @rmdir($root . '/tid/tool-asset');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteEncodesToolResultVideoAsSiblingAfterToolResult(): void
    {
        $root = sys_get_temp_dir() . '/switon-tool-video-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid/tool-asset', 0777, true);
        file_put_contents($root . '/tid/tool-asset/clip.mp4', 'MP4');
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ], JSON_THROW_ON_ERROR);

            $thread = new Thread('tid', turns: [Turn::of('t', completions: [
                    Completion::of(UserMessage::of('Watch'), new AssistantMessage('', [
                        new ToolCall('toolu_1', 'media', []),
                    ])),
                ], status: TurnStatus::Completed),
            ], datadir: '@threads/tid');

            $this->makeAnthropic($httpClient)->complete($this->hang($thread, Completion::of(ToolMessage::of([
                new ToolResult('', 'toolu_1', 'media', parts: [
                    MediaPart::of('tool-asset/clip.mp4', mime: 'video/mp4', extra: ['fps' => 4]),
                ]),
            ]))), Charter::of());

            $body = Json::parse($httpClient->posts[0]['body']);
            $userContent = $body['messages'][2]['content'];
            $this->assertSame('tool_result', $userContent[0]['type']);
            // Video moved to sibling; leftover text is compacted to a string.
            $this->assertSame('Loaded media.', $userContent[0]['content']);
            $this->assertSame('video', $userContent[1]['type']);
            $this->assertSame([
                'type' => 'base64',
                'media_type' => 'video/mp4',
                'data' => base64_encode('MP4'),
            ], $userContent[1]['source']);
            $this->assertArrayNotHasKey('fps', $userContent[1]['source']);
        } finally {
            @unlink($root . '/tid/tool-asset/clip.mp4');
            @rmdir($root . '/tid/tool-asset');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteUnloadsHistoricalToolResultImageOnWire(): void
    {
        $root = sys_get_temp_dir() . '/switon-tool-asset-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid/tool-asset', 0777, true);
        $ref = 'tool-asset/toolu_1-1.png';
        file_put_contents($root . '/tid/' . $ref, 'PNG');
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ], JSON_THROW_ON_ERROR);

            $toolResult = new ToolResult('shot ok', 'toolu_1', 'browser_take_screenshot', parts: [
                MediaPart::of($ref, mime: 'image/png'),
            ]);
            $thread = new Thread('tid', turns: [Turn::of('t', completions: [
                    Completion::of(UserMessage::of('Look'), new AssistantMessage('', [
                        new ToolCall('toolu_1', 'browser_take_screenshot', []),
                    ])),
                    Completion::of(
                        ToolMessage::of([$toolResult]),
                        new AssistantMessage('seen'),
                    ),
                ], status: TurnStatus::Completed),
            ], datadir: '@threads/tid');

            $this->makeAnthropic($httpClient)->complete(
                $this->hang($thread, Completion::of(UserMessage::of('again'))),
                Charter::of(),
            );

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'shot ok'],
                ['type' => 'text', 'text' => AbstractLlm::UNLOADED_MEDIA_NOTICE . ' ref: ' . $ref],
            ], $body['messages'][2]['content'][0]['content']);
            $this->assertCount(1, $toolResult->parts);
            $this->assertInstanceOf(MediaPart::class, $toolResult->parts[0]);
        } finally {
            @unlink($root . '/tid/' . $ref);
            @rmdir($root . '/tid/tool-asset');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteEncodesRemoteImageUrlSource(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('', [
                MediaPart::of('https://cdn.example.com/a.png', mime: 'image/png'),
            ]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => 'https://cdn.example.com/a.png',
                ],
            ],
        ], $body['messages'][0]['content']);
    }

    public function testCompleteEncodesRemoteVideoUrlSource(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('watch', [
                MediaPart::of('https://cdn.example.com/a.mp4', mime: 'video/mp4'),
            ]))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'watch'],
            [
                'type' => 'video',
                'source' => [
                    'type' => 'url',
                    'url' => 'https://cdn.example.com/a.mp4',
                ],
            ],
        ], $body['messages'][0]['content']);
    }

    public function testCompleteEncodesUserVideoPartsFromDatadirRef(): void
    {
        $root = sys_get_temp_dir() . '/switon-user-video-' . bin2hex(random_bytes(4));
        mkdir($root . '/tid/attachments', 0777, true);
        file_put_contents($root . '/tid/attachments/clip.mp4', 'MP4');
        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        try {
            $httpClient = new StubHttpClient();
            $httpClient->responseBody = (string) json_encode([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
            ], JSON_THROW_ON_ERROR);

            $this->makeAnthropic($httpClient)->complete(
                $this->hang(Thread::of('tid', '@threads/tid'), Completion::of(UserMessage::of('', [
                    AskPart::of('watch'),
                    MediaPart::of('attachments/clip.mp4', mime: 'video/mp4'),
                ]))),
                Charter::of(),
            );

            $body = Json::parse($httpClient->posts[0]['body']);
            $this->assertSame([
                ['type' => 'text', 'text' => 'watch'],
                [
                    'type' => 'video',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'video/mp4',
                        'data' => base64_encode('MP4'),
                    ],
                ],
            ], $body['messages'][0]['content']);
        } finally {
            @unlink($root . '/tid/attachments/clip.mp4');
            @rmdir($root . '/tid/attachments');
            @rmdir($root . '/tid');
            @rmdir($root);
        }
    }

    public function testCompleteParsesThinkingBlockIntoReasoningContent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_think',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'thinking',
                    'thinking' => 'Let me analyze this step by step.',
                    'signature' => 'sig_abc',
                ],
                ['type' => 'text', 'text' => 'The answer is 42.'],
            ],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Solve it'))), Charter::of(), );

        $this->assertSame('Let me analyze this step by step.', $completion->output->reasoning);
        $this->assertSame('The answer is 42.', $completion->output->content);
        $this->assertSame('sig_abc', $completion->output->passback[Anthropic::THINKING_SIGNATURE] ?? null);
    }

    public function testCompleteOmitsClosedTurnThinkingWhenPassbackNull(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_think_2',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'next']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $prior = new AssistantMessage(
            'The answer is 42.',
            reasoning: 'Let me analyze this step by step.',
            passback: [Anthropic::THINKING_SIGNATURE => 'sig_abc'],
        );

        $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test', turns: [Turn::of('t', completions: [
                    Completion::of(UserMessage::of('Solve it'), $prior),
                ], status: TurnStatus::Completed),
            ]), Completion::of(UserMessage::of('Confirm'))), Charter::of(), );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            [
                'type' => 'text',
                'text' => 'The answer is 42.',
            ],
        ], $body['messages'][1]['content']);
        $this->assertArrayNotHasKey(Anthropic::THINKING_SIGNATURE, $body['messages'][1]);
    }

    public function testCompletePassbackTrueIncludesClosedTurnThinking(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_think_3',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'next']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $prior = new AssistantMessage(
            'The answer is 42.',
            reasoning: 'Let me analyze this step by step.',
            passback: [Anthropic::THINKING_SIGNATURE => 'sig_abc'],
        );

        $this->makeAnthropic($httpClient, ['passbackReasoning' => true])->complete(
            $this->hang(new Thread('test', turns: [Turn::of('t', completions: [
                    Completion::of(UserMessage::of('Solve it'), $prior),
                ], status: TurnStatus::Completed),
            ]), Completion::of(UserMessage::of('Confirm'))),
            Charter::of(),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame([
            [
                'type' => 'thinking',
                'thinking' => 'Let me analyze this step by step.',
                'signature' => 'sig_abc',
            ],
            [
                'type' => 'text',
                'text' => 'The answer is 42.',
            ],
        ], $body['messages'][1]['content']);
    }

    public function testStreamAggregatesThinkingDeltasIntoReasoningContent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => ['id' => 'msg_stream', 'type' => 'message', 'role' => 'assistant'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'thinking', 'thinking' => ''],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me '],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'thinking_delta', 'thinking' => 'think.'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'signature_delta', 'signature' => 'sig_abc'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_start',
                'index' => 1,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 1,
                'delta' => ['type' => 'text_delta', 'text' => 'Done.'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
            ]),
        ];

        $dispatcher = $this->eventDispatcher();
        $completion = $this->makeAnthropic($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Solve it'))), Charter::of(), );

        $this->assertSame('Let me think.', $completion->output?->reasoning);
        $this->assertSame('Done.', $completion->output?->content);
        $this->assertSame('sig_abc', $completion->output?->passback[Anthropic::THINKING_SIGNATURE] ?? null);

        $events = $dispatcher->getEventsByClass(CompletionDelta::class);
        $this->assertSame('Let me ', $events[1]->streamDelta->reasoning);
        $this->assertSame('', $events[1]->streamDelta->content);
        $this->assertSame('think.', $events[2]->streamDelta->reasoning);
        $this->assertSame('', $events[2]->streamDelta->content);
        $this->assertSame('sig_abc', $events[3]->streamDelta->passback[Anthropic::THINKING_SIGNATURE] ?? null);
    }

    public function testStreamClosesToolInputMissingTrailingBrace(): void
    {
        $completion = $this->makeAnthropic($this->toolUseStream('{"pattern":"a{b","offset":0'))->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Search'))), Charter::of(), );

        $this->assertSame(['pattern' => 'a{b', 'offset' => 0], $completion->output?->toolCalls[0]->arguments);
    }

    public function testStreamClosesToolInputMissingTwoTrailingBraces(): void
    {
        $completion = $this->makeAnthropic($this->toolUseStream('{"opts":{"x":1'))->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Search'))), Charter::of(), );

        $this->assertSame(['opts' => ['x' => 1]], $completion->output?->toolCalls[0]->arguments);
    }

    public function testStreamClosesToolInputMissingArrayAndObjectClosers(): void
    {
        $completion = $this->makeAnthropic($this->toolUseStream('{"paths":["a","b"'))->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Search'))), Charter::of(), );

        $this->assertSame(['paths' => ['a', 'b']], $completion->output?->toolCalls[0]->arguments);
    }

    public function testStreamKeepsOtherwiseBrokenToolInputInvalid(): void
    {
        $this->expectException(InvalidToolArgumentsException::class);

        $this->makeAnthropic($this->toolUseStream('{"pattern":"ab'))->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Search'))), Charter::of(), );
    }

    public function testCompleteRaisesWhenToolUseBlockHasNoId(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_tools',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'name' => 'get_weather', 'input' => ['location' => 'SF']],
            ],
            'stop_reason' => 'tool_use',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('missing its id');

        $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());
    }

    public function testCompleteRaisesWhenToolUseBlockHasNoName(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_tools',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'input' => []],
            ],
            'stop_reason' => 'tool_use',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('missing its name');

        $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());
    }

    public function testStreamRaisesWhenToolUseStartHasNoId(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => ['id' => 'msg_tool', 'type' => 'message', 'role' => 'assistant'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'tool_use', 'name' => 'grep', 'input' => []],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'tool_use'],
            ]),
        ];

        $this->expectException(InvalidToolCallException::class);
        $this->expectExceptionMessage('missing its id');

        $this->makeAnthropic($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Search'))), Charter::of(), );
    }

    protected function toolUseStream(string $partialJson): StubHttpClient
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => ['id' => 'msg_tool', 'type' => 'message', 'role' => 'assistant'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'grep', 'input' => []],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => $partialJson],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'tool_use'],
            ]),
        ];

        return $httpClient;
    }

    public function testStreamRaisesProviderErrorForErrorFrame(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => ['id' => 'msg_stream', 'type' => 'message', 'role' => 'assistant'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'error',
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ]),
        ];

        try {
            $this->makeAnthropic($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());
            $this->fail('Expected ProviderErrorException.');
        } catch (ProviderErrorException $e) {
            $this->assertStringContainsString('Overloaded', $e->getMessage());
        }
    }

    public function testCompleteRaisesProviderErrorForErrorTypeWithoutErrorObject(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = Json::stringify(['type' => 'error']);

        $this->expectException(ProviderErrorException::class);

        $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of(''))), Charter::of());
    }

    public function testNormalizeBaseUrlRewritesMiniMaxAnthropicPaths(): void
    {
        $this->assertSame(
            'https://api.minimaxi.com/anthropic/v1',
            Anthropic::normalizeBaseUrl('https://api.minimaxi.com/v1'),
        );
        $this->assertSame(
            'https://api.minimax.io/anthropic/v1',
            Anthropic::normalizeBaseUrl('https://api.minimax.io/anthropic'),
        );
        $this->assertSame(
            'https://api.anthropic.com/v1',
            Anthropic::normalizeBaseUrl('https://api.anthropic.com/v1'),
        );
        $this->assertSame('', Anthropic::normalizeBaseUrl(''));
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function toolChoiceProvider(): array
    {
        return [
            'auto' => ['auto', ['type' => 'auto']],
            'none' => ['none', ['type' => 'none']],
            'required' => ['required', ['type' => 'any']],
            'named' => ['bash', ['type' => 'tool', 'name' => 'bash']],
        ];
    }

    /** @param array<string, string> $expected */
    #[DataProvider('toolChoiceProvider')]
    public function testCompletePostsToolsAndToolChoice(string $choice, array $expected): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = Json::stringify([
            'id' => 'msg_1',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ]);

        $this->makeAnthropic($httpClient)->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Run'))),
            Charter::of(
                tools: ['bash' => new ToolSchema(description: 'Run a command')],
                toolChoice: $choice,
            ),
        );

        $body = Json::parse($httpClient->posts[0]['body']);
        $this->assertSame('bash', $body['tools'][0]['name']);
        $this->assertSame('Run a command', $body['tools'][0]['description']);
        $this->assertSame('object', $body['tools'][0]['input_schema']['type']);
        $this->assertSame($expected, $body['tool_choice']);
    }

    public function testCompleteRejectsBrokenJson(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = 'not-json';

        $this->expectException(InvalidResponseException::class);
        $this->makeAnthropic($httpClient)->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );
    }

    public function testCompleteRejectsAScalarBody(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = 'null';

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('must decode to an object');
        $this->makeAnthropic($httpClient)->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );
    }

    public function testCompleteRejectsMissingContent(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = '{}';

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('missing content');
        $this->makeAnthropic($httpClient)->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );
    }

    public function testStreamIgnoresFramesThatCarryNoDelta(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify(['type' => 'ping']),
            'data: ' . Json::stringify(['type' => 'content_block_delta', 'index' => 0]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'signature_delta', 'signature' => ''],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'unknown_delta'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'ok'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
            ]),
        ];

        $completion = $this->makeAnthropic($httpClient)->stream(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );

        $this->assertSame('ok', $completion->output?->content);
    }

    public function testCompleteOmitsAnEmptyAssistantAndKeepsPassback(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = Json::stringify([
            'id' => 'msg_1',
            'content' => [['type' => 'text', 'text' => 'next']],
            'stop_reason' => 'end_turn',
        ]);
        $empty = Completion::of(UserMessage::of('Q'), new AssistantMessage(''));
        $kept = Completion::of(
            UserMessage::of('Again'),
            new AssistantMessage('Answer.', passback: ['cache_control' => ['type' => 'ephemeral']]),
        );
        $thread = new Thread('t');
        $thread->turns[] = Turn::of('prev', [$empty, $kept], TurnStatus::Completed, 'claude');

        $this->makeAnthropic($httpClient)->complete(
            $this->hang($thread, Completion::of(UserMessage::of('Next'))),
            Charter::of(),
        );

        $messages = Json::parse($httpClient->posts[0]['body'])['messages'];
        $roles = array_column($messages, 'role');
        $this->assertSame(['user', 'user', 'assistant', 'user'], $roles);
        $this->assertSame(['type' => 'ephemeral'], $messages[2]['cache_control']);
    }

    public function testCompleteParsesAToolUseWithoutInputAndSkipsJunkBlocks(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = Json::stringify([
            'id' => 'msg_1',
            'content' => [
                null,
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'bash'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [],
        ]);

        $completion = $this->makeAnthropic($httpClient)->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );

        $this->assertSame([], $completion->output?->toolCalls[0]->arguments);
        $this->assertNull($completion->usage);
    }

    public function testCompleteUnloadsMediaItCannotSend(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = Json::stringify([
            'id' => 'msg_1',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ]);

        $this->makeAnthropic($httpClient)->complete(
            $this->hang(new Thread('test'), Completion::of(UserMessage::of('Look', [
                MediaPart::of('https://cdn.example.com/a.bin', 'application/octet-stream'),
                MediaPart::of('missing.png', 'image/png'),
            ]))),
            Charter::of(),
        );

        $body = $httpClient->posts[0]['body'];
        $this->assertStringContainsString('https://cdn.example.com/a.bin', $body);
        $this->assertStringContainsString('missing.png', $body);
        $this->assertStringContainsString(AbstractLlm::UNLOADED_MEDIA_NOTICE, $body);
    }

    public function testCompleteUsesNormalizedMiniMaxBaseUrl(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);

        $this->makeAnthropic($httpClient, [
            'baseUrl' => 'https://api.minimaxi.com/v1',
            'apiKey' => 'secret',
        ])->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('https://api.minimaxi.com/anthropic/v1/messages', $httpClient->posts[0]['url']);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function testCompleteParsesPromptCacheTokensIntoPromptUsage(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->responseBody = (string) json_encode([
            'id' => 'msg_cache',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 460,
                'output_tokens' => 124,
                'cache_read_input_tokens' => 3_085,
            ],
        ], JSON_THROW_ON_ERROR);

        $completion = $this->makeAnthropic($httpClient)->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('follow-up'))), Charter::of(), );

        $this->assertNotNull($completion->usage);
        $this->assertSame(3_545, $completion->usage->input);
        $this->assertSame(124, $completion->usage->output);
        $this->assertSame(3_669, $completion->usage->total());
        $this->assertSame(460, $completion->usage->uncached());
        $this->assertSame(3_085, $completion->usage->cacheRead);
        $this->assertSame(0, $completion->usage->cacheWrite);
        $this->assertSame(0, $completion->usage->reasoning);
    }

    public function testStreamMergesMessageStartInputAndMessageDeltaOutputUsage(): void
    {
        $httpClient = new StubHttpClient();
        $httpClient->streamLines = [
            'data: ' . Json::stringify([
                'type' => 'message_start',
                'message' => [
                    'id' => 'msg_stream_cache',
                    'type' => 'message',
                    'role' => 'assistant',
                    'usage' => [
                        'input_tokens' => 460,
                        'output_tokens' => 0,
                        'cache_read_input_tokens' => 3_085,
                    ],
                ],
            ]),
            'data: ' . Json::stringify([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'ok'],
            ]),
            'data: ' . Json::stringify([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 124],
            ]),
        ];

        $completion = $this->makeAnthropic($httpClient)->stream($this->hang(new Thread('test'), Completion::of(UserMessage::of('follow-up'))), Charter::of(), );

        $this->assertNotNull($completion->usage);
        $this->assertSame(3_545, $completion->usage->input);
        $this->assertSame(124, $completion->usage->output);
        $this->assertSame(3_669, $completion->usage->total());
        $this->assertSame(460, $completion->usage->uncached());
        $this->assertSame(3_085, $completion->usage->cacheRead);
    }

    protected function makeAnthropic(StubHttpClient $httpClient, array $config = []): Anthropic
    {
        return $this->make(Anthropic::class, [
            'httpClient' => $httpClient,
            'baseUrl' => 'https://api.anthropic.com/v1',
            'apiKey' => 'test-key',
            'model' => 'claude-sonnet-4-20250514',
            'window' => 128_000,
            ...$config,
        ]);
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }
}
