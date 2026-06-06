<?php

declare(strict_types=1);

namespace App\Filter;

use App\Repository\AdminRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\Attribute\EventListener;
use Switon\Http\Event\RequestAuthenticating;
use Switon\Principal\IdentityInterface;
use Switon\Session\SessionInterface;

use function is_array;
use function time;

/**
 * Session authentication filter.
 */
class AuthenticationFilter
{
    #[Autowired] protected SessionInterface $session;
    #[Autowired] protected IdentityInterface $identity;
    #[Autowired] protected AdminRepository $adminRepository;

    #[Autowired] protected string $sessionKey = 'auth';

    #[EventListener] public function onAuthenticating(RequestAuthenticating $event): void
    {
        $identity = $this->session->get($this->sessionKey, null);
        if (!is_array($identity)) {
            return;
        }

        $adminId = (int) ($identity['id'] ?? 0);
        if ($adminId <= 0) {
            $this->session->remove($this->sessionKey);
            $this->identity->clear();
            return;
        }

        $admin = $this->adminRepository->first(
            ['admin_id' => $adminId],
            ['admin_id', 'status', 'session_id', 'locked_until']
        );
        $currentSessionId = $this->session->getId();
        if ($admin === null
            || $admin->status !== 1
            || $admin->locked_until > time()
            || ($admin->session_id !== '' && $admin->session_id !== $currentSessionId)
        ) {
            $this->session->destroy();
            $this->identity->clear();
            return;
        }

        $this->identity->set($identity['id'], $identity['name'], $identity['roles'] ?? []);
    }
}
