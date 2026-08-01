<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Core\Attribute\Autowired;
use Switon\Http\RequestInterface;
use Switon\Mcp\Transport\HttpTransportInterface;

/**
 * Reads the current HTTP request for MCP session id, headers, and access key.
 *
 * @see \Switon\Mcp\SessionInterface
 */
class Session implements SessionInterface
{
    #[Autowired] protected RequestInterface $request;

    public function getId(): ?string
    {
        $id = $this->header(HttpTransportInterface::SESSION_HEADER);
        if ($id === null || $id === '') {
            return null;
        }

        return $id;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->request->header($name, $default);
    }

    public function headers(): array
    {
        return $this->request->headers();
    }

    public function getAccessKey(): ?string
    {
        $header = $this->header('Authorization');
        if ($header !== null && $header !== ''
            && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1
        ) {
            return $m[1];
        }

        $key = $this->request->query('access_key');
        if (!is_string($key)) {
            return null;
        }
        $key = trim($key);

        return $key !== '' ? $key : null;
    }
}
