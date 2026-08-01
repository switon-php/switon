<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * Current MCP call facade: session id, HTTP headers, access credentials.
 *
 * Streamable HTTP: each POST carries client headers + {@see Transport\HttpTransportInterface::SESSION_HEADER}.
 * stdio / no HTTP request: id and tokens are null; headers empty.
 *
 * @see \Switon\Mcp\Session
 */
interface SessionInterface
{
    /** {@see Transport\HttpTransportInterface::SESSION_HEADER}, or null when absent. */
    public function getId(): ?string;

    /** Case-insensitive header read. */
    public function header(string $name, ?string $default = null): ?string;

    /**
     * Normalized request headers (empty when no HTTP request).
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /**
     * Credential material for the call: Bearer first, else query <code>access_key</code>.
     */
    public function getAccessKey(): ?string;
}
