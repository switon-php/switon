<?php

declare(strict_types=1);

namespace App\Listener;

use App\Entity\AdminActionLog;
use App\Repository\AdminActionLogRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ObservabilityProbe;
use Switon\Core\Json;
use Switon\Db\Event\DbExecuting;
use Switon\Http\CookiesInterface;
use Switon\Http\Event\RequestInvoked;
use Switon\Http\Event\RequestInvoking;
use Switon\Http\RequestInterface;
use Switon\Principal\IdentityInterface;

use function str_contains;

class AdminActionLogListener implements ContextAware, ObservabilityProbe
{
    #[Autowired] protected ContextManagerInterface $contextManager;
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected CookiesInterface $cookies;
    #[Autowired] protected AdminActionLogRepository $adminActionLogRepository;

    public function getContext(): AdminActionLogListenerContext
    {
        return $this->contextManager->getContext($this);
    }

    protected function getTag(): int
    {
        foreach ($this->request->all() as $k => $v) {
            if (is_numeric($v)) {
                if ($k === 'id') {
                    return (int)$v;
                } elseif (str_ends_with($k, '_id')) {
                    return (int)$v;
                }
            }
        }

        return 0;
    }

    #[EventListener] public function onRequestInvoking(RequestInvoking $event): void
    {
        $context = $this->getContext();

        $context->invoking = true;
        $context->handler = $event->controller . '::' . $event->action;
    }

    /** @noinspection  PhpUnusedParameterInspection */
    #[EventListener] public function onRequestInvoked(RequestInvoked $event): void
    {
        $context = $this->getContext();

        $context->invoking = false;
    }

    /** @noinspection  PhpUnusedParameterInspection */
    #[EventListener] public function onDbExecuting(DbExecuting $event): void
    {
        $context = $this->getContext();
        if ($context->logged) {
            return;
        }

        if ($context->invoking && str_contains($context->handler, '\\Areas\\Admin\\')) {
            $this->logAdminAction();
        }
    }

    public function logAdminAction(): void
    {
        $context = $this->getContext();

        if ($context->logged) {
            return;
        }

        $context->logged = true;

        $data = $this->request->all();
        if (isset($data['password'])) {
            $data['password'] = '*';
        }
        unset($data['ajax']);

        $adminActionLog = new AdminActionLog();

        $adminActionLog->admin_id = $this->identity->isGuest() ? 0 : $this->identity->getId();
        $adminActionLog->admin_name = $this->identity->isGuest() ? '' : $this->identity->getName();
        $adminActionLog->client_ip = $this->request->ip();
        $adminActionLog->method = $this->request->verb();
        $adminActionLog->url = $this->request->path();
        $adminActionLog->tag = $this->getTag() & 0xFFFFFFFF;
        $adminActionLog->data = Json::stringify($data);
        $adminActionLog->handler = $context->handler;
        $adminActionLog->client_udid = $this->cookies->get('CLIENT_UDID');

        $this->adminActionLogRepository->create($adminActionLog);
    }
}
