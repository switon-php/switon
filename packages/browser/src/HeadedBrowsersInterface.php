<?php

declare(strict_types=1);

namespace Switon\Browser;

use Switon\Ai\AgentInterface;

/**
 * Headed tabs for one agent, keyed by the id that agent chose.
 *
 * Guidance: agent tools pass that id here. The same id keeps one page and its refs; do not inject
 * {@see HeadedBrowserInterface} for tools.
 */
interface HeadedBrowsersInterface
{
    /**
     * The tab this agent already named `$tab`.
     *
     * @throws Exception When the name is new or empty. {@see HeadedBrowsersInterface::open()} creates it.
     */
    public function tab(AgentInterface $agent, string $tab): HeadedBrowserInterface;

    /**
     * The tab this agent named `$tab`, opening it when the name is new.
     *
     * @throws Exception When `$tab` is empty.
     */
    public function open(AgentInterface $agent, string $tab): HeadedBrowserInterface;

    /**
     * Close the tab this agent named `$tab`; its other tabs stay open.
     *
     * Guidance: the rest close when the agent is collected, so call this only to release one page
     * the agent is done with.
     *
     * @return string The closed id, trimmed as stored
     *
     * @throws Exception
     */
    public function close(AgentInterface $agent, string $tab): string;
}
