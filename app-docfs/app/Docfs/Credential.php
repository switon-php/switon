<?php

declare(strict_types=1);

namespace App\Docfs;

use Switon\Core\Attribute\Autowired;
use Switon\Jwt\Exception\NoCredentialException;
use Switon\Jwt\JwtInterface;
use Switon\Mcp\SessionInterface;

/**
 * Stateless credential: session access key → JWT payload (any failure raises).
 */
final class Credential implements CredentialInterface
{
    #[Autowired] protected SessionInterface $session;
    #[Autowired] protected JwtInterface $jwt;

    public function payload(): array
    {
        $token = $this->session->getAccessKey();
        if ($token === null) {
            NoCredentialException::raise('Access token is required (Authorization: Bearer or access_key).');
        }

        return $this->jwt->decode($token);
    }
}
