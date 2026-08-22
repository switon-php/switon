<?php

declare(strict_types=1);

namespace Switon\Cdp;

use Switon\Core\Attribute\Autowired;
use Throwable;

use function is_array;
use function is_numeric;
use function is_string;
use function min;
use function trim;

/**
 * Headed Chrome on port 59223. A visible window for login and interactive tools.
 *
 * Focusing a tab restores the window when it is minimized and raises it above other applications.
 * A maximized or fullscreen window is left at that size.
 */
class HeadedChrome extends Chrome implements HeadedChromeInterface
{
    #[Autowired] protected int $port = Paths::PORT_HEADED;

    protected function isHeadless(): bool
    {
        return false;
    }

    protected function listenPort(): int
    {
        return $this->port;
    }

    /**
     * Show the tab that was just opened.
     *
     * The window exists for a human to watch the agent, so the agent's tab belongs in front.
     */
    protected function tabToFocusAfterOpen(string $previousId, string $targetId): string
    {
        return $targetId;
    }

    public function focusTarget(string $targetId): void
    {
        parent::focusTarget($targetId);
        $targetId = trim($targetId);
        if ($targetId === '') {
            return;
        }

        $this->showWindow($targetId);
        $this->launcher->foreground($this->listenPort());
    }

    /**
     * Refuse a port another mode already owns, before opening a tab nobody can see.
     *
     * Role: a headed service that attaches to a headless Chrome gets a tab in a window that does
     * not exist. `open()` would succeed, every raise would be a no-op, and the caller would wait
     * for a human to look at it. Failing here names the actual mistake.
     */
    protected function ensure(): void
    {
        $port = $this->listenPort();
        if ($this->launcher->portOpen($port) && $this->launcher->listenerIsHeadless($port) === true) {
            Exception::raise(
                'Port {port} is held by a headless Chrome, so a visible window cannot open there. Use the headless service for port {port}, or give this headed service its own port.',
                ['port' => $port],
            );
        }

        parent::ensure();
    }

    /**
     * Restore a minimized window and ask Chrome to show this tab.
     *
     * `normal` is applied only when the window is minimized. Forcing it on a maximized window
     * would shrink a window the human arranged.
     */
    private function showWindow(string $targetId): void
    {
        $timeout = min(3.0, $this->timeout);
        $this->withDebuggerSession($this->browserDebuggerUrl($timeout), $timeout, static function (Session $session) use ($targetId, $timeout): void {
            $windowId = 0;
            $state = '';
            try {
                $window = $session->call('Browser.getWindowForTarget', ['targetId' => $targetId], $timeout);
                $windowId = is_numeric($window['windowId'] ?? null) ? (int) $window['windowId'] : 0;
                $bounds = $window['bounds'] ?? null;
                $state = is_array($bounds) ? (string) ($bounds['windowState'] ?? '') : '';
            } catch (Throwable) {
            }
            if ($windowId > 0 && $state === 'minimized') {
                try {
                    $session->call('Browser.setWindowBounds', [
                        'windowId' => $windowId,
                        'bounds' => ['windowState' => 'normal'],
                    ], $timeout);
                } catch (Throwable) {
                }
            }
            try {
                $session->call('Target.activateTarget', ['targetId' => $targetId], $timeout);
            } catch (Throwable) {
            }
        });

        $pageUrl = $this->pageDebuggerUrl($targetId, $timeout);
        $this->withDebuggerSession($pageUrl, $timeout, static function (Session $session) use ($timeout): void {
            $session->call('Page.bringToFront', [], $timeout);
        });
    }

    private function pageDebuggerUrl(string $targetId, float $timeout): string
    {
        $list = $this->httpJson(
            'http://' . self::HOST . ':' . $this->listenPort() . '/json/list',
            $timeout,
            'GET',
        );
        if (!is_array($list)) {
            return '';
        }

        foreach ($list as $row) {
            if (!is_array($row) || (string) ($row['id'] ?? '') !== $targetId) {
                continue;
            }
            $url = $row['webSocketDebuggerUrl'] ?? null;

            return is_string($url) ? $url : '';
        }

        return '';
    }
}
