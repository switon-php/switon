<?php

declare(strict_types=1);

namespace Switon\Search\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Search\Web\UrlNormalizer;
use Switon\Search\WebSearchInterface;
use Switon\Tooling\AbstractTool;

use function in_array;
use function trim;

/** Tool `web_search`. The hit cap is `$limit`, not a call argument. */
class WebSearchTool extends AbstractTool
{
    #[Autowired] protected WebSearchInterface $webSearch;

    #[Autowired] protected bool $enabled = true;
    /** Absolute hit cap after dedupe (four engines can yield ~60 raw hits). */
    #[Autowired] protected int $limit = 60;

    public function schema(string $name): ?ToolSchema
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Search the web.',
            properties: [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query.',
                ],
                'site' => [
                    'type' => 'string',
                    'description' => 'Optional hostname (e.g. github.com).',
                ],
                'lang' => [
                    'type' => 'string',
                    'description' => 'Optional language hint (e.g. zh-CN, en).',
                ],
            ],
            required: ['query'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $site = trim((string) ($arguments['site'] ?? ''));
        $siteHost = $site !== '' ? UrlNormalizer::siteHost($site) : '';
        $lang = trim((string) ($arguments['lang'] ?? ''));

        $results = $this->webSearch->search(
            $arguments['query'],
            $this->limit,
            fn (): bool => $agent->isCancelled() || $call->cancelled,
            $lang !== '' ? $lang : null,
            $siteHost !== '' ? $siteHost : null,
        );

        $engines = [];
        foreach ($results as $row) {
            $engine = (string) ($row['engine'] ?? '');
            if ($engine !== '' && !in_array($engine, $engines, true)) {
                $engines[] = $engine;
            }
        }

        $payload = [
            'query' => $arguments['query'],
            'results' => $results,
        ];
        if ($engines !== []) {
            $payload['engines'] = $engines;
        }
        if ($siteHost !== '') {
            $payload['site'] = $siteHost;
        }
        if ($lang !== '') {
            $payload['lang'] = $lang;
        }

        if ($results === []) {
            $payload['warning'] = 'No results parsed; engines may have changed or returned a challenge.';
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
