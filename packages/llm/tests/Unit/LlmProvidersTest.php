<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Llm\Anthropic;
use Switon\Llm\Exception\ConfigurationException;
use Switon\Llm\Http\HttpClientInterface;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProviders;
use Switon\Llm\LlmProvidersInterface;
use Switon\Llm\OpenAi;
use Switon\Llm\Tests\TestCase;
use Switon\Llm\Tests\Unit\Fixtures\StubHttpClient;

#[CoversClass(LlmProviders::class)]
#[CoversClass(LlmDiscovery::class)]
class LlmProvidersTest extends TestCase
{
    public function testGetMainFromUserConfig(): void
    {
        $home = $this->prepareHome([
            '.switon/llm.yml' => <<<'YAML'
providers:
  openai:
    description: OpenAI-compatible endpoint
    apiKey: 'sk-test'
    baseUrl: 'http://openai.test/v1'
    models:
      default:
        model: 'gpt-test'
aliases:
  main: openai/default
YAML,
        ]);
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover(['file' => $home . '/.switon/llm.yml']);
        $llm = $providers->get('main');
        $this->assertInstanceOf(OpenAi::class, $llm);
        $this->assertTrue($providers->has('main'));
        $this->assertTrue($providers->has('openai/default'));
        $this->assertSame('openai/default', $providers->get('main')->model()->id);
        $this->assertSame($llm, $providers->get('main'));
        $this->assertSame('openai/default', $providers->get('')->model()->id);
        $this->assertSame('openai/default', $providers->get('openai/default')->model()->id);
        $this->assertSame(['openai/default', 'main'], array_keys($providers->all()));
    }

    public function testGetNamedVendorModel(): void
    {
        $http = $this->stubHttp();
        $llm = $this->discover([
            'providers' => [
                'bigmodel' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'shared-key',
                    'baseUrl' => 'https://glm.example/v1',
                    'models' => [
                        'glm-flash' => [
                            'model' => 'glm-4-flash',
                            'extra' => ['temperature' => 0.2],
                            'window' => '128k',
                        ],
                    ],
                ],
            ],
        ])->get('bigmodel/glm-flash');
        $this->assertInstanceOf(OpenAi::class, $llm);

        $record = $llm->model();
        $this->assertSame('glm-4-flash', $record->model);
        $this->assertSame(['temperature' => 0.2], $record->extra);
        $this->assertSame(128_000, $record->window);
        $this->assertSame('Bearer shared-key', $this->posted($http, $llm)['headers']['Authorization']);
    }

    public function testDefaultTimeoutComesFromProviders(): void
    {
        $http = $this->stubHttp();
        $llm = $this->discover([
            'timeout' => 300,
            'providers' => [
                'local' => [
                    'apiKey' => 'k',
                    'baseUrl' => 'http://local.test/v1',
                    'models' => [
                        'chat' => ['model' => 'llama'],
                    ],
                ],
            ],
        ])->get('local/chat');
        $this->assertSame(300, $this->posted($http, $llm)['timeout']);
    }

    public function testMissingApiKeyIsSkipped(): void
    {
        $http = $this->stubHttp();
        $llm = $this->discover([
            'providers' => [
                'local' => [
                    'baseUrl' => 'http://local.test/v1',
                    'models' => [
                        'chat' => ['model' => 'llama'],
                    ],
                ],
            ],
        ])->get('local/chat');
        $this->assertArrayNotHasKey('Authorization', $this->posted($http, $llm)['headers']);

        foreach (['', null] as $apiKey) {
            $cleared = $this->discover([
                'providers' => [
                    'local' => [
                        'apiKey' => $apiKey,
                        'baseUrl' => 'http://local.test/v1',
                        'models' => [
                            'chat' => ['model' => 'llama'],
                        ],
                    ],
                ],
            ])->get('local/chat');
            $this->assertArrayNotHasKey('Authorization', $this->posted($http, $cleared)['headers']);
        }
    }

    public function testClassDefaultsToOpenAi(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $llm = $this->discover([
            'providers' => [
                'local' => [
                    'apiKey' => 'k',
                    'baseUrl' => 'http://local.test/v1',
                    'models' => [
                        'chat' => ['model' => 'llama'],
                    ],
                ],
            ],
        ])->get('local/chat');
        $this->assertInstanceOf(OpenAi::class, $llm);
    }

    public function testShortModelKeyIsNotARoute(): void
    {
        $http = $this->stubHttp();
        $providers = $this->discover([
            'providers' => [
                'bigmodel' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'k1',
                    'baseUrl' => 'https://first.example/v1',
                    'models' => [
                        'glm-flash' => ['model' => 'glm-4-flash'],
                    ],
                ],
                'openrouter' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'k2',
                    'baseUrl' => 'https://second.example/v1',
                    'models' => [
                        'glm-flash' => ['model' => 'z-ai/glm-flash'],
                    ],
                ],
            ],
        ]);
        $llm = $providers->get('bigmodel/glm-flash');
        $this->assertInstanceOf(OpenAi::class, $llm);
        $this->assertSame('bigmodel/glm-flash', $llm->model()->id);
        $this->assertFalse($providers->has('glm-flash'));
        $this->assertSame('Bearer k1', $this->posted($http, $llm)['headers']['Authorization']);
    }

    public function testCatalogListsFullPaths(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'bigmodel' => [
                    'description' => 'Fast GLM chat',
                    'class' => OpenAi::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://glm.example/v1',
                    'models' => [
                        'glm-flash' => ['model' => 'glm-4-flash', 'description' => 'Flash tier'],
                    ],
                ],
            ],
        ]);
        $catalog = $providers->all();
        $this->assertSame(['bigmodel/glm-flash' => $providers->get('bigmodel/glm-flash')], $catalog);
        $record = $catalog['bigmodel/glm-flash']->model();
        $this->assertSame('bigmodel/glm-flash', $record->id);
        $this->assertSame('glm-4-flash', $record->model);
        $this->assertSame('Flash tier', $record->description);
        $this->assertTrue($record->image);
        $this->assertFalse($record->video);
        $this->assertNull($record->efforts);
        $record = $providers->get('bigmodel/glm-flash')->model();
        $this->assertSame('bigmodel/glm-flash', $record->id);
        $this->assertSame('Flash tier', $record->description);
        $this->assertTrue($record->image);
        $this->assertFalse($record->video);
    }

    public function testDescribeImageFromModelEntry(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'minimax' => [
                    'description' => 'MiniMax',
                    'class' => Anthropic::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.minimaxi.com/anthropic/v1',
                    'models' => [
                        'm3' => ['model' => 'MiniMax-M3', 'image' => true, 'video' => true],
                        'm27' => ['model' => 'MiniMax-M2.7', 'image' => false, 'video' => false],
                    ],
                ],
            ],
        ]);
        $this->assertTrue($providers->get('minimax/m3')->model()->image);
        $this->assertTrue($providers->get('minimax/m3')->model()->video);
        $this->assertFalse($providers->get('minimax/m27')->model()->image);
        $this->assertFalse($providers->get('minimax/m27')->model()->video);
    }

    public function testDescribeImageDefaultsTrueWithoutConfig(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'openai' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.openai.com/v1',
                    'models' => [
                        'vision' => ['model' => 'gpt-4o'],
                        'text' => ['model' => 'gpt-3.5-turbo'],
                    ],
                ],
            ],
        ]);
        $this->assertTrue($providers->get('openai/vision')->model()->image);
        $this->assertTrue($providers->get('openai/text')->model()->image);
        $this->assertFalse($providers->get('openai/vision')->model()->video);
    }

    public function testEmptyCatalogHasNoDefault(): void
    {
        $this->assertFalse($this->container->get(LlmProvidersInterface::class)->has(''));
    }

    public function testGetRejectsUnknownModel(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown LLM model');
        $this->container->get(LlmProvidersInterface::class)->get('missing');
    }

    public function testEmptyNameIsFirstClient(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'minimax' => [
                    'description' => 'MiniMax',
                    'class' => Anthropic::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.minimaxi.com/anthropic/v1',
                    'models' => [
                        'M27' => ['model' => 'MiniMax-M2.7', 'image' => false],
                        'M3' => ['model' => 'MiniMax-M3', 'image' => true],
                    ],
                ],
            ],
        ]);
        $this->assertFalse($providers->has('fast'));
        $this->assertFalse($providers->has('best'));
        $this->assertSame('minimax/M27', $providers->get('')->model()->id);
    }

    public function testEmptyNameSkipsUnregisteredAlias(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'local' => [
                    'class' => Anthropic::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://example.test/v1',
                    'models' => ['default' => ['model' => 'local-model']],
                ],
                'minimax' => [
                    'class' => Anthropic::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.minimaxi.com/anthropic/v1',
                    'models' => ['M27' => ['model' => 'MiniMax-M2.7']],
                ],
            ],
            'aliases' => ['main' => 'minimax/M27'],
        ]);
        $this->assertSame('local/default', $providers->get('')->model()->id);
        $this->assertFalse($providers->has('fast'));
        $this->assertFalse($providers->has('best'));
        $this->assertSame('minimax/M27', $providers->get('main')->model()->id);
    }

    public function testUnregisteredNameIsAbsent(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'minimax' => [
                    'class' => Anthropic::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.minimaxi.com/anthropic/v1',
                    'models' => ['M3' => ['model' => 'MiniMax-M3']],
                ],
            ],
            'aliases' => ['main' => 'minimax/M3'],
        ]);
        $this->assertTrue($providers->has('main'));
        $this->assertFalse($providers->has('auto'));
    }

    public function testAliasesResolveBeforeCatalog(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'minimax' => [
                    'class' => Anthropic::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.minimaxi.com/anthropic/v1',
                    'models' => [
                        'M27' => ['model' => 'MiniMax-M2.7'],
                        'M3' => ['model' => 'MiniMax-M3', 'image' => true],
                    ],
                ],
            ],
            'aliases' => [
                'fast' => 'minimax/M27',
                'best' => 'minimax/M3',
            ],
        ]);
        $this->assertSame('minimax/M27', $providers->get('fast')->model()->id);
        $this->assertSame('minimax/M27', $providers->get('fast')->model()->id);
        $this->assertSame('minimax/M3', $providers->get('best')->model()->id);
        $this->assertTrue($providers->get('best')->model()->image);
    }

    public function testRouteKeepsSlashInsideModelKey(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover([
            'providers' => [
                'vllm' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'local',
                    'baseUrl' => 'http://10.0.0.8:8000/v1',
                    'models' => [
                        'Qwen/Qwen2.5-72B-Instruct' => [
                            'model' => 'Qwen/Qwen2.5-72B-Instruct',
                        ],
                    ],
                ],
            ],
            'aliases' => ['main' => 'vllm/Qwen/Qwen2.5-72B-Instruct'],
        ]);
        $this->assertSame('vllm/Qwen/Qwen2.5-72B-Instruct', $providers->get('main')->model()->id);
        $llm = $providers->get('main');
        $this->assertSame('Qwen/Qwen2.5-72B-Instruct', $llm->model()->model);
    }

    public function testUserConfigFileReplacesProvidersAndAliases(): void
    {
        $home = $this->prepareHome([
            '.switon/llm.yml' => <<<'YAML'
providers:
  minimax:
    description: MiniMax
    class: Switon\Llm\Anthropic
    apiKey: 'token-mm'
    baseUrl: 'https://api.minimaxi.com/anthropic/v1'
    models:
      M27:
        model: 'MiniMax-M2.7'
        image: false
      M3:
        model: 'MiniMax-M3'
        image: true
aliases:
  main: minimax/M27
  fast: minimax/M27
  best: minimax/M3
YAML,
        ]);
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover(['file' => $home . '/.switon/llm.yml']);
        $this->assertTrue($providers->has('minimax/M27'));
        $this->assertTrue($providers->has('minimax/M3'));
        $this->assertSame('minimax/M27', $providers->get('fast')->model()->id);
        $this->assertSame('minimax/M3', $providers->get('best')->model()->id);

        $this->assertSame(
            ['minimax/M27', 'minimax/M3', 'main', 'fast', 'best'],
            array_keys($providers->all()),
        );
    }

    public function testGetDefaultsPassbackReasoningTrueForDeepSeek(): void
    {
        $http = $this->stubHttp();
        $providers = $this->discover([
            'providers' => [
                'deepseek' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.deepseek.com',
                    'models' => [
                        'flash' => ['model' => 'deepseek-flash'],
                    ],
                ],
                'openai' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.openai.com/v1',
                    'models' => [
                        'default' => ['model' => 'gpt-4o-mini'],
                    ],
                ],
            ],
        ]);
        $deepseek = $this->closedTurnBody($http, $providers->get('deepseek/flash'));
        $this->assertSame('Think first.', $deepseek['messages'][1]['reasoning_content']);
        $openai = $this->closedTurnBody($http, $providers->get('openai/default'));
        $this->assertArrayNotHasKey('reasoning_content', $openai['messages'][1]);
    }

    public function testGetAcceptsSnakeCaseClientKeysAndPreservesExtra(): void
    {
        $http = $this->stubHttp();
        $llm = $this->discover([
            'providers' => [
                'openai' => [
                    'class' => OpenAi::class,
                    'api_key' => 'snake-key',
                    'base_url' => 'https://api.openai.com/v1',
                    'models' => [
                        'default' => [
                            'model' => 'gpt-4o-mini',
                            'passback_reasoning' => false,
                            'extra' => [
                                'reasoning_effort' => 'medium',
                                'max_tokens' => 100,
                            ],
                        ],
                    ],
                ],
            ],
        ])->get('openai/default');
        $post = $this->openTurnPost($http, $llm);
        $body = json_decode($post['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Bearer snake-key', $post['headers']['Authorization']);
        $this->assertSame('https://api.openai.com/v1', $llm->baseUrl());
        $this->assertSame('https://api.openai.com/v1/chat/completions', $post['url']);
        $this->assertArrayNotHasKey('reasoning_content', $body['messages'][1]);
        $this->assertSame(
            ['reasoning_effort' => 'medium', 'max_tokens' => 100],
            $llm->model()->extra,
        );
    }

    public function testCatalogEffortIsStrippedFromTheClient(): void
    {
        $http = $this->stubHttp();
        $providers = $this->discover([
            'providers' => [
                'deepseek' => [
                    'class' => OpenAi::class,
                    'apiKey' => 'k',
                    'baseUrl' => 'https://api.deepseek.com/v1',
                    'models' => [
                        'flash' => [
                            'model' => 'deepseek-flash',
                            'timeout' => 180,
                            'efforts' => ['low', 'high', 'low', ''],
                        ],
                        'plain' => ['model' => 'deepseek-chat'],
                    ],
                ],
            ],
        ]);
        $catalog = $providers->all();
        $this->assertSame(['low', 'high'], $catalog['deepseek/flash']->model()->efforts);
        $this->assertNull($catalog['deepseek/plain']->model()->efforts);

        $llm = $providers->get('deepseek/flash');
        $this->assertSame([], $llm->model()->extra);
        $this->assertSame(180, $this->posted($http, $llm)['timeout']);
    }

    public function testFlatFileReadsAliasOnTheModel(): void
    {
        $home = $this->prepareHome([
            '.switon/llm.yml' => <<<'YAML'
vllm:
  apiKey: 'local'
  baseUrl: 'http://127.0.0.1:8000/v1'
  models:
    qwen72:
      model: 'Qwen/Qwen2.5-72B-Instruct'
      alias: [main, fast]
    coder:
      model: 'qwen2.5-coder'
      alias: best
YAML,
        ]);
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover(['file' => $home . '/.switon/llm.yml']);
        $this->assertSame('vllm/qwen72', $providers->get('main')->model()->id);
        $this->assertSame('vllm/qwen72', $providers->get('fast')->model()->id);
        $this->assertSame('vllm/coder', $providers->get('best')->model()->id);
        $this->assertSame('vllm/qwen72', $providers->get('vllm/qwen72')->model()->id);
        $this->assertFalse($providers->has('missing'));
    }

    public function testDuplicateAliasKeepsTheFirstModel(): void
    {
        $home = $this->prepareHome([
            '.switon/llm.yml' => <<<'YAML'
vllm:
  apiKey: 'local'
  baseUrl: 'http://127.0.0.1:8000/v1'
  models:
    qwen72:
      model: 'Qwen/Qwen2.5-72B-Instruct'
      alias: main
    coder:
      model: 'qwen2.5-coder'
      alias: [main, fast]
YAML,
        ]);
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover(['file' => $home . '/.switon/llm.yml']);
        $this->assertSame('vllm/qwen72', $providers->get('main')->model()->id);
        $this->assertSame('vllm/coder', $providers->get('fast')->model()->id);
    }

    public function testTopLevelAliasUsesTheModelKeyAndSkipsTheRest(): void
    {
        $home = $this->prepareHome([
            '.switon/llm.yml' => <<<'YAML'
openai:
  apiKey: 'k'
  baseUrl: 'http://local.test/v1'
  models:
    chat:
      model: 'llama'
      alias: main
note: 1
aliases:
  main: other/nope
  fast: chat
  missing: nope/id
  also: nope
YAML,
        ]);
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $providers = $this->discover(['file' => $home . '/.switon/llm.yml']);

        $this->assertSame('openai/chat', $providers->get('main')->model()->id);
        $this->assertSame('openai/chat', $providers->get('fast')->model()->id);
        $this->assertFalse($providers->has('missing'));
        $this->assertFalse($providers->has('also'));
    }

    public function testNullModelEntryKeepsAnEmptyWireName(): void
    {
        $home = $this->prepareHome([
            '.switon/llm.yml' => <<<'YAML'
openai:
  apiKey: 'k'
  baseUrl: 'http://local.test/v1'
  models:
    chat:
YAML,
        ]);
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $llm = $this->discover(['file' => $home . '/.switon/llm.yml'])->get('openai/chat');

        $this->assertSame('', $llm->model()->model);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidCatalogProvider(): array
    {
        return [
            'provider list' => [
                "providers:\n  - broken\n",
                'keys must be strings',
            ],
            'models scalar' => [
                "openai:\n  models: hello\n",
                'models must be a mapping',
            ],
            'model sequence' => [
                "openai:\n  models:\n    - llama\n",
                'model keys must be non-empty strings',
            ],
            'model scalar' => [
                "openai:\n  models:\n    chat: llama\n",
                'must be a mapping',
            ],
            'alias target' => [
                "aliases:\n  main: 1\n",
                'Invalid LLM alias',
            ],
            'empty alias target' => [
                "aliases:\n  main: ''\n",
                'Invalid LLM alias',
            ],
        ];
    }

    #[DataProvider('invalidCatalogProvider')]
    public function testDiscoverRejectsABadCatalog(string $yaml, string $message): void
    {
        $home = $this->prepareHome(['.switon/llm.yml' => $yaml]);
        $this->container->set(HttpClientInterface::class, new StubHttpClient());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->discover(['file' => $home . '/.switon/llm.yml']);
    }

    protected function stubHttp(): StubHttpClient
    {
        $http = new StubHttpClient();
        $http->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->container->set(HttpClientInterface::class, $http);

        return $http;
    }

    /**
     * @return array{url: string, headers: array<string, string>, body: string, timeout: int}
     */
    protected function posted(StubHttpClient $http, LlmInterface $llm, ?Thread $thread = null): array
    {
        $llm->complete(
            $this->hang($thread ?? new Thread('t'), Completion::of(UserMessage::of('Hi'))),
            Charter::of(),
        );

        return $http->posts[array_key_last($http->posts)];
    }

    /** @return array<string, mixed> */
    protected function closedTurnBody(StubHttpClient $http, LlmInterface $llm): array
    {
        $previous = Completion::of(
            UserMessage::of('Q'),
            new AssistantMessage('Answer.', reasoning: 'Think first.'),
        );
        $thread = new Thread('t');
        $thread->turns[] = Turn::of('prev', [$previous], TurnStatus::Completed, $llm->model()->id);

        return json_decode($this->posted($http, $llm, $thread)['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Previous assistant stays on the open turn, so a null passback would still echo reasoning.
     *
     * @return array{url: string, headers: array<string, string>, body: string, timeout: int}
     */
    protected function openTurnPost(StubHttpClient $http, LlmInterface $llm): array
    {
        $previous = Completion::of(
            UserMessage::of('Q'),
            new AssistantMessage('Answer.', reasoning: 'Think first.'),
        );
        $thread = new Thread('t');
        $this->hang($thread, $previous);

        return $this->posted($http, $llm, $thread);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function discover(array $config): LlmProvidersInterface
    {
        $config['class'] = LlmDiscovery::class;
        $config['file'] = $config['file'] ?? null;
        $this->container->replace(LlmDiscoveryInterface::class, $config);
        $this->container->get(LlmDiscoveryInterface::class)->discover();

        return $this->container->get(LlmProvidersInterface::class);
    }

    /** @param array<string, array<string, mixed>|string> $files relative to HOME */
    protected function prepareHome(array $files): string
    {
        $home = sys_get_temp_dir() . '/llm-providers-home-' . uniqid('', true);
        foreach ($files as $relativePath => $payload) {
            $path = $home . '/' . $relativePath;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            if (is_array($payload)) {
                file_put_contents($path, (string) json_encode($payload, JSON_THROW_ON_ERROR));
            } else {
                file_put_contents($path, $payload);
            }
        }

        putenv('HOME=' . $home);
        $_ENV['HOME'] = $home;

        return $home;
    }
}
