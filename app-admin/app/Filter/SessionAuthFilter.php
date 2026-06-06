<?php

declare(strict_types=1);

namespace App\Filter;

use Switon\Core\Attribute\Autowired;
use Switon\Eventing\Attribute\EventListener;
use Switon\Http\Event\RequestAuthenticating;
use Switon\Principal\IdentityInterface;
use Switon\Session\SessionInterface;

/**
 * Session authentication filter.
 */
class SessionAuthFilter
{
    #[Autowired] protected SessionInterface $session;
    #[Autowired] protected IdentityInterface $identity;

    #[Autowired] protected string $sessionKey = 'auth';

    #[EventListener] public function onAuthenticating(RequestAuthenticating $event): void
    {
        $identity = $this->session->get($this->sessionKey, null);
        if (!is_array($identity)) {
            return;
        }

        $this->identity->set($identity['id'], $identity['name'], $identity['roles'] ?? []);
    }
}
