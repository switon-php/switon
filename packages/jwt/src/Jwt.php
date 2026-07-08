<?php

declare(strict_types=1);

namespace Switon\Jwt;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Json;
use Switon\Crypto\CipherInterface;
use Switon\Jwt\Exception\AlgorithmMismatchException;
use Switon\Jwt\Exception\ExpiredException;
use Switon\Jwt\Exception\KidMismatchException;
use Switon\Jwt\Exception\MalformedException;
use Switon\Jwt\Exception\NoCredentialException;
use Switon\Jwt\Exception\NotBeforeException;
use Switon\Jwt\Exception\SignatureException;

use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function preg_match;
use function rtrim;
use function strlen;
use function strtr;
use function substr;

/**
 * Implements HMAC-based JWT encoding and validation.
 *
 * Use when one trust domain shares the same JWT algorithm and key policy.
 *
 * Supports <code>HS256</code>, <code>HS384</code>, and <code>HS512</code> with configurable key and
 * validation leeway.
 *
 * @see \Switon\Jwt\JwtInterface
 * @see \Switon\Jwt\Exception
 */
class Jwt implements JwtInterface
{
    /** Cryptographic service for key derivation. */
    #[Autowired] protected CipherInterface $cipher;
    /** Clock service for time operations (testable). */
    #[Autowired] protected ClockInterface $clock;

    /** HMAC algorithm for signing (HS256, HS384, HS512). */
    #[Autowired] protected string $alg = 'HS256';
    /** Default HMAC key in lowercase hex with an even number of characters, null to derive from CipherInterface. */
    #[Autowired] protected ?string $key;
    /** Time leeway in seconds for exp/nbf validation (clock skew tolerance). */
    #[Autowired] protected int $leeway = 0;

    /**
     * Encodes binary or JSON text using Base64 URL-safe format without padding.
     *
     * @param string $str Source string
     *
     * @return string Encoded string
     */
    protected function base64UrlEncode(string $str): string
    {
        return strtr(rtrim(base64_encode($str), '='), '+/', '-_');
    }

    /**
     * Decodes Base64 URL-safe text back to the original bytes.
     *
     * @param string $str Encoded string
     *
     * @return string|null Decoded string, or null on decode failure
     */
    protected function base64UrlDecode(string $str): ?string
    {
        $v = strtr($str, '-_', '+/');
        $pad = strlen($v) % 4;
        if ($pad !== 0) {
            $v .= str_repeat('=', 4 - $pad);
        }

        $v = base64_decode($v, true);
        return $v === false ? null : $v;
    }

    /**
     * Resolves the signing key for the requested <code>kid</code> or the default JWT key policy.
     *
     * @param string|null $kid Key identifier from caller or token header
     *
     * @return string Lowercase hex signing key
     */
    protected function resolveSigningKeyHex(?string $kid): string
    {
        $bits = match ($this->alg) {
            'HS256' => 256,
            'HS384' => 384,
            'HS512' => 512,
            default => Exception::raise(
                'Unsupported JWT algorithm "{alg}". Use HS256, HS384, or HS512.',
                ['alg' => $this->alg]
            ),
        };

        if ($kid !== null) {
            return $this->cipher->getDerivedKey("jwt:$kid", $bits);
        }

        if ($this->key === null) {
            return $this->cipher->getDerivedKey('jwt', $bits);
        }

        if ($this->key === '' || strlen($this->key) % 2 !== 0 || preg_match('/^[0-9a-f]+$/', $this->key) !== 1) {
            Exception::raise('{source} must be lowercase hex with an even number of characters.', [
                'source' => 'Configured JWT key',
                'alg' => $this->alg,
                'key_preview' => substr($this->key, 0, 20),
            ]);
        }

        return $this->key;
    }

    /**
     * Splits the token into header, payload, and signature segments.
     *
     * @param string $token
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function splitToken(string $token): array
    {
        if ($token === '') {
            NoCredentialException::raise('Token credentials are missing.');
        }

        $parts = explode('.', $token, 4);
        if (count($parts) !== 3) {
            MalformedException::raise(
                'Token must have three parts separated by dots.',
                ['parts_count' => count($parts)]
            );
        }

        return $parts;
    }

    /**
     * Verifies the HMAC signature against the resolved signing key.
     *
     * @param array{0: string, 1: string, 2: string} $parts
     * @param string|null $kid
     * @param string $token
     */
    protected function verifySignature(array $parts, ?string $kid, string $token): void
    {
        $key = hex2bin($this->resolveSigningKeyHex($kid));
        $data = $parts[0] . '.' . $parts[1];
        $signature = $parts[2];
        $hmac = hash_hmac(strtr($this->alg, ['HS' => 'sha']), $data, $key, true);

        if (!hash_equals($this->base64UrlEncode($hmac), $signature)) {
            SignatureException::raise('Token signature verification failed.', [
                'algorithm' => $this->alg,
                'token_preview' => substr($token, 0, 50),
                'expected_signature' => substr($this->base64UrlEncode($hmac), 0, 20),
                'received_signature' => substr($signature, 0, 20),
            ]);
        }
    }

    /**
     * Decodes the claims payload and enforces that it is a JSON object array.
     *
     * @param string $payload Encoded JWT payload segment
     * @param string $token Original token for diagnostics
     *
     * @return array<string, mixed>
     */
    protected function decodePayloadClaims(string $payload, string $token): array
    {
        $decodedPayload = $this->base64UrlDecode($payload);
        if (!is_array($claims = json_decode($decodedPayload ?? '', true))) {
            MalformedException::raise('Token payload is not an array.', [
                'payload_type' => gettype($claims),
                'payload_preview' => is_string($decodedPayload) ? substr($decodedPayload, 0, 100) : null,
                'json_error' => json_last_error_msg(),
                'token_preview' => substr($token, 0, 50),
            ]);
        }

        return $claims;
    }

    /**
     * Decodes the JWT header and validates algorithm, type, and <code>kid</code> shape.
     *
     * @param string $header Encoded JWT header segment
     * @param string $token Original token for diagnostics
     *
     * @return array<string, mixed>
     */
    protected function decodeHeader(string $header, string $token): array
    {
        $decodedHeaderRaw = $this->base64UrlDecode($header);
        if (!is_array($decodedHeader = json_decode($decodedHeaderRaw ?? '', true))) {
            MalformedException::raise('Token header cannot be decoded or is invalid.', [
                'header_preview' => is_string($decodedHeaderRaw) ? substr($decodedHeaderRaw, 0, 100) : null,
                'json_error' => json_last_error_msg(),
                'token_preview' => substr($token, 0, 50),
            ]);
        }

        if (!isset($decodedHeader['alg'])) {
            MalformedException::raise('Algorithm field "alg" is missing in token header.', [
                'header_keys' => array_keys($decodedHeader),
                'token_preview' => substr($token, 0, 50),
            ]);
        }

        if ($decodedHeader['alg'] !== $this->alg) {
            AlgorithmMismatchException::raise(
                'Algorithm mismatch: {decoded_alg} != {alg}',
                ['decoded_alg' => $decodedHeader['alg'], 'alg' => $this->alg]
            );
        }

        if (isset($decodedHeader['typ']) && $decodedHeader['typ'] !== 'JWT') {
            MalformedException::raise('Token type "{typ}" is not "JWT".', ['typ' => $decodedHeader['typ']]);
        }

        if (isset($decodedHeader['kid']) && !is_string($decodedHeader['kid'])) {
            MalformedException::raise(
                'Token header field "kid" must be a string.',
                ['kid_type' => gettype($decodedHeader['kid'])]
            );
        }

        return $decodedHeader;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $claims
     */
    public function encode(array $claims, int $ttl, ?string $kid = null): string
    {
        $key = hex2bin($this->resolveSigningKeyHex($kid));

        $now = $this->clock->time();
        $claims['iat'] = $now;

        if ($ttl > 0) {
            $claims['exp'] = $now + $ttl;
        } else {
            unset($claims['exp']);
        }

        $headerData = ['alg' => $this->alg, 'typ' => 'JWT'];
        if ($kid !== null) {
            $headerData['kid'] = $kid;
        }

        $header = $this->base64UrlEncode(Json::stringify($headerData));
        $payload = $this->base64UrlEncode(Json::stringify($claims));
        $hmac = hash_hmac(strtr($this->alg, ['HS' => 'sha']), "$header.$payload", $key, true);
        $signature = $this->base64UrlEncode($hmac);

        return "$header.$payload.$signature";
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed>
     */
    public function decode(string $token, ?string $kid = null): array
    {
        $parts = $this->splitToken($token);
        $decodedHeader = $this->decodeHeader($parts[0], $token);
        $claims = $this->decodePayloadClaims($parts[1], $token);
        $tokenKid = $decodedHeader['kid'] ?? null;
        if ($kid !== null && $tokenKid !== null && $kid !== $tokenKid) {
            KidMismatchException::raise(
                'Token kid mismatch: {token_kid} != {expected_kid}',
                ['token_kid' => $tokenKid, 'expected_kid' => $kid]
            );
        }

        $this->verifySignature($parts, $kid ?? $tokenKid, $token);

        if (isset($claims['exp']) && !is_numeric($claims['exp'])) {
            MalformedException::raise('Token claim "exp" must be a Unix timestamp number.', [
                'exp_type' => gettype($claims['exp']),
                'token_preview' => substr($token, 0, 50),
            ]);
        }

        if (isset($claims['nbf']) && !is_numeric($claims['nbf'])) {
            MalformedException::raise('Token claim "nbf" must be a Unix timestamp number.', [
                'nbf_type' => gettype($claims['nbf']),
                'token_preview' => substr($token, 0, 50),
            ]);
        }

        $now = $this->clock->microtime();
        if (isset($claims['exp']) && $now > ((float)$claims['exp'] + $this->leeway)) {
            ExpiredException::raise('Token expired at "{expired_at}".', [
                'expired_at' => date('Y-m-d H:i:s', $claims['exp']),
            ]);
        }

        if (isset($claims['nbf']) && $now < ((float)$claims['nbf'] - $this->leeway)) {
            NotBeforeException::raise('Token not active until "{active_at}".', [
                'active_at' => date('Y-m-d H:i:s', $claims['nbf']),
            ]);
        }

        return $claims;
    }
}
