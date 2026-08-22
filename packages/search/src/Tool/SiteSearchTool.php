<?php

declare(strict_types=1);

namespace Switon\Search\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Search\Exception;
use Switon\Search\SiteSearchInterface;
use Switon\Search\SitesInterface;
use Switon\Tooling\AbstractTool;

use function array_keys;
use function is_array;
use function is_int;

/** Tool `site_search`. `page` greater than 1 is rejected when the site has no `size`. */
class SiteSearchTool extends AbstractTool
{
    #[Autowired] protected SitesInterface $sites;
    #[Autowired] protected SiteSearchInterface $search;

    #[Autowired] protected bool $enabled = true;
    /** Absolute hit cap per call (one response page). */
    #[Autowired] protected int $limit = 20;

    public function schema(string $name): ?ToolSchema
    {
        if (!$this->enabled) {
            return null;
        }

        return new ToolSchema(
            $this,
            description: 'Search one curated site. `site` takes a registry id (enum below); use `site_list` for labels.',
            properties: [
                'site' => [
                    'type' => 'string',
                    'description' => 'Site id (enum = registry).',
                    'enum' => array_keys($this->sites->all()),
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query.',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => '1-based result page (default 1). Only sites with pagination.',
                ],
            ],
            required: ['site', 'query'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $siteId = $arguments['site'];
        $site = $this->sites->get($siteId);
        $page = $arguments['page'] ?? 1;
        if ($page > 1 && $site->size === 0) {
            Exception::raise('Site "{id}" does not support pagination.', ['id' => $siteId]);
        }

        $returned = $this->search->search(
            $site,
            $arguments['query'],
            $this->limit,
            static fn (): bool => $agent->isCancelled() || $call->cancelled,
            $page,
        );

        $status = null;
        if (isset($returned['status'], $returned['results'])
            && is_int($returned['status']) && is_array($returned['results'])
        ) {
            $results = $returned['results'];
            $status = $returned['status'];
        } else {
            $results = $returned;
        }

        $payload = [
            'site' => $siteId,
            'query' => $arguments['query'],
            'page' => $page,
            'results' => $results,
        ];
        if ($status !== null) {
            $payload['status'] = $status;
        } elseif ($results === []) {
            $payload['warning'] = 'No results; the site may have changed, rate-limited, or returned empty.';
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
