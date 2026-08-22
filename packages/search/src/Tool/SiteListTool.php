<?php

declare(strict_types=1);

namespace Switon\Search\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Search\SitesInterface;
use Switon\Tooling\AbstractTool;

use function ksort;

use const SORT_STRING;

/** Tool `site_list`. Id, label, and whether the site paginates. */
class SiteListTool extends AbstractTool
{
    #[Autowired] protected SitesInterface $sites;

    #[Autowired] protected bool $enabled = true;

    public function schema(string $name): ?ToolSchema
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->schema ??= new ToolSchema(
            $this,
            description: 'List curated sites: id, label, pagination. Ids feed `site_search`.',
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $sites = $this->sites->all();
        ksort($sites, SORT_STRING);

        $rows = [];
        foreach ($sites as $id => $site) {
            $rows[] = [
                'id' => $id,
                'label' => $site->description,
                'pagination' => $site->size > 0,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
