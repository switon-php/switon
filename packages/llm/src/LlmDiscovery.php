<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\LlmInterface;
use Switon\Ai\Model;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\MakerInterface;
use Switon\Core\Naming;
use Switon\Llm\Exception\ConfigurationException;
use Switon\Yaml\YamlReaderInterface;

use function array_key_exists;
use function get_debug_type;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function strtolower;
use function trim;

/**
 * Default {@see LlmDiscoveryInterface}: YAML file or DI `providers` / `aliases` → {@see LlmProvidersInterface::add()}.
 *
 * @see \Switon\Llm\LlmDiscoveryInterface
 */
class LlmDiscovery implements LlmDiscoveryInterface
{
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected YamlReaderInterface $yamlReader;
    #[Autowired] protected LlmProvidersInterface $llmProviders;

    #[Autowired] public int $timeout = 60;
    /** Non-null path loads the catalog. A missing file is skipped. `null` uses DI providers only. */
    #[Autowired] protected ?string $file = '~/.switon/llm.yml';
    /** @var array<string, string> */
    #[Autowired] protected array $aliases = [];
    /** @var array<string, array<string, mixed>> */
    #[Autowired] protected array $providers = [];

    public function discover(): void
    {
        if ($this->file !== null) {
            try {
                $this->readFile();
            } catch (FileNotFoundException) {
                // Missing catalog file: leave providers and aliases from DI.
            }
        }

        /** @var array<string, LlmInterface> $made */
        $made = [];
        /** @var array<string, true> $claimed */
        $claimed = [];
        foreach ($this->providers as $providerName => $provider) {
            foreach ($provider['models'] as $modelKey => $modelEntry) {
                $llm = $this->makeLlm($providerName, $provider, $modelKey, $modelEntry);
                $made[$llm->model()->id] = $llm;
                $names = [];
                foreach ($this->aliasNames($modelEntry) as $name) {
                    if (isset($claimed[$name])) {
                        continue;
                    }
                    $claimed[$name] = true;
                    $names[] = $name;
                }
                $this->llmProviders->add($llm, $names);
            }
        }

        foreach ($this->aliases as $name => $target) {
            if (isset($claimed[$name])) {
                continue;
            }
            $llm = $made[$target] ?? $this->llmByShortKey($made, $target);
            if ($llm === null) {
                continue;
            }
            $this->llmProviders->add($llm, [$name]);
        }
    }

    /**
     * @param array<string, LlmInterface> $made
     */
    protected function llmByShortKey(array $made, string $target): ?LlmInterface
    {
        if (str_contains($target, '/')) {
            return null;
        }
        foreach ($made as $id => $llm) {
            $key = str_contains($id, '/') ? explode('/', $id, 2)[1] : $id;
            if ($key === $target) {
                return $llm;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $modelEntry
     */
    protected function makeLlm(string $providerName, array $provider, string $modelKey, array $modelEntry): LlmInterface
    {
        $wire = $modelEntry['model'] ?? '';
        $window = $modelEntry['window'] ?? 128_000;
        if (is_string($window) && str_ends_with($window, 'k')) {
            $window = (int)rtrim($window, 'k') * 1_000;
        }

        $config = [
            'baseUrl' => $this->clientField($provider, 'baseUrl') ?? '',
            'timeout' => $this->clientField($modelEntry, 'timeout')
                ?? $this->clientField($provider, 'timeout')
                    ?? $this->timeout,
            'passbackReasoning' => $this->clientField($modelEntry, 'passbackReasoning')
                ?? $this->clientField($provider, 'passbackReasoning'),
        ];
        if ($this->hasClientField($provider, 'apiKey')) {
            $apiKey = $this->clientField($provider, 'apiKey');
            $config['apiKey'] = is_string($apiKey) ? $apiKey : '';
        }
        if ($config['passbackReasoning'] === null && $this->looksLikeDeepSeek($providerName, $config, $wire)) {
            $config['passbackReasoning'] = true;
        }
        $class = $provider['class'] ?? OpenAi::class;
        $config['model'] = Model::of(
            $providerName . '/' . $modelKey,
            $wire,
            $window,
            $modelEntry['image'] ?? true,
            $modelEntry['video'] ?? false,
            $this->effortsOf($modelEntry),
            $modelEntry['extra'] ?? [],
            trim($modelEntry['description'] ?? ''),
        );

        $llm = $this->maker->make($class, $config);
        if (!$llm instanceof LlmInterface) {
            ConfigurationException::raise('LLM class "{class}" does not implement {interface}.', [
                'class' => is_string($class) ? $class : get_debug_type($class),
                'interface' => LlmInterface::class,
            ]);
        }

        return $llm;
    }

    /** @param array<string, mixed> $config */
    protected function looksLikeDeepSeek(string $providerName, array $config, string $wire): bool
    {
        $haystack = strtolower($providerName . ' ' . ($config['baseUrl'] ?? '') . ' ' . $wire);

        return str_contains($haystack, 'deepseek');
    }

    /**
     * @param array<string, mixed> $modelEntry
     *
     * @return list<string>|null
     */
    protected function effortsOf(array $modelEntry): ?array
    {
        $efforts = $modelEntry['efforts'] ?? null;
        if ($efforts === null) {
            return null;
        }

        $levels = [];
        foreach ($efforts as $level) {
            $level = trim((string)$level);
            if ($level === '' || in_array($level, $levels, true)) {
                continue;
            }
            $levels[] = $level;
        }

        return $levels === [] ? null : $levels;
    }

    /**
     * Known client field is present. Camel case or snake case.
     *
     * @param array<string, mixed> $bag
     */
    protected function hasClientField(array $bag, string $name): bool
    {
        return array_key_exists($name, $bag) || array_key_exists(Naming::snake($name), $bag);
    }

    /**
     * Known client field. Camel case wins over snake case.
     *
     * @param array<string, mixed> $bag
     */
    protected function clientField(array $bag, string $name): mixed
    {
        if (array_key_exists($name, $bag)) {
            return $bag[$name];
        }
        $snake = Naming::snake($name);

        return array_key_exists($snake, $bag) ? $bag[$snake] : null;
    }

    /**
     * @param array<string, mixed> $modelEntry
     *
     * @return list<string>
     */
    protected function aliasNames(array $modelEntry): array
    {
        $alias = $modelEntry['alias'] ?? [];

        return is_string($alias) ? [$alias] : $alias;
    }

    protected function readFile(): void
    {
        $parsed = $this->yamlReader->load((string)$this->file);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        if (is_array($parsed['providers'] ?? null)) {
            $rawProviders = $parsed['providers'];
        } else {
            $rawProviders = [];
            foreach ($parsed as $name => $entry) {
                if ($name === 'aliases' || !is_string($name) || !is_array($entry)) {
                    continue;
                }
                $rawProviders[$name] = $entry;
            }
        }
        $rawAliases = is_array($parsed['aliases'] ?? null) ? $parsed['aliases'] : [];

        $providers = [];
        foreach ($rawProviders as $name => $entry) {
            if (!is_string($name) || $name === '' || !is_array($entry)) {
                ConfigurationException::raise(
                    'Invalid LLM provider entry in {file}: keys must be strings and values mappings.',
                    ['file' => $this->file],
                );
            }
            $models = $entry['models'] ?? [];
            if (!is_array($models)) {
                ConfigurationException::raise(
                    'LLM provider "{provider}" models must be a mapping ({file}).',
                    ['provider' => $name, 'file' => $this->file],
                );
            }
            foreach ($models as $modelKey => $modelEntry) {
                if (!is_string($modelKey) || $modelKey === '') {
                    ConfigurationException::raise(
                        'LLM provider "{provider}" model keys must be non-empty strings ({file}).',
                        ['provider' => $name, 'file' => $this->file],
                    );
                }
                if ($modelEntry === null) {
                    $models[$modelKey] = [];
                } elseif (!is_array($modelEntry)) {
                    ConfigurationException::raise(
                        'LLM provider "{provider}" model "{model}" must be a mapping ({file}).',
                        ['provider' => $name, 'model' => $modelKey, 'file' => $this->file],
                    );
                }
            }
            $entry['models'] = $models;
            $providers[$name] = $entry;
        }

        $aliases = [];
        foreach ($rawAliases as $name => $target) {
            if (!is_string($name) || $name === '' || !is_string($target) || $target === '') {
                ConfigurationException::raise(
                    'Invalid LLM alias in {file}: keys and targets must be non-empty strings.',
                    ['file' => $this->file],
                );
            }
            $aliases[$name] = $target;
        }

        $this->providers = $providers;
        $this->aliases = $aliases;
    }
}
