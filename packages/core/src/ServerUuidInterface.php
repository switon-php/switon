<?php

declare(strict_types=1);

namespace Switon\Core;

/**
 * Stable server identity for this runtime directory (MySQL `server_uuid` analogue).
 *
 * Guidance: inject in normal code; at bootstrap edges use
 * <code>App::get(ServerUuidInterface::class)->get()</code>.
 *
 * @see \Switon\Core\ServerUuid
 */
interface ServerUuidInterface
{
    /** Return the UUID persisted under {@see ServerUuid::$path}. */
    public function get(): string;
}
