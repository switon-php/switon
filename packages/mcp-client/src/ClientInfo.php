<?php

declare(strict_types=1);

namespace Switon\McpClient;

/**
 * `protocolVersion` is offered to the server. `negotiatedProtocol` is the string it returned.
 */
class ClientInfo
{
    public ?string $negotiatedProtocol = null;

    protected function __construct(
        public string $name,
        public string $version = '1.0.0',
        public string $protocolVersion = '2025-03-26',
    ) {
    }

    public static function of(
        string $name,
        string $version = '1.0.0',
        string $protocolVersion = '2025-03-26',
    ): self {
        return new self($name, $version, $protocolVersion);
    }
}
