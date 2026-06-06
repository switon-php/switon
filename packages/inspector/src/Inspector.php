<?php

declare(strict_types=1);

namespace Switon\Inspector;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Core\RandomInterface;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Http\Event\RequestBegin;
use Switon\Http\Event\RequestEnd;
use Switon\Http\Event\ResponseStringify;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\Server\Event\ServerReady;
use Switon\Http\UrlGeneratorInterface;
use Switon\Inspector\Event\InspectorLinkGenerated;
use Switon\Inspector\Listener\RenderListener;
use Switon\Inspector\Listener\RouteHeaderListener;

use function date;
use function is_array;
use function str_contains;

/**
 * Coordinates inspector lifecycle across request events.
 *
 * Use when enabling collector bootstrapping, link generation, data aggregation,
 * and optional JSON tail injection.
 *
 * @see \Switon\Inspector\InspectorInterface
 * @see \Switon\Inspector\CollectorDiscoveryInterface
 * @see \Switon\Inspector\StorageInterface
 * @see \Switon\Http\Event\RequestBegin
 * @see \Switon\Http\Event\RequestEnd
 * @see \Switon\Http\Event\ResponseStringify
 * @see \Switon\Http\Server\Event\ServerReady
 */
class Inspector implements InspectorInterface, ContextAware, ObservabilityProbe
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected ContextManagerInterface $contextManager;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected UrlGeneratorInterface $urlGenerator;
    #[Autowired] protected StorageInterface $storage;
    #[Autowired] protected CollectorDiscoveryInterface $discovery;
    #[Autowired] protected RandomInterface $random;

    /** Whether to append inspector link into array JSON responses. */
    #[Autowired] protected bool $tail = true;
    /** Explicit inspector enable flag; null uses environment defaults. */
    #[Autowired] protected ?bool $enabled;

    /**
     * Returns request-local inspector state for the current request lifecycle.
     */
    public function getContext(): InspectorContext
    {
        return $this->contextManager->getContext($this);
    }

    /** {@inheritDoc} */
    public function boot(): void
    {
        $enabled = $this->enabled ?? ($this->app->isEnv('dev') || $this->app->isEnv('test'));
        if (!$enabled) {
            return;
        }

        foreach ($this->discovery->resolve() as $collector) {
            $collector->boot();
        }

        $this->listenerProvider->register(RenderListener::class);
        $this->listenerProvider->register($this);
        $this->listenerProvider->register(RouteHeaderListener::class);
        $this->listenerProvider->on(ServerReady::class, [$this, 'onServerReady']);
    }

    /**
     * Handles server-ready lifecycle event.
     *
     * Override in subclasses for startup probes or diagnostics.
     */
    public function onServerReady(): void
    {
    }

    /**
     * Starts inspector capture for the current request and sets the response header link.
     */
    #[EventListener] public function onRequestBegin(RequestBegin $event): void
    {
        $context = $this->getContext();

        if (str_contains((string)$this->request->header('user-agent'), 'ApacheBench')) {
            $context->enabled = false;
        } else {
            $context->enabled = true;
            $context->key = date('/ymd/His_') . $this->random->chars(32);
        }

        if ($context->enabled) {
            $context->url = $this->urlGenerator->generate("/?__inspector=$context->key.html", true);
            $this->response->setHeader('X-Inspector-Link', $context->url);
            $this->eventDispatcher->dispatch(new InspectorLinkGenerated($context->url, $context->key));
        }
    }

    /**
     * Persists collected inspector data and broadcasts the generated link when capture is enabled.
     */
    #[EventListener] public function onRequestEnd(RequestEnd $event): void
    {
        $context = $this->getContext();

        if ($context->enabled) {
            $this->storage->write($context->key, $this->getData());
            $this->storage->broadcastLink($context->url);
        }
    }

    /**
     * Appends the inspector link to array JSON responses when tail injection is enabled.
     */
    #[EventListener] public function onResponseStringify(ResponseStringify $event): void
    {
        if ($this->tail && is_array($content = $this->response->getContent())) {
            $content['inspector'] = $this->response->getHeader('X-Inspector-Link');
            $this->response->setContent($content);
        }
    }

    /**
     * Collects the current request payload from all resolved inspector collectors.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $data = [];

        foreach ($this->discovery->resolve() as $key => $collector) {
            $data[$key] = $collector->collect();
        }

        return $data;
    }
}
