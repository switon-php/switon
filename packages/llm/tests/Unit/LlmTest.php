<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Llm\Http\HttpClientInterface;
use Switon\Llm\Llm;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProvidersInterface;
use Switon\Llm\OpenAi;
use Switon\Llm\Tests\TestCase;
use Switon\Llm\Tests\Unit\Fixtures\StubHttpClient;

#[CoversClass(Llm::class)]
class LlmTest extends TestCase
{
    public function testResolvesOpenAiFromUserConfig(): void
    {
        $home = $this->prepareHome([
            '.switon/llm.yml' => <<<'YAML'
providers:
  openai:
    apiKey: 'sk-test'
    baseUrl: 'http://openai.test/v1'
    models:
      default:
        model: 'gpt-test'
YAML,
        ]);
        $http = new StubHttpClient();
        $http->responseBody = (string) json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->container->set(HttpClientInterface::class, $http);
        $this->container->replace(LlmDiscoveryInterface::class, [
            'class' => LlmDiscovery::class,
            'file' => $home . '/.switon/llm.yml',
        ]);
        $this->container->get(LlmDiscoveryInterface::class)->discover();

        $chat = $this->container->get(LlmInterface::class);
        $this->assertInstanceOf(OpenAi::class, $chat);
        $chat->complete($this->hang(new Thread('test'), Completion::of(UserMessage::of('Hi'))), Charter::of());

        $this->assertSame('http://openai.test/v1/chat/completions', $http->posts[0]['url']);
    }

    public function testDiscoverSkipsMissingConfigFile(): void
    {
        $this->container->set(HttpClientInterface::class, new StubHttpClient());
        $this->container->replace(LlmDiscoveryInterface::class, [
            'class' => LlmDiscovery::class,
            'file' => sys_get_temp_dir() . '/llm-missing-' . uniqid('', true) . '/llm.yml',
        ]);

        $this->container->get(LlmDiscoveryInterface::class)->discover();

        $this->assertFalse($this->container->get(LlmProvidersInterface::class)->has('openai/default'));
    }

    /** @param array<string, array<string, mixed>|string> $files relative to HOME */
    protected function prepareHome(array $files): string
    {
        $home = sys_get_temp_dir() . '/llm-home-' . uniqid('', true);
        foreach ($files as $relativePath => $payload) {
            $path = $home . '/' . $relativePath;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            if (is_string($payload)) {
                file_put_contents($path, $payload);
            } else {
                file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
            }
        }

        putenv('HOME=' . $home);

        return $home;
    }
}
