<?php

declare(strict_types=1);

namespace Switon\Browser;

use Switon\Ai\AgentInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use WeakMap;

use function trim;

/**
 * Keeps each live agent's tabs apart.
 *
 * Chrome stays the shared headed process. A tab is keyed by the agent object plus the id that
 * agent chose, so two agents — or two ids — cannot share `$page`.
 *
 * Guidance: a collected agent releases every tab it still holds, through
 * {@see HeadedBrowser::__destruct()}. Nothing else closes them in bulk.
 */
class HeadedBrowsers implements HeadedBrowsersInterface
{
    #[Autowired] protected MakerInterface $maker;

    /** @var WeakMap<AgentInterface, array<string, HeadedBrowserInterface>> */
    protected WeakMap $byAgent;

    public function __construct()
    {
        $this->byAgent = new WeakMap();
    }

    public function tab(AgentInterface $agent, string $tab): HeadedBrowserInterface
    {
        $tab = $this->tabId($tab);
        $browser = $this->tabs($agent)[$tab] ?? null;
        if ($browser === null) {
            Exception::raise('Tab "{tab}" is not open. Navigate to that tab first.', ['tab' => $tab]);
        }

        return $browser;
    }

    public function open(AgentInterface $agent, string $tab): HeadedBrowserInterface
    {
        $tab = $this->tabId($tab);
        $tabs = $this->tabs($agent);
        if (isset($tabs[$tab])) {
            return $tabs[$tab];
        }

        $browser = $this->maker->make(HeadedBrowser::class);
        $tabs[$tab] = $browser;
        $this->byAgent[$agent] = $tabs;

        return $browser;
    }

    public function close(AgentInterface $agent, string $tab): string
    {
        $tab = $this->tabId($tab);
        $tabs = $this->tabs($agent);
        $browser = $tabs[$tab] ?? null;
        if ($browser === null) {
            Exception::raise('Tab "{tab}" is not open.', ['tab' => $tab]);
        }

        unset($tabs[$tab]);
        if ($tabs === []) {
            unset($this->byAgent[$agent]);
        } else {
            $this->byAgent[$agent] = $tabs;
        }
        $browser->closePage();

        return $tab;
    }

    /** @return array<string, HeadedBrowserInterface> */
    protected function tabs(AgentInterface $agent): array
    {
        return isset($this->byAgent[$agent]) ? $this->byAgent[$agent] : [];
    }

    /** @throws Exception */
    protected function tabId(string $tab): string
    {
        $tab = trim($tab);
        if ($tab === '') {
            Exception::raise('Tab id must not be empty.');
        }

        return $tab;
    }
}
