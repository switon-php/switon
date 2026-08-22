<?php

declare(strict_types=1);

namespace Switon\Cdp;

/**
 * A Chrome debugging endpoint.
 *
 * Guidance: inject {@see HeadlessChromeInterface} or {@see HeadedChromeInterface}. This base is not a container binding.
 *
 * Road-signs:
 * - Open a tab: {@see ChromeInterface::open()}
 * - Quit: {@see ChromeInterface::close()}
 */
interface ChromeInterface
{
    /**
     * Open `$url` in a new tab.
     *
     * Starts Chrome when its port is closed. Headless keeps the previous tab in front so a
     * visible window sharing the port is not disturbed; headed shows the tab it just opened.
     *
     * `$timeout` is the budget this tab keeps for its lifetime. Null uses the configured value,
     * so a caller with a tighter or looser need cannot retune the shared service.
     */
    public function open(string $url, ?float $timeout = null): PageInterface;

    /**
     * Quit the browser, but only one this service launched.
     *
     * A browser it attached to — a visible window a human opened to sign in — is left running.
     */
    public function close(): void;
}
