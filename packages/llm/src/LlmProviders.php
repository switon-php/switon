<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\LlmInterface;
use Switon\Llm\Exception\ConfigurationException;

use function array_key_first;

/**
 * Default {@see LlmProvidersInterface}: one name → client map.
 * An empty name is the first catalog model.
 *
 * @see \Switon\Llm\LlmProvidersInterface
 */
class LlmProviders implements LlmProvidersInterface
{
    /** @var array<string, LlmInterface> route or alias → client */
    protected array $llms = [];

    public function add(LlmInterface $llm, array $alias = []): void
    {
        $this->llms[$llm->model()->id] = $llm;
        foreach ($alias as $name) {
            $this->llms[$name] = $llm;
        }
    }

    public function get(string $model): LlmInterface
    {
        if ($model === '') {
            $model = array_key_first($this->llms) ?? '';
        }
        if (isset($this->llms[$model])) {
            return $this->llms[$model];
        }

        ConfigurationException::raise(
            'Unknown LLM model "{model}". Configure ~/.switon/llm.yml or bind providers on {service}.',
            [
                'model' => $model,
                'service' => LlmDiscoveryInterface::class,
            ],
        );
    }

    public function has(string $model): bool
    {
        if ($model === '') {
            return $this->llms !== [];
        }

        return isset($this->llms[$model]);
    }

    public function all(): array
    {
        return $this->llms;
    }
}
