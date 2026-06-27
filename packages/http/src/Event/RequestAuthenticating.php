<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\RequestInterface;
use Switon\Http\RequestHandlerInterface;

/**
 * Event emitted before request authentication starts.
 *
 * Road-signs:
 * - emitted before authenticators resolve identity
 * - unauthorized failures end the authentication stage
 * - next hop is RequestAuthenticated on success
 *
 * Log category: <code>switon.http.request.authenticating</code>
 *
 * @see \Switon\Http\RequestHandler::handle()
 * @see \Switon\Http\Event\RequestAuthenticated
 * @see \Switon\Http\Exception\UnauthorizedException
 * @see \Switon\Principal\IdentityInterface
 */
#[EventLevel(Severity::DEBUG)]
class RequestAuthenticating implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public RequestHandlerInterface $sender,
        public RequestInterface $request
    ) {
    }

    public function getSender(): RequestHandlerInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
