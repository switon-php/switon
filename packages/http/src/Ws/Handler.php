<?php

declare(strict_types=1);

namespace Switon\Http\Ws;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionMethod;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Core\StopFlow;
use Switon\Http\Event\RequestInvoked;
use Switon\Http\Event\RequestReceived;
use Switon\Http\Event\ResponseAdjust;
use Switon\Http\Event\ResponseStringify;
use Switon\Http\ExceptionDispatcherInterface;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\ServerInterface;
use Switon\Http\Attribute\WsMapping;
use Switon\Http\WsSenderInterface;
use Switon\Invoking\InvokerInterface;
use Switon\Routing\Exception\NotFoundRouteException;
use Switon\Routing\RouterInterface;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Throwable;

use function explode;
use function is_array;
use function is_string;
use function str_starts_with;
use function trim;

/**
 * WebSocket lifecycle dispatcher: handshake URL → controller, event → action.
 *
 * Return normalization matches HTTP for {@see WsMapping::VERB_MESSAGE} only.
 * {@see \Switon\Core\ContextConnScoped} state from open (request, identity, etc.)
 * is snapshotted by {@see \Switon\Http\Server\Adapter\Swoole} and restored on message/close.
 */
class Handler implements HandlerInterface
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected RequestHandlerInterface $requestHandler;
    #[Autowired] protected RouterInterface $router;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected InvokerInterface $invoker;
    #[Autowired] protected ExceptionDispatcherInterface $exceptionDispatcher;
    #[Autowired] protected ConnContextInterface $connContext;
    #[Autowired] protected ServerInterface $server;

    public function onOpen(Request $request): void
    {
        $fd = $request->fd;

        try {
            $this->prepareRequest($request);
            $path = $this->request->path();
            try {
                $matcher = $this->router->match($path, WsMapping::VERB_OPEN);
            } catch (NotFoundRouteException) {
                return;
            }

            [$controller, $action] = explode('::', $matcher->getHandler(), 2);
            $variables = $matcher->getVariables();

            $this->connContext->bind($fd, $controller, $variables, $path);
            $this->dispatch($controller, $action, array_merge($variables, ['fd' => $fd]), normalizeReturn: false);
        } catch (StopFlow) {
            // no-op
        } catch (Throwable $throwable) {
            $this->exceptionDispatcher->dispatch($throwable);
        } finally {
            $this->flushResponse($fd);
        }
    }

    public function onMessage(Frame $frame): void
    {
        $fd = $frame->fd;
        $conn = $this->connContext->get($fd);
        if ($conn === null) {
            return;
        }

        $requestSnapshot = $this->request->getContext()->_REQUEST;

        try {
            try {
                $matcher = $this->router->match($conn['path'], WsMapping::VERB_MESSAGE);
            } catch (NotFoundRouteException) {
                return;
            }

            [$controller, $action] = explode('::', $matcher->getHandler(), 2);
            $variables = $matcher->getVariables();

            $message = new Message($fd, $frame->data);
            $parameters = array_merge($variables, ['fd' => $fd, 'message' => $message]);
            if (str_starts_with(trim($frame->data), '{')) {
                $decoded = Json::parse($frame->data);
                if (is_array($decoded)) {
                    $parameters = array_merge($decoded, $parameters);
                }
            }

            $this->dispatch($controller, $action, $parameters, normalizeReturn: true);
        } catch (StopFlow) {
            // no-op
        } catch (Throwable $throwable) {
            $this->exceptionDispatcher->dispatch($throwable);
        } finally {
            $this->flushResponse($fd);
            $context = $this->request->getContext();
            $context->_REQUEST = $requestSnapshot;
            $context->_POST = [];
            $context->rawBody = null;
        }
    }

    public function onClose(int $fd): void
    {
        $conn = $this->connContext->get($fd);
        if ($conn === null) {
            return;
        }

        try {
            try {
                $matcher = $this->router->match($conn['path'], WsMapping::VERB_CLOSE);
            } catch (NotFoundRouteException) {
                return;
            }

            [$controller, $action] = explode('::', $matcher->getHandler(), 2);
            $variables = $matcher->getVariables();
            $this->dispatch($controller, $action, array_merge($variables, ['fd' => $fd]), normalizeReturn: false);
        } catch (StopFlow) {
            // no-op
        } catch (Throwable $throwable) {
            $this->exceptionDispatcher->dispatch($throwable);
        } finally {
            $this->connContext->remove($fd);
        }
    }

    public function onError(int $fd, int $reactorId): void
    {
        $conn = $this->connContext->get($fd);
        if ($conn === null) {
            return;
        }

        try {
            try {
                $matcher = $this->router->match($conn['path'], WsMapping::VERB_ERROR);
            } catch (NotFoundRouteException) {
                return;
            }

            [$controller, $action] = explode('::', $matcher->getHandler(), 2);
            $variables = $matcher->getVariables();

            $this->dispatch($controller, $action, array_merge($variables, ['fd' => $fd, 'reactorId' => $reactorId]), normalizeReturn: false);
        } catch (StopFlow) {
            // no-op
        } catch (Throwable $throwable) {
            $this->exceptionDispatcher->dispatch($throwable);
        } finally {
            $this->flushResponse($fd);
        }
    }

    protected function prepareRequest(Request $request): void
    {
        $_server = array_change_key_case($request->server, CASE_UPPER);

        foreach ($request->header ?: [] as $k => $v) {
            if ($k === 'content-type' || $k === 'content-length') {
                $_server[strtoupper(strtr($k, '-', '_'))] = $v;
            } else {
                $_server['HTTP_' . strtoupper(strtr($k, '-', '_'))] = $v;
            }
        }

        $_get = $request->get ?: [];
        $cookies = $request->cookie ?? [];

        $this->container->get(\Psr\EventDispatcher\EventDispatcherInterface::class)
            ->dispatch(new RequestReceived($this->server, $_get, [], $_server, null, $cookies, []));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function dispatch(string $controller, string $action, array $parameters, bool $normalizeReturn = false): void
    {
        $context = $this->request->getContext();
        foreach ($parameters as $name => $value) {
            if (is_string($name) && !is_object($value)) {
                $context->_REQUEST[$name] = $value;
            }
        }

        $instance = $this->container->get($controller);
        $rMethod = new ReflectionMethod($controller, $action);
        $return = $this->invoker->invoke([$instance, $action], $parameters);

        if ($normalizeReturn || $return !== null || $this->response->hasContent()) {
            $this->eventDispatcher->dispatch(new RequestInvoked($this->requestHandler, $rMethod, $return));
        }
    }

    protected function flushResponse(int $fd): void
    {
        if (!$this->server instanceof WsSenderInterface) {
            return;
        }

        if (!$this->response->hasContent()) {
            return;
        }

        $this->eventDispatcher->dispatch(new ResponseAdjust($this->requestHandler, $this->request, $this->response));

        $content = $this->response->getContent();
        if (is_array($content)) {
            $this->eventDispatcher->dispatch(new ResponseStringify($this->requestHandler, $this->response));
            $content = $this->response->getContent();
            if (is_array($content)) {
                $this->response->setContent(Json::stringify($content));
                $content = $this->response->getContent();
            }
        }

        if (is_string($content) && $content !== '' && $this->server->isEstablished($fd)) {
            $this->server->push($fd, $content);
        }

        $this->response->setContent(null);
    }
}
