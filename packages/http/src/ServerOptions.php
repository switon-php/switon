<?php

declare(strict_types=1);

namespace Switon\Http;

use Switon\Core\Attribute\Autowired;

/**
 * HTTP server runtime options.
 *
 * Guidance: Set <code>type</code> to <code>auto</code>, <code>fpm</code>, <code>php</code>, or <code>swoole</code>.
 *
 * @see \Switon\Http\Server
 * @see \Switon\Http\Kernel::start()
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Server\Detector
 */
class ServerOptions
{
    /**
     * Listen host.
     */
    #[Autowired] public string $host = '0.0.0.0';

    /**
     * Listen port.
     */
    #[Autowired] public int $port = 9501;

    /**
     * Server adapter type: auto, fpm, php, or swoole.
     */
    #[Autowired] public string $type = 'auto';

    /**
     * Adapter-specific settings.
     *
     * @var array<string, mixed>
     */
    #[Autowired] public array $settings = [];
}
