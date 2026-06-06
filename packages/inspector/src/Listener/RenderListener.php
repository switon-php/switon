<?php

declare(strict_types=1);

namespace Switon\Inspector\Listener;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Core\StopFlow;
use Switon\Eventing\Attribute\EventListener;
use Switon\Http\Event\RequestBegin;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Inspector\HtmlRendererInterface;
use Switon\Inspector\StorageInterface;

use function preg_match;

/**
 * Serves stored inspector payloads for inspector request URLs.
 *
 * Use when handling <code>?__inspector={key}.(html|json|txt|raw)</code> routes.
 *
 * @see \Switon\Inspector\Inspector
 * @see \Switon\Inspector\StorageInterface
 * @see \Switon\Inspector\HtmlRendererInterface
 */
class RenderListener
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected StorageInterface $storage;
    #[Autowired] protected HtmlRendererInterface $htmlRenderer;

    /**
     * Renders stored inspector data and stops normal request flow.
     */
    #[EventListener] public function onRequestBegin(RequestBegin $event): void
    {
        $inspector = $this->request->get('__inspector');

        if ($inspector === null || !preg_match('#^([\w/_-]+)\.(html|json|txt|raw)$#', $inspector, $match)) {
            return;
        }

        $data = $this->storage->read($match[1]);

        if ($data === null) {
            $this->response->text('NOT FOUND', 'text/plain', 404);
            throw StopFlow::because('Inspector data not found');
        }

        match ($match[2]) {
            'html' => $this->response->text(
                $this->htmlRenderer->render($data),
                'text/html'
            ),
            'txt' => $this->response->text(Json::stringify(Json::parse($data), JSON_PRETTY_PRINT)),
            'raw' => $this->response->text($data),
            default => $this->response->json(Json::parse($data)),
        };

        throw StopFlow::because('Inspector UI displayed');
    }
}
