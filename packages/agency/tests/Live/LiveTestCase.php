<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live;

use Switon\Agency\Tests\TestCase;
use Switon\Llm\Anthropic;
use Switon\Ai\LlmInterface;

require_once dirname(__DIR__) . '/manual/live_bootstrap.php';

/**
 * Base case for agent integration tests that call a configured LLM.
 *
 * Skips when `~/.claude/settings.json` is missing or has no auth token.
 */
abstract class LiveTestCase extends TestCase
{
    protected function setUp(): void
    {
        $this->requireLiveLlm();
        liveEnableCoroutineRuntime();

        parent::setUp();
    }

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        liveBindLlm($this->container);
    }

    protected function requireLiveLlm(): void
    {
        $path = (getenv('HOME') ?: '') . '/.claude/settings.json';
        if (!is_file($path)) {
            $this->markTestSkipped('Claude Code settings missing at ~/.claude/settings.json');
        }

        $settings = json_decode((string) file_get_contents($path), true);
        if (!is_array($settings)) {
            $this->markTestSkipped('Invalid Claude Code settings JSON.');
        }

        $env = is_array($settings['env'] ?? null) ? $settings['env'] : [];
        $token = (string) ($env['ANTHROPIC_AUTH_TOKEN'] ?? '');
        if ($token === '') {
            $this->markTestSkipped('ANTHROPIC_AUTH_TOKEN not configured in ~/.claude/settings.json');
        }
    }

    protected function assertLiveLlmBound(): void
    {
        $llm = $this->container->get(LlmInterface::class);
        $this->assertInstanceOf(Anthropic::class, $llm);
    }
}
