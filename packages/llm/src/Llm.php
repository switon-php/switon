<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\LlmInterface;

/** DI factory for the default {@see LlmInterface} (first catalog client). */
class Llm
{
    public function __invoke(LlmProvidersInterface $providers): LlmInterface
    {
        return $providers->get('');
    }
}
