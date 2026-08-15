<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\Ai\LlmInterface;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Switon\Llm\Anthropic;
use Switon\Llm\Command\LlmCommand;
use Switon\Llm\Http\HttpClient;
use Switon\Llm\Http\HttpClientInterface;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProvidersInterface;
use Switon\Llm\OpenAi;
use Switon\Llm\Tests\TestCase;
use Switon\Llm\Tests\Unit\Fixtures\StubCurl;
use Switon\Llm\Tests\Unit\Fixtures\StubHttpClient;

#[CoversClass(LlmCommand::class)]
class LlmCommandTest extends TestCase
{
    protected ConsoleInterface&MockObject $console;

    protected function setUp(): void
    {
        parent::setUp();
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->container->set(ConsoleInterface::class, $this->console);
    }

    public function testListPrintsBothWiresAndKeepsAliasesOffTheIdColumn(): void
    {
        $this->catalog();
        $this->console->expects($this->once())
            ->method('table')
            ->with(
                ['id', 'model', 'class', 'baseUrl', 'image', 'video', 'efforts', 'aliases'],
                [
                    ['openai/default', 'gpt-test', OpenAi::class, 'http://openai.test/v1', 'yes', 'no', '', 'main'],
                    ['minimax/M3', 'MiniMax-M3', Anthropic::class, 'https://api.minimaxi.com/anthropic/v1', 'yes', 'yes', 'low,high', 'best'],
                ],
            );

        $this->assertSame(0, $this->command()->listAction());
    }

    public function testListJsonIncludesClassAndFlags(): void
    {
        $this->catalog();
        $encoded = '';
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $message, array $context = [], int $options = 0) use (&$encoded): void {
                unset($context, $options);
                $encoded = $message;
            });

        $this->assertSame(0, $this->command()->listAction(true));
        $rows = Json::parse($encoded);
        $this->assertSame(OpenAi::class, $rows[0]['class']);
        $this->assertSame(Anthropic::class, $rows[1]['class']);
        $this->assertTrue($rows[1]['video']);
        $this->assertSame(['best'], $rows[1]['aliases']);
    }

    public function testShowEmptyNameIsFirstClient(): void
    {
        $this->catalog();
        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $message, array $context = [], int $options = 0) use (&$lines): void {
                unset($context, $options);
                $lines[] = $message;
            });

        $this->assertSame(0, $this->command()->showAction());
        $this->assertSame('id:          openai/default', $lines[0]);
    }

    public function testPingRefusesVideoWhenTheCatalogFlagIsOff(): void
    {
        $http = $this->catalog();
        $this->console->expects($this->once())
            ->method('error')
            ->with('{id} does not accept video.', ['id' => 'openai/default'], 1)
            ->willReturn(1);

        $code = $this->command()->pingAction('openai/default', 'hi', '', 'https://cdn.example.com/a.mp4');

        $this->assertSame(1, $code);
        $this->assertSame([], $http->posts);
    }

    public function testPingEncodesImageForOpenAiAndAnthropic(): void
    {
        $http = $this->catalog();
        $http->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'a house'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->console->expects($this->exactly(2))->method('writeLn');

        $this->assertSame(0, $this->command()->pingAction('openai/default', 'What is this?', 'https://cdn.example.com/a.png'));
        $openAi = Json::parse($http->posts[0]['body']);
        $this->assertSame('image_url', $openAi['messages'][0]['content'][1]['type']);
        $this->assertSame('https://cdn.example.com/a.png', $openAi['messages'][0]['content'][1]['image_url']['url']);

        $http->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'a house']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);
        $this->assertSame(0, $this->command()->pingAction('minimax/M3', 'What is this?', 'https://cdn.example.com/a.png'));
        $anthropic = Json::parse($http->posts[1]['body']);
        $this->assertSame('image', $anthropic['messages'][0]['content'][1]['type']);
        $this->assertSame('https://cdn.example.com/a.png', $anthropic['messages'][0]['content'][1]['source']['url']);
    }

    public function testPingPrintsReasoningAndDescribesMediaWhenMessageIsEmpty(): void
    {
        $http = $this->catalog();
        $http->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'ok',
                    'reasoning_content' => 'A white house with a conical roof.',
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $message, array $context = [], int $options = 0) use (&$lines): void {
                unset($context, $options);
                $lines[] = $message;
            });

        $this->assertSame(0, $this->command()->pingAction('openai/default', '', 'https://cdn.example.com/a.png'));
        $body = Json::parse($http->posts[0]['body']);
        $this->assertSame('What is in this picture? One short sentence.', $body['messages'][0]['content'][0]['text']);
        $this->assertSame(['A white house with a conical roof.', '', 'ok'], $lines);
    }

    public function testListErrorsWhenTheCatalogIsEmpty(): void
    {
        $this->console->expects($this->once())
            ->method('error')
            ->with(
                'No LLM models. Configure ~/.switon/llm.yml or bind providers on {service}.',
                ['service' => LlmDiscoveryInterface::class],
                1,
            )
            ->willReturn(1);

        $this->assertSame(1, $this->command()->listAction());
    }

    public function testShowJsonPrintsOneClient(): void
    {
        $this->catalog();
        $encoded = '';
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $message, array $context = [], int $options = 0) use (&$encoded): void {
                unset($context, $options);
                $encoded = $message;
            });

        $this->assertSame(0, $this->command()->showAction('openai/default', true));
        $row = Json::parse($encoded);
        $this->assertSame('openai/default', $row['id']);
        $this->assertSame('gpt-test', $row['model']);
        $this->assertSame([], $row['extra']);
    }

    public function testShowErrorsWhenTheNameIsUnknown(): void
    {
        $this->catalog();
        $this->console->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Unknown LLM model "missing"'))
            ->willReturn(1);

        $this->assertSame(1, $this->command()->showAction('missing'));
    }

    public function testShowPrintsAClientThatIsMissingFromTheCatalogRows(): void
    {
        $llm = $this->make(OpenAi::class, [
            'httpClient' => new StubHttpClient(),
            'baseUrl' => 'http://openai.test/v1',
            'apiKey' => 'k',
            'model' => 'gpt-test',
        ]);
        $this->container->remove(LlmProvidersInterface::class);
        $this->container->set(LlmProvidersInterface::class, new class ($llm) implements LlmProvidersInterface {
            public function __construct(private LlmInterface $llm)
            {
            }

            public function add(LlmInterface $llm, array $alias = []): void
            {
            }

            public function get(string $model): LlmInterface
            {
                return $this->llm;
            }

            public function has(string $model): bool
            {
                return true;
            }

            public function all(): array
            {
                return [];
            }
        });
        $lines = [];
        $this->console->expects($this->atLeastOnce())
            ->method('writeLn')
            ->willReturnCallback(static function (string $message, array $context = [], int $options = 0) use (&$lines): void {
                unset($context, $options);
                $lines[] = $message;
            });

        $this->assertSame(0, $this->command()->showAction('gpt-test'));
        $this->assertContains('aliases:     ', $lines);
        $this->assertContains('baseUrl:     http://openai.test/v1', $lines);
    }

    public function testPingRefusesImageWhenTheCatalogFlagIsOff(): void
    {
        $http = new StubHttpClient();
        $this->container->set(HttpClientInterface::class, $http);
        $this->container->replace(LlmDiscoveryInterface::class, [
            'class' => LlmDiscovery::class,
            'file' => null,
            'providers' => [
                'openai' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'sk-test',
                    'baseUrl' => 'http://openai.test/v1',
                    'models' => [
                        'default' => ['model' => 'gpt-test', 'image' => false],
                    ],
                ],
            ],
        ]);
        $this->container->get(LlmDiscoveryInterface::class)->discover();
        $this->console->expects($this->once())
            ->method('error')
            ->with('{id} does not accept images.', ['id' => 'openai/default'], 1)
            ->willReturn(1);

        $code = $this->command()->pingAction('openai/default', 'hi', 'https://cdn.example.com/a.png');

        $this->assertSame(1, $code);
        $this->assertSame([], $http->posts);
    }

    public function testPingDescribesImageAndVideoWhenMessageIsEmpty(): void
    {
        $http = $this->ready('https://cdn.example.com/a.png', 'https://cdn.example.com/a.mp4');

        $body = Json::parse($http->posts[0]['body']);
        $this->assertSame(
            'Describe the image and the video in one short sentence each.',
            $body['messages'][0]['content'][0]['text'],
        );
    }

    public function testPingDescribesVideoWhenMessageIsEmpty(): void
    {
        $http = $this->ready('', 'https://cdn.example.com/a.webm');

        $body = Json::parse($http->posts[0]['body']);
        $this->assertSame('What happens in this video? One short sentence.', $body['messages'][0]['content'][0]['text']);
        $this->assertStringContainsString('https://cdn.example.com/a.webm', $http->posts[0]['body']);
    }

    public function testPingAcceptsEachMediaExtension(): void
    {
        $http = $this->catalog();
        $http->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->console->expects($this->atLeastOnce())->method('writeLn');

        $this->assertSame(0, $this->command()->pingAction('openai/default', 'look', 'https://cdn.example.com/a.gif'));
        $this->assertSame(0, $this->command()->pingAction('openai/default', 'look', 'https://cdn.example.com/a.webp'));
        $this->assertSame(0, $this->command()->pingAction('openai/default', 'look', 'https://cdn.example.com/a'));
        $http->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);
        $this->assertSame(0, $this->command()->pingAction('minimax/M3', 'look', '', 'https://cdn.example.com/a.mov'));
        $this->assertSame(0, $this->command()->pingAction('minimax/M3', 'look', '', 'https://cdn.example.com/clip'));

        $this->assertStringContainsString('https://cdn.example.com/a.gif', $http->posts[0]['body']);
        $this->assertStringContainsString('https://cdn.example.com/a.webp', $http->posts[1]['body']);
        $this->assertStringContainsString('https://cdn.example.com/a', $http->posts[2]['body']);
        $this->assertStringContainsString('https://cdn.example.com/a.mov', $http->posts[3]['body']);
        $this->assertStringContainsString('https://cdn.example.com/clip', $http->posts[4]['body']);
    }

    public function testPingReportsAuthenticationFailure(): void
    {
        $curl = new StubCurl();
        $curl->statusCode = 401;
        $curl->responseBody = '{"error":{"message":"nope"}}';
        $this->container->set(HttpClientInterface::class, $this->make(HttpClient::class, ['curl' => $curl]));
        $this->container->replace(LlmDiscoveryInterface::class, [
            'class' => LlmDiscovery::class,
            'file' => null,
            'providers' => [
                'openai' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'sk-test',
                    'baseUrl' => 'http://openai.test/v1',
                    'models' => [
                        'default' => ['model' => 'gpt-test'],
                    ],
                ],
            ],
        ]);
        $this->container->get(LlmDiscoveryInterface::class)->discover();
        $this->console->expects($this->once())
            ->method('error')
            ->with('LLM API authentication failed.')
            ->willReturn(1);

        $this->assertSame(1, $this->command()->pingAction());
    }

    public function testPingReportsABrokenReply(): void
    {
        $http = $this->catalog();
        $http->responseBody = 'not-json';
        $this->console->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Invalid LLM JSON response'))
            ->willReturn(1);

        $this->assertSame(1, $this->command()->pingAction());
    }

    public function testPingErrorsWhenTheReplyIsEmpty(): void
    {
        $http = $this->catalog();
        $http->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => ''],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->console->expects($this->once())
            ->method('error')
            ->with('Empty reply from {id}.', ['id' => 'openai/default'], 1)
            ->willReturn(1);

        $this->assertSame(1, $this->command()->pingAction());
    }

    public function testPingErrorsWhenTheNameIsUnknown(): void
    {
        $this->catalog();
        $this->console->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Unknown LLM model "missing"'))
            ->willReturn(1);

        $this->assertSame(1, $this->command()->pingAction('missing'));
    }

    protected function ready(string $image, string $video): StubHttpClient
    {
        $http = $this->catalog();
        $http->responseBody = (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
        ], JSON_THROW_ON_ERROR);
        $this->console->expects($this->atLeastOnce())->method('writeLn');
        $this->assertSame(0, $this->command()->pingAction('minimax/M3', '', $image, $video));

        return $http;
    }

    protected function command(): LlmCommand
    {
        return $this->container->get(LlmCommand::class);
    }

    protected function catalog(): StubHttpClient
    {
        $http = new StubHttpClient();
        $this->container->set(HttpClientInterface::class, $http);
        $this->container->replace(LlmDiscoveryInterface::class, [
            'class' => LlmDiscovery::class,
            'file' => null,
            'providers' => [
                'openai' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'sk-test',
                    'baseUrl' => 'http://openai.test/v1',
                    'models' => [
                        'default' => [
                            'model' => 'gpt-test',
                            'alias' => 'main',
                            'image' => true,
                            'video' => false,
                        ],
                    ],
                ],
                'minimax' => [
                    'class' => Anthropic::class,
                    'apiKey' => 'sk-test',
                    'baseUrl' => 'https://api.minimaxi.com/anthropic/v1',
                    'models' => [
                        'M3' => [
                            'model' => 'MiniMax-M3',
                            'alias' => 'best',
                            'image' => true,
                            'video' => true,
                            'efforts' => ['low', 'high'],
                        ],
                    ],
                ],
            ],
            'aliases' => [],
        ]);
        $this->container->get(LlmDiscoveryInterface::class)->discover();
        $this->container->get(LlmProvidersInterface::class);

        return $http;
    }
}
