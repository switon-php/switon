<?php

declare(strict_types=1);

namespace Switon\Cdp;

/**
 * Headed Chrome. Default port 59223, profile `~/.switon/chrome/headed`.
 *
 * Guidance: inject this for a visible window. Silent fetch uses {@see HeadlessChromeInterface}.
 *
 * Configure `port` and `userDataDir` on the headed implementation.
 */
interface HeadedChromeInterface extends ChromeInterface
{
}
