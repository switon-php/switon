<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval;

use Switon\Agency\Tests\TestCase;
use Switon\Llm\LlmProvidersInterface;
use Throwable;

require_once dirname(__DIR__) . '/manual/live_bootstrap.php';

/** Base for eval suites that call a real LLM via {@see LlmProvidersInterface} (`~/.switon/llm.yml`). */
abstract class EvalLiveTestCase extends TestCase
{
    protected function setUp(): void
    {
        liveEnableCoroutineRuntime();
        parent::setUp();
    }

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        try {
            liveBindLlm($this->container);
            $this->container->get(LlmProvidersInterface::class)->get('main');
        } catch (Throwable) {
            $this->markTestSkipped('No ~/.switon/llm.yml for eval live tests (see packages/llm/resources/llm.yml.example).');
        }
    }
}
