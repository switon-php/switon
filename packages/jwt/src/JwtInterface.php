<?php

declare(strict_types=1);

namespace Switon\Jwt;

use Switon\Jwt\Exception\AlgorithmMismatchException;
use Switon\Jwt\Exception\ExpiredException;
use Switon\Jwt\Exception\KidMismatchException;
use Switon\Jwt\Exception\MalformedException;
use Switon\Jwt\Exception\NoCredentialException;
use Switon\Jwt\Exception\NotBeforeException;
use Switon\Jwt\Exception\SignatureException;

/**
 * Contract for issuing and validating application JWTs.
 *
 * Guidance:
 * - use this for HMAC-signed JWT flows inside one trust domain
 * - implementations are responsible for both claim policy and signature validation
 *
 * Road-signs:
 * - Jwt
 * - ExpiredException / SignatureException / KidMismatchException
 *
 * @see \Switon\Jwt\Jwt
 * @see \Switon\Jwt\Exception
 * @see \Switon\Jwt\Exception\ExpiredException
 * @see \Switon\Jwt\Exception\SignatureException
 * @see \Switon\Jwt\Exception\KidMismatchException
 */
interface JwtInterface
{
    /**
     * Encodes claims into a signed JWT token.
     *
     * Adds <code>iat</code> automatically.
     * Adds <code>exp</code> when <code>$ttl &gt; 0</code>; removes <code>exp</code> when <code>$ttl &lt;= 0</code>.
     * When <code>$kid</code> is provided, implementation derives key from <code>jwt:{kid}</code> and writes
     * <code>kid</code> into JWT header.
     *
     * @param array<string, mixed> $claims Application claims.
     * @param int $ttl Token lifetime in seconds; 0 removes the expiration claim.
     * @param string|null $kid Key identifier for header and key derivation, or null for the default key policy.
     *
     * @return string JWT token in <code>header.payload.signature</code> format
     *
     * @throws MalformedException If claims cannot be serialized as JSON
     * @throws \Switon\Jwt\Exception On unsupported configured algorithm or invalid signing key policy
     * @throws \Switon\Core\Exception On dependency-level cryptographic failures bubbling from underlying services
     */
    public function encode(array $claims, int $ttl, ?string $kid = null): string;

    /**
     * Decodes and validates a JWT token.
     *
     * Validates structure, header algorithm/type (when <code>typ</code> is present), claims payload shape, signature, and
     * <code>exp</code>/<code>nbf</code>.
     *
     * @param string $token JWT token to decode and validate.
     * @param string|null $kid Expected key identifier, or null to use token header or default key policy.
     *
     * @return array<string, mixed> Decoded claims
     *
     * @throws NoCredentialException If token is empty
     * @throws MalformedException If token format/header/payload is invalid
     * @throws AlgorithmMismatchException If header <code>alg</code>
     *         differs from configured algorithm
     * @throws ExpiredException If current time is after <code>exp</code> (with leeway)
     * @throws NotBeforeException If current time is before <code>nbf</code> (with leeway)
     * @throws KidMismatchException If expected <code>kid</code> differs from token header <code>kid</code>
     * @throws SignatureException If signature verification fails
     * @throws \Switon\Jwt\Exception On unsupported configured algorithm or invalid signing key policy
     * @throws \Switon\Core\Exception On dependency-level cryptographic failures bubbling from underlying services
     */
    public function decode(string $token, ?string $kid = null): array;
}
