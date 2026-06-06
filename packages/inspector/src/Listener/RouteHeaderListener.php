<?php

declare(strict_types=1);

namespace Switon\Inspector\Listener;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\Attribute\EventListener;
use Switon\Http\Event\HeadersSending;
use Switon\Http\RequestInterface;

/**
 * Adds matched route handler information to response headers.
 *
 * Use when debugging requests without opening inspector UI pages.
 *
 * @see \Switon\Inspector\Inspector
 * @see \Switon\Http\Event\HeadersSending
 */
class RouteHeaderListener
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected RequestInterface $request;

    /** Explicit enable flag; null uses environment defaults. */
    #[Autowired] protected ?bool $enabled;

    /**
     * Sets <code>X-Inspector-Route</code> on outgoing responses when enabled.
     */
    #[EventListener] public function onResponseHeadersSending(HeadersSending $event): void
    {
        $enabled = $this->enabled ?? $this->app->isEnv('dev');
        if (!$enabled) {
            return;
        }

        $handler = $this->request->handler();
        if ($handler !== '') {
            $event->response->setHeader('X-Inspector-Route', $handler);
        }
    }
}
