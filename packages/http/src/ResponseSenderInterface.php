<?php

declare(strict_types=1);

namespace Switon\Http;

/**
 * HTTP response output boundary.
 *
 * Use for objects that can emit headers and body content.
 *
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Server\Adapter\Native\SenderInterface
 */
interface ResponseSenderInterface
{
    public function sendHeaders(): void;

    public function sendBody(): void;
}
