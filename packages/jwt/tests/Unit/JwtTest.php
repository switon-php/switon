<?php

declare(strict_types=1);

namespace Switon\Jwt\Tests\Unit;

use Switon\Core\Exception as CoreException;
use Switon\Jwt\Exception as JwtException;
use Switon\Jwt\Exception\AlgorithmMismatchException;
use Switon\Jwt\Exception\ExpiredException;
use Switon\Jwt\Exception\KidMismatchException;
use Switon\Jwt\Exception\MalformedException;
use Switon\Jwt\Exception\NoCredentialException;
use Switon\Jwt\Exception\NotBeforeException;
use Switon\Jwt\Exception\SignatureException;
use Switon\Jwt\Jwt;
use Switon\Jwt\JwtInterface;
use Switon\Jwt\Tests\TestCase;

use function time;

/**
 * Test cases for Jwt class.
 */
class JwtTest extends TestCase
{
    protected JwtInterface $jwt;
    protected string $testKey;
    protected string $testKeyHex;

    protected function base64UrlEncode(string $str): string
    {
        return strtr(rtrim(base64_encode($str), '='), '+/', '-_');
    }

    protected function base64UrlDecode(string $str): string
    {
        $v = strtr($str, '-_', '+/');
        $pad = strlen($v) % 4;
        if ($pad !== 0) {
            $v .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($v, true);
        $this->assertNotFalse($decoded, 'base64url decode should succeed');
        return $decoded;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->testKey = str_repeat('test-secret-key-', 4);
        $this->testKeyHex = bin2hex($this->testKey);
        $this->jwt = $this->createJwt($this->testKeyHex);
    }

    /**
     * Test that encode() creates a valid JWT token with three parts.
     *
     * Verifies that encode() returns a token in the format "header.payload.signature"
     * with all three parts properly base64url encoded.
     */
    public function testEncodeCreatesValidTokenFormat(): void
    {
        // Arrange
        $claims = ['user_id' => 123, 'username' => 'testuser'];
        $ttl = 3600;

        // Act
        $token = $this->jwt->encode($claims, $ttl);

        // Assert
        $this->assertNotEmpty($token, 'Token should not be empty');
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'Token should have exactly three parts separated by dots');
        $this->assertNotEmpty($parts[0], 'Header should not be empty');
        $this->assertNotEmpty($parts[1], 'Payload should not be empty');
        $this->assertNotEmpty($parts[2], 'Signature should not be empty');
    }

    /**
     * Test that encode() automatically adds iat and exp claims.
     *
     * Verifies that encode() automatically adds issued-at (iat) and expiration (exp)
     * claims to the token, overwriting any provided values.
     */
    public function testEncodeAddsAutomaticClaims(): void
    {
        // Arrange
        $claims = ['user_id' => 123];
        $ttl = 3600;
        $beforeTime = time();

        // Act
        $token = $this->jwt->encode($claims, $ttl);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertArrayHasKey('iat', $decoded, 'Token should contain iat claim');
        $this->assertArrayHasKey('exp', $decoded, 'Token should contain exp claim');
        $this->assertGreaterThanOrEqual($beforeTime, $decoded['iat'], 'iat should be current time or later');
        $this->assertLessThanOrEqual(
            $beforeTime + 5,
            $decoded['iat'],
            'iat should be current time or within 5 seconds'
        );
        $this->assertEquals($decoded['iat'] + $ttl, $decoded['exp'], 'exp should be iat + ttl');
        $this->assertEquals(123, $decoded['user_id'], 'User claims should be preserved');
    }

    /**
     * Test that encode() overwrites existing iat and exp claims.
     *
     * Verifies that encode() overwrites any provided iat and exp claims with
     * automatically generated values.
     */
    public function testEncodeOverwritesExistingIatAndExp(): void
    {
        // Arrange
        $claims = ['user_id' => 123, 'iat' => 1000, 'exp' => 2000];
        $ttl = 3600;
        $beforeTime = time();

        // Act
        $token = $this->jwt->encode($claims, $ttl);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertNotEquals(1000, $decoded['iat'], 'iat should be overwritten');
        $this->assertNotEquals(2000, $decoded['exp'], 'exp should be overwritten');
        $this->assertGreaterThanOrEqual($beforeTime, $decoded['iat'], 'iat should be current time');
        $this->assertEquals($decoded['iat'] + $ttl, $decoded['exp'], 'exp should be iat + ttl');
    }

    /**
     * Test that encode() uses provided kid parameter.
     *
     * Verifies that encode() derives key from kid and writes kid into JWT header.
     */
    public function testEncodeWithExplicitKid(): void
    {
        // Arrange
        $claims = ['user_id' => 123];
        $ttl = 3600;
        $customKid = 'tenant:acme-corp:v1';

        // Act
        $token = $this->jwt->encode($claims, $ttl, $customKid);

        // Assert
        $decoded = $this->jwt->decode($token, $customKid);
        $this->assertEquals(123, $decoded['user_id'], 'Token should decode with explicit kid');

        $parts = explode('.', $token);
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $this->assertIsArray($header, 'Header should be decodable JSON object');
        $this->assertEquals($customKid, $header['kid'] ?? null, 'Header should include kid');
    }

    /**
     * Test that decode() can use token header kid for signature validation.
     */
    public function testDecodeUsesHeaderKidWhenExpectedKidIsNull(): void
    {
        // Arrange
        $claims = ['user_id' => 123];
        $kid = 'tenant:acme-corp:v1';
        $token = $this->jwt->encode($claims, 3600, $kid);

        // Act
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'Token should decode by using header kid');
    }

    /**
     * Test that encode() uses configured key when kid parameter is null.
     */
    public function testEncodeUsesConfiguredKey(): void
    {
        // Arrange
        $claims = ['user_id' => 123];
        $ttl = 3600;

        // Act
        $token = $this->jwt->encode($claims, $ttl, null);

        // Assert
        // Token should be decodable with the configured key
        $decoded = $this->jwt->decode($token);
        $this->assertEquals(123, $decoded['user_id'], 'Token should decode with configured key');
    }

    /**
     * Test that decode() successfully decodes a valid token.
     *
     * Verifies that decode() returns the original claims when given a valid token.
     */
    public function testDecodeValidToken(): void
    {
        // Arrange
        $claims = ['user_id' => 123, 'username' => 'testuser', 'role' => 'admin'];
        $ttl = 3600;
        $token = $this->jwt->encode($claims, $ttl);

        // Act
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'user_id should match');
        $this->assertEquals('testuser', $decoded['username'], 'username should match');
        $this->assertEquals('admin', $decoded['role'], 'role should match');
        $this->assertArrayHasKey('iat', $decoded, 'Token should contain iat');
        $this->assertArrayHasKey('exp', $decoded, 'Token should contain exp');
    }

    /**
     * Test that decode() throws ExpiredException for expired tokens.
     *
     * Verifies that decode() throws ExpiredException when token has expired.
     */
    public function testDecodeThrowsExpiredException(): void
    {
        // Arrange - Use mock clock for instant expiration without sleep
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 0, $clock);

        $claims = ['user_id' => 123];
        $token = $jwt->encode($claims, 1); // 1 second TTL - exp = 1000000001

        // Advance clock past expiration (no sleep needed!)
        $clock->advance(2.0);

        // Act & Assert
        $this->expectException(ExpiredException::class);

        $jwt->decode($token);
    }

    /**
     * Test that decode() respects leeway for expiration checking.
     *
     * Verifies that decode() allows tokens within leeway time even if technically expired.
     */
    public function testDecodeWithLeewayAllowsExpiredToken(): void
    {
        // Arrange - Use mock clock for instant expiration without sleep
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 5, $clock); // 5 second leeway

        $claims = ['user_id' => 123];
        $token = $jwt->encode($claims, 1); // 1 second TTL - exp = 1000000001

        // Advance clock by 2 seconds - token is expired but within 5 second leeway
        // Check: now (1000000002) > (exp (1000000001) + leeway (5)) => false (within leeway)
        $clock->advance(2.0);

        // Act
        $decoded = $jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'Token should decode with leeway');
    }

    /**
     * Test that decode() throws ExpiredException when token expires beyond leeway.
     *
     * Verifies that decode() throws ExpiredException when token has expired beyond the leeway time.
     */
    public function testDecodeWithLeewayThrowsExpiredExceptionBeyondLeeway(): void
    {
        // Arrange - Use mock clock for instant expiration without sleep
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 5, $clock); // 5 second leeway

        $claims = ['user_id' => 123];
        $token = $jwt->encode($claims, 1); // 1 second TTL - exp = 1000000001

        // Advance clock by 7 seconds - token is expired beyond 5 second leeway
        // Check: now (1000000007) > (exp (1000000001) + leeway (5)) => true (beyond leeway)
        $clock->advance(7.0);

        // Act & Assert
        $this->expectException(ExpiredException::class);

        $jwt->decode($token);
    }

    /**
     * Test that decode() throws ExpiredException at leeway boundary.
     *
     * Verifies that decode() throws ExpiredException when current time slightly exceeds exp + leeway.
     */
    public function testDecodeWithLeewayThrowsExpiredExceptionAtBoundary(): void
    {
        // Arrange - Use mock clock for instant expiration without sleep
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 5, $clock); // 5 second leeway

        $claims = ['user_id' => 123];
        $token = $jwt->encode($claims, 1); // 1 second TTL - exp = 1000000001

        // Advance clock by 6.1 seconds - token is slightly beyond leeway boundary
        // Check: now (1000000006.1) > (exp (1000000001) + leeway (5)) => true (beyond boundary, should throw)
        $clock->advance(6.1);

        // Act & Assert
        $this->expectException(ExpiredException::class);

        $jwt->decode($token);
    }

    /**
     * Test that decode() throws NotBeforeException when token is not yet active.
     *
     * Verifies that decode() throws NotBeforeException when nbf claim is in the future.
     */
    public function testDecodeThrowsNotBeforeException(): void
    {
        // Arrange
        $claims = ['user_id' => 123, 'nbf' => time() + 3600]; // Not active for 1 hour
        $token = $this->jwt->encode($claims, 7200); // 2 hour TTL

        // Act & Assert
        $this->expectException(NotBeforeException::class);

        $this->jwt->decode($token);
    }

    /**
     * Test that decode() respects leeway for not-before checking.
     *
     * Verifies that decode() allows tokens within leeway time even if nbf is slightly in the future.
     */
    public function testDecodeWithLeewayAllowsNotBeforeToken(): void
    {
        // Arrange - Use mock clock for controlled time
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 5, $clock); // 5 second leeway
        $claims = ['user_id' => 123, 'nbf' => 1000000003]; // Active in 3 seconds (1000000000 + 3)
        $token = $jwt->encode($claims, 3600);

        // Act
        $decoded = $jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'Token should decode with leeway');
    }

    /**
     * Test that decode() throws NotBeforeException when nbf is beyond leeway.
     *
     * Verifies that decode() throws NotBeforeException when nbf claim is in the future beyond leeway time.
     */
    public function testDecodeWithLeewayThrowsNotBeforeExceptionBeyondLeeway(): void
    {
        // Arrange - Use mock clock for controlled time
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 5, $clock); // 5 second leeway
        $claims = ['user_id' => 123, 'nbf' => 1000000007]; // Active in 7 seconds (1000000000 + 7)
        $token = $jwt->encode($claims, 3600);

        // Check: now (1000000000) < (nbf (1000000007) - leeway (5)) => 1000000000 < 1000000002 => true (beyond leeway)

        // Act & Assert
        $this->expectException(NotBeforeException::class);

        $jwt->decode($token);
    }

    /**
     * Test that decode() throws NotBeforeException at leeway boundary.
     *
     * Verifies that decode() throws NotBeforeException when nbf slightly exceeds now + leeway.
     */
    public function testDecodeWithLeewayThrowsNotBeforeExceptionAtBoundary(): void
    {
        // Arrange - Use mock clock for controlled time
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 5, $clock); // 5 second leeway
        $claims = ['user_id' => 123, 'nbf' => 1000000006]; // Active in 6 seconds (1000000000 + 6)
        $token = $jwt->encode($claims, 3600);

        // Check: now (1000000000) < (nbf (1000000006) - leeway (5)) => 1000000000 < 1000000001
        // => true (beyond boundary, should throw)

        // Act & Assert
        $this->expectException(NotBeforeException::class);

        $jwt->decode($token);
    }

    /**
     * Test that decode() throws SignatureException for invalid signature.
     *
     * Verifies that decode() throws SignatureException when signature verification fails.
     */
    public function testDecodeThrowsSignatureException(): void
    {
        // Arrange
        $claims = ['user_id' => 123];
        $token = $this->jwt->encode($claims, 3600);

        // Corrupt the signature
        $parts = explode('.', $token);
        $parts[2] = 'invalid-signature';
        $corruptedToken = implode('.', $parts);

        // Act & Assert
        $this->expectException(SignatureException::class);

        $this->jwt->decode($corruptedToken);
    }

    /**
     * Test that decode() throws KidMismatchException when using wrong kid.
     *
     * Verifies that decode() throws KidMismatchException when token header kid mismatches expected kid.
     */
    public function testDecodeThrowsKidMismatchExceptionWithWrongKid(): void
    {
        // Arrange
        $claims = ['user_id' => 123];
        $token = $this->jwt->encode($claims, 3600, 'tenant:acme-corp:v1');
        $wrongKid = 'tenant:other-corp:v1';

        // Act & Assert
        $this->expectException(KidMismatchException::class);

        $this->jwt->decode($token, $wrongKid);
    }

    public function testDecodePrefersKidMismatchBeforeSignatureValidation(): void
    {
        $token = $this->jwt->encode(['user_id' => 123], 3600, 'tenant:acme-corp:v1');
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        // Keep header/payload intact (kid mismatch path), but corrupt signature.
        $tampered = $parts[0] . '.' . $parts[1] . '.tampered-signature';

        $this->expectException(KidMismatchException::class);

        $this->jwt->decode($tampered, 'tenant:other-corp:v1');
    }

    /**
     * Test that decode() throws NoCredentialException for empty token.
     *
     * Verifies that decode() throws NoCredentialException when token is empty.
     */
    public function testDecodeThrowsExceptionForEmptyToken(): void
    {
        // Act & Assert
        $this->expectException(NoCredentialException::class);

        $this->jwt->decode('');
    }

    /**
     * Test that decode() throws MalformedException for invalid token format.
     *
     * Verifies that decode() throws MalformedException when token doesn't have three parts.
     */
    public function testDecodeThrowsMalformedExceptionForInvalidFormat(): void
    {
        // Arrange
        $invalidToken = 'invalid.token'; // Only 2 parts

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    /**
     * Test that decode() throws MalformedException for invalid payload.
     *
     * Verifies that decode() throws MalformedException when payload is not valid JSON.
     */
    public function testDecodeThrowsMalformedExceptionForInvalidPayload(): void
    {
        // Arrange
        $header = base64_encode('{"alg":"HS256","typ":"JWT"}');
        $invalidPayload = 'invalid-json-payload';
        $signature = 'signature';
        $invalidToken = "$header.$invalidPayload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    /**
     * Test that decode() throws MalformedException when payload JSON is not an object/array.
     */
    public function testDecodeThrowsMalformedExceptionForNonArrayClaimsPayload(): void
    {
        // Arrange - Payload is valid JSON but not an array/object
        $header = base64_encode('{"alg":"HS256","typ":"JWT"}');
        $payload = base64_encode('123'); // JSON number, not claims object
        $signature = 'signature';
        $invalidToken = "$header.$payload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    public function testDecodeThrowsSignatureExceptionWhenExpectedKidProvidedButTokenHasNoKid(): void
    {
        // Arrange - Token is signed with configured key (no kid), but decode expects a kid-derived key
        $token = $this->jwt->encode(['user_id' => 123], 3600);

        // Act & Assert
        $this->expectException(SignatureException::class);

        $this->jwt->decode($token, 'tenant:acme:v1');
    }

    public function testEncodeAndDecodeAllowEmptyStringKid(): void
    {
        // Arrange - Empty string kid is treated as an explicit kid (derived key boundary)
        $kid = '';
        $token = $this->jwt->encode(['user_id' => 123], 3600, $kid);

        // Act
        $decoded = $this->jwt->decode($token, $kid);

        // Assert
        $this->assertSame(123, $decoded['user_id']);

        $parts = explode('.', $token);
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $this->assertIsArray($header);
        $this->assertSame('', $header['kid'] ?? null);
    }

    public function testDecodeUsesEmptyStringKidFromHeaderWhenExpectedKidIsNull(): void
    {
        // Arrange
        $token = $this->jwt->encode(['user_id' => 123], 3600, '');

        // Act
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertSame(123, $decoded['user_id']);
    }

    public function testEncodeWithNegativeTtlRemovesExpClaim(): void
    {
        // Arrange
        $claims = ['user_id' => 123, 'exp' => 9999999999];

        // Act
        $token = $this->jwt->encode($claims, -1);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayNotHasKey('exp', $decoded);
        $this->assertSame(123, $decoded['user_id']);
    }

    public function testEncodeThrowsCoreExceptionForUnsupportedAlgorithm(): void
    {
        $jwt = $this->createJwt($this->testKeyHex, 'HS1024');

        $this->expectException(CoreException::class);

        $jwt->encode(['user_id' => 123], 3600);
    }

    public function testEncodeThrowsCoreExceptionForInvalidConfiguredKeyFormat(): void
    {
        // Odd length + non-hex characters
        $jwt = $this->createJwt('zz1', 'HS256');

        $this->expectException(CoreException::class);

        $jwt->encode(['user_id' => 123], 3600);
    }

    public function testEncodeThrowsCoreExceptionForEmptyConfiguredKey(): void
    {
        $jwt = $this->createJwt('', 'HS256');

        $this->expectException(CoreException::class);

        $jwt->encode(['user_id' => 123], 3600);
    }

    public function testEncodeThrowsCoreExceptionForUppercaseHexConfiguredKey(): void
    {
        $jwt = $this->createJwt('ABCDEF01ABCDEF01', 'HS256');

        $this->expectException(CoreException::class);

        $jwt->encode(['user_id' => 123], 3600);
    }

    public function testDecodeThrowsMalformedWhenHeaderJsonIsNullLiteral(): void
    {
        $header = $this->base64UrlEncode('null');
        $payload = $this->base64UrlEncode('{}');
        $token = $header . '.' . $payload . '.sig';

        $this->expectException(MalformedException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeRejectsBase64WithWhitespaceInHeaderSegment(): void
    {
        // Arrange - base64_decode strict mode should reject whitespace in the encoded segment
        $headerJson = '{"alg":"HS256","typ":"JWT"}';
        $header = $this->base64UrlEncode($headerJson) . " \n";
        $payload = $this->base64UrlEncode('{"user_id":123}');
        $signature = $this->base64UrlEncode('sig');
        $token = "$header.$payload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeRejectsHeaderAndPayloadWithExplicitPaddingBecauseSignatureChanges(): void
    {
        // Arrange - Some producers may include '=' padding even in URL-safe base64 for header/payload.
        // For JWT, signature is computed over the exact base64url segments. Adding padding changes the signed input.
        $token = $this->jwt->encode(['user_id' => 123], 3600);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $paddedHeader = $parts[0] . str_repeat('=', (4 - (strlen($parts[0]) % 4)) % 4);
        $paddedPayload = $parts[1] . str_repeat('=', (4 - (strlen($parts[1]) % 4)) % 4);
        $paddedToken = $paddedHeader . '.' . $paddedPayload . '.' . $parts[2];

        // Act & Assert
        $this->expectException(SignatureException::class);

        $this->jwt->decode($paddedToken);
    }

    public function testDecodeAcceptsPlusSlashBase64ForHeaderAndPayloadSegments(): void
    {
        // Arrange - Accept classic base64 characters (+/) in header/payload segments as a compatibility mode.
        // Signature is compared as a base64url string, so keep it unchanged.
        $token = $this->jwt->encode(['user_id' => 123], 3600);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $b64Header = strtr($parts[0], '-_', '+/');
        $b64Payload = strtr($parts[1], '-_', '+/');
        $b64Token = $b64Header . '.' . $b64Payload . '.' . $parts[2];

        // Act
        $decoded = $this->jwt->decode($b64Token);

        // Assert
        $this->assertSame(123, $decoded['user_id']);
    }

    public function testDecodeRejectsSignatureWithExplicitPaddingBecauseSignatureIsComparedAsString(): void
    {
        // Arrange - Signature is compared as base64url string, not decoded then compared.
        $token = $this->jwt->encode(['user_id' => 123], 3600);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $token = $parts[0] . '.' . $parts[1] . '.' . ($parts[2] . '==');

        // Act & Assert
        $this->expectException(SignatureException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeRejectsSignatureWithPlusSlashBase64Variant(): void
    {
        // Arrange - Even if '+'/' represents the same base64 bytes, signature comparison is string-based.
        // Ensure the signature segment actually contains '-' or '_' so the variant differs.
        $token = null;
        $parts = null;
        for ($i = 0; $i < 200; $i++) {
            $candidate = $this->jwt->encode(['user_id' => 123, 'nonce' => $i], 3600);
            $candidateParts = explode('.', $candidate);
            $this->assertCount(3, $candidateParts);
            if (str_contains($candidateParts[2], '-') || str_contains($candidateParts[2], '_')) {
                $token = $candidate;
                $parts = $candidateParts;
                break;
            }
        }
        $this->assertNotNull($token, 'Expected to find a token signature containing - or _');
        $this->assertIsArray($parts);

        $variantSignature = strtr($parts[2], '-_', '+/');
        $this->assertNotSame($parts[2], $variantSignature, 'Variant signature should differ');
        $token = $parts[0] . '.' . $parts[1] . '.' . $variantSignature;

        // Act & Assert
        $this->expectException(SignatureException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeRejectsSignatureContainingWhitespace(): void
    {
        // Arrange - Signature segment is not base64-decoded, so whitespace causes mismatch (SignatureException).
        $token = $this->jwt->encode(['user_id' => 123], 3600);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $token = $parts[0] . '.' . $parts[1] . '.' . ($parts[2] . " \n");

        // Act & Assert
        $this->expectException(SignatureException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeRejectsEmptySignatureSegment(): void
    {
        $token = $this->jwt->encode(['user_id' => 123], 3600);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $token = $parts[0] . '.' . $parts[1] . '.';

        $this->expectException(SignatureException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeThrowsMalformedExceptionForFourPartToken(): void
    {
        // Arrange - Must be exactly 3 segments.
        $token = $this->jwt->encode(['user_id' => 123], 3600) . '.extra';

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($token);
    }

    public function testBase64UrlDecodeHandlesMinimalPaddingCases(): void
    {
        // Arrange - Ensure our padding logic covers the common mod-4 cases.
        $this->assertSame('f', $this->base64UrlDecode('Zg'));
        $this->assertSame('fo', $this->base64UrlDecode('Zm8'));
        $this->assertSame('foo', $this->base64UrlDecode('Zm9v'));
        $this->assertSame('foob', $this->base64UrlDecode('Zm9vYg'));
        $this->assertSame('fooba', $this->base64UrlDecode('Zm9vYmE'));
        $this->assertSame('foobar', $this->base64UrlDecode('Zm9vYmFy'));
    }

    public function testDecodeThrowsMalformedExceptionForNonNumericExp(): void
    {
        // Arrange - encode() overwrites exp, so we must tamper payload and re-sign
        $token = $this->jwt->encode(['user_id' => 123], 3600);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        $this->assertIsArray($payload);
        $payload['exp'] = 'not-a-timestamp';

        $newPayloadPart = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $key = hex2bin($this->testKeyHex);
        $this->assertNotFalse($key);
        $data = $parts[0] . '.' . $newPayloadPart;
        $hmac = hash_hmac('sha256', $data, $key, true);
        $newSignaturePart = $this->base64UrlEncode($hmac);

        $token = $parts[0] . '.' . $newPayloadPart . '.' . $newSignaturePart;

        $this->expectException(MalformedException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeThrowsMalformedExceptionForNonNumericNbf(): void
    {
        $token = $this->jwt->encode(['user_id' => 123, 'nbf' => ['x']], 3600);

        $this->expectException(MalformedException::class);

        $this->jwt->decode($token);
    }

    /**
     * Test that decode() throws MalformedException for payload that cannot be base64 decoded.
     *
     * Verifies that decode() throws MalformedException when payload base64 decode returns null.
     */
    public function testDecodeThrowsMalformedExceptionForInvalidPayloadDecode(): void
    {
        // Arrange - Create token with invalid base64 payload (contains invalid characters)
        $header = base64_encode('{"alg":"HS256","typ":"JWT"}');
        $invalidPayload = 'invalid-base64!!!';
        $signature = 'signature';
        $invalidToken = "$header.$invalidPayload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    /**
     * Test that decode() throws MalformedException for invalid header decode.
     *
     * Verifies that decode() throws MalformedException when header cannot be base64 decoded.
     */
    public function testDecodeThrowsMalformedExceptionForInvalidHeaderDecode(): void
    {
        // Arrange - Create token with invalid base64 header (contains invalid characters)
        $invalidHeader = 'invalid-base64!!!';
        $payload = base64_encode('{"user_id":123}');
        $signature = 'signature';
        $invalidToken = "$invalidHeader.$payload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    /**
     * Test that decode() throws MalformedException for missing alg field in header.
     *
     * Verifies that decode() throws MalformedException when header is missing 'alg' field.
     */
    public function testDecodeThrowsMalformedExceptionForMissingAlg(): void
    {
        // Arrange - Create token with header missing 'alg' field
        $header = base64_encode('{"typ":"JWT"}'); // Missing 'alg'
        $payload = base64_encode('{"user_id":123}');
        $signature = 'signature';
        $invalidToken = "$header.$payload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    /**
     * Test that decode() accepts token when typ field is missing in header.
     */
    public function testDecodeAcceptsMissingTyp(): void
    {
        // Arrange
        $header = $this->base64UrlEncode('{"alg":"HS256"}'); // typ is optional
        $payload = $this->base64UrlEncode('{"user_id":123}');
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $this->testKey, true));
        $token = "$header.$payload.$signature";

        // Act
        $claims = $this->jwt->decode($token);

        // Assert
        $this->assertSame(123, $claims['user_id']);
    }

    /**
     * Test that decode() throws MalformedException for invalid typ field in header.
     *
     * Verifies that decode() throws MalformedException when header 'typ' is not 'JWT'.
     */
    public function testDecodeThrowsMalformedExceptionForInvalidTyp(): void
    {
        // Arrange - Create token with header 'typ' not 'JWT'
        $header = base64_encode('{"alg":"HS256","typ":"INVALID"}');
        $payload = base64_encode('{"user_id":123}');
        $signature = 'signature';
        $invalidToken = "$header.$payload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    /**
     * Test that decode() throws MalformedException when header kid is not string.
     */
    public function testDecodeThrowsMalformedExceptionForNonStringKid(): void
    {
        // Arrange - Header kid must be string, not number
        $header = base64_encode('{"alg":"HS256","typ":"JWT","kid":123}');
        $payload = base64_encode('{"user_id":123}');
        $signature = 'signature';
        $invalidToken = "$header.$payload.$signature";

        // Act & Assert
        $this->expectException(MalformedException::class);

        $this->jwt->decode($invalidToken);
    }

    /**
     * Test that decode() throws AlgorithmMismatchException for algorithm mismatch.
     *
     * Verifies that decode() throws AlgorithmMismatchException when token algorithm
     * doesn't match configured algorithm.
     */
    public function testDecodeThrowsAlgorithmMismatchException(): void
    {
        // Arrange - Create token with HS384
        $jwt384 = $this->createJwt($this->testKeyHex, 'HS384');
        $claims = ['user_id' => 123];
        $token = $jwt384->encode($claims, 3600);

        // Act & Assert - Try to decode with HS256
        $this->expectException(AlgorithmMismatchException::class);

        $this->jwt->decode($token);
    }

    public function testDecodeThrowsAlgorithmMismatchExceptionWhenHeaderAlgIsNonString(): void
    {
        $header = base64_encode('{"alg":123,"typ":"JWT"}');
        $payload = base64_encode('{"user_id":123}');
        $signature = 'signature';
        $token = "$header.$payload.$signature";

        $this->expectException(AlgorithmMismatchException::class);

        $this->jwt->decode($token);
    }

    /**
     * Test that decode() supports HS256 algorithm.
     */
    public function testDecodeWithHS256(): void
    {
        // Arrange
        $jwt = $this->createJwt($this->testKeyHex, 'HS256');
        $claims = ['user_id' => 123];
        $token = $jwt->encode($claims, 3600);

        // Act
        $decoded = $jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'HS256 token should decode correctly');
    }

    /**
     * Test that decode() supports HS384 algorithm.
     */
    public function testDecodeWithHS384(): void
    {
        // Arrange
        $jwt = $this->createJwt($this->testKeyHex, 'HS384');
        $claims = ['user_id' => 123];
        $token = $jwt->encode($claims, 3600);

        // Act
        $decoded = $jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'HS384 token should decode correctly');
    }

    /**
     * Test that decode() supports HS512 algorithm.
     */
    public function testDecodeWithHS512(): void
    {
        // Arrange
        $jwt = $this->createJwt($this->testKeyHex, 'HS512');
        $claims = ['user_id' => 123];
        $token = $jwt->encode($claims, 3600);

        // Act
        $decoded = $jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'HS512 token should decode correctly');
    }

    /**
     * Test that encode() and decode() work with complex claims.
     *
     * Verifies that encode() and decode() correctly handle nested arrays and various data types.
     */
    public function testEncodeDecodeWithComplexClaims(): void
    {
        // Arrange
        $claims = [
            'user_id' => 123,
            'username' => 'testuser',
            'roles' => ['admin', 'user'],
            'metadata' => [
                'created_at' => '2024-01-01',
                'active' => true,
            ],
            'score' => 95.5,
        ];
        $ttl = 3600;

        // Act
        $token = $this->jwt->encode($claims, $ttl);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id']);
        $this->assertEquals('testuser', $decoded['username']);
        $this->assertEquals(['admin', 'user'], $decoded['roles']);
        $this->assertEquals(['created_at' => '2024-01-01', 'active' => true], $decoded['metadata']);
        $this->assertEquals(95.5, $decoded['score']);
    }

    /**
     * Test that encode() uses key from CipherInterface when key is null.
     *
     * Verifies that encode() falls back to CipherInterface.getDerivedKey() when
     * both key parameter and configured key are null.
     */
    public function testEncodeUsesDerivedKeyFromCrypt(): void
    {
        // Arrange
        $jwt = $this->createJwt(null); // No configured key
        $claims = ['user_id' => 123];
        $ttl = 3600;

        // Act
        $token = $jwt->encode($claims, $ttl);

        // Assert
        // Token should be decodable with derived key
        $decoded = $jwt->decode($token);
        $this->assertEquals(123, $decoded['user_id'], 'Token should decode with derived key');
    }

    /**
     * Test that JWT derives algorithm-sized keys from CipherInterface.
     */
    public function testEncodeUsesAlgorithmSizedDerivedKey(): void
    {
        // Arrange
        $jwt = $this->createJwt(null, 'HS512');

        // Act
        $token = $jwt->encode(['user_id' => 123], 3600);
        $decoded = $jwt->decode($token);

        // Assert
        $this->assertSame(123, $decoded['user_id']);
    }

    public function testEncodeAcceptsConfiguredHexKeyWithoutAlgorithmLengthCheck(): void
    {
        $shortHexKey = str_repeat('ab', 8);
        $jwt = $this->createJwt($shortHexKey, 'HS512');

        $token = $jwt->encode(['user_id' => 123], 3600);
        $decoded = $jwt->decode($token);

        $this->assertSame(123, $decoded['user_id']);
    }

    /**
     * Test that encode() with zero TTL creates a never-expiring token.
     *
     * Verifies that encode() with TTL=0 omits the exp claim, making the token never expire.
     */
    public function testEncodeWithZeroTtlNeverExpires(): void
    {
        // Arrange
        $clock = new \Switon\Testing\MockClock(1000000000);
        $jwt = $this->createJwt($this->testKeyHex, 'HS256', 0, $clock);
        $claims = ['user_id' => 123];

        // Act
        $token = $jwt->encode($claims, 0);

        // Assert - Token has iat but no exp
        $decoded = $jwt->decode($token);
        $this->assertArrayHasKey('iat', $decoded, 'Token should contain iat claim');
        $this->assertArrayNotHasKey('exp', $decoded, 'Token with TTL=0 should not contain exp claim');

        // Assert - Token is still valid after a long time
        $clock->advance(86400 * 365.0); // 1 year later
        $decoded = $jwt->decode($token);
        $this->assertEquals(123, $decoded['user_id'], 'Token should still be valid after 1 year');
    }

    /**
     * Test that encode() with zero TTL removes user-provided exp claim.
     *
     * Verifies that encode() with TTL=0 strips any exp claim from user input,
     * ensuring the token truly never expires.
     */
    public function testEncodeWithZeroTtlRemovesUserProvidedExp(): void
    {
        // Arrange
        $claims = ['user_id' => 123, 'exp' => 9999999999];

        // Act
        $token = $this->jwt->encode($claims, 0);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertArrayNotHasKey('exp', $decoded, 'User-provided exp should be removed when TTL=0');
        $this->assertEquals(123, $decoded['user_id'], 'Other claims should be preserved');
    }

    /**
     * Test that encode() works with empty claims array.
     *
     * Verifies that encode() can create tokens with no user-defined claims,
     * only automatic iat and exp claims.
     */
    public function testEncodeWithEmptyClaims(): void
    {
        // Arrange
        $claims = [];
        $ttl = 3600;

        // Act
        $token = $this->jwt->encode($claims, $ttl);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertArrayHasKey('iat', $decoded, 'Token should contain iat claim');
        $this->assertArrayHasKey('exp', $decoded, 'Token should contain exp claim');
        $this->assertCount(2, $decoded, 'Token should only contain iat and exp for empty claims');
    }

    /**
     * Test that encode() with empty claims and zero TTL only adds iat.
     *
     * Verifies that encode() with TTL=0 only adds iat (no exp) for empty claims.
     */
    public function testEncodeWithEmptyClaimsAndZeroTtl(): void
    {
        // Arrange
        $claims = [];

        // Act
        $token = $this->jwt->encode($claims, 0);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertArrayHasKey('iat', $decoded, 'Token should contain iat claim');
        $this->assertArrayNotHasKey('exp', $decoded, 'Token with TTL=0 should not contain exp claim');
        $this->assertCount(1, $decoded, 'Token should only contain iat for empty claims with TTL=0');
    }

    /**
     * Test that encode() and decode() handle Unicode characters correctly.
     *
     * Verifies that tokens can contain Unicode characters including emoji,
     * Chinese characters, and other international text.
     */
    public function testEncodeDecodeWithUnicodeCharacters(): void
    {
        // Arrange
        $claims = [
            'username' => 'user@example.com',
            'emoji' => '😀🎉🚀',
            'chinese' => '你好世界',
            'arabic' => 'مرحبا بالعالم',
            'japanese' => 'こんにちは世界',
            'mixed' => 'Hello 世界 🌍',
        ];
        $ttl = 3600;

        // Act
        $token = $this->jwt->encode($claims, $ttl);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertEquals($claims['username'], $decoded['username'], 'Email should be preserved');
        $this->assertEquals($claims['emoji'], $decoded['emoji'], 'Emoji should be preserved');
        $this->assertEquals($claims['chinese'], $decoded['chinese'], 'Chinese characters should be preserved');
        $this->assertEquals($claims['arabic'], $decoded['arabic'], 'Arabic characters should be preserved');
        $this->assertEquals($claims['japanese'], $decoded['japanese'], 'Japanese characters should be preserved');
        $this->assertEquals($claims['mixed'], $decoded['mixed'], 'Mixed Unicode should be preserved');
    }

    /**
     * Test that encode() and decode() handle special HTML/JavaScript characters.
     *
     * Verifies that potentially dangerous characters are properly encoded and
     * do not cause injection vulnerabilities.
     */
    public function testEncodeDecodeWithSpecialCharacters(): void
    {
        // Arrange
        $claims = [
            'html' => '<script>alert("xss")</script>',
            'quotes' => 'It\'s a "test"',
            'backslash' => 'path\\to\\file',
            'newline' => "line1\nline2",
            'tab' => "col1\tcol2",
            'null_byte' => 'test' . chr(0) . 'data',
        ];
        $ttl = 3600;

        // Act
        $token = $this->jwt->encode($claims, $ttl);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertEquals($claims['html'], $decoded['html'], 'HTML tags should be preserved');
        $this->assertEquals($claims['quotes'], $decoded['quotes'], 'Quotes should be preserved');
        $this->assertEquals($claims['backslash'], $decoded['backslash'], 'Backslashes should be preserved');
        $this->assertEquals($claims['newline'], $decoded['newline'], 'Newlines should be preserved');
        $this->assertEquals($claims['tab'], $decoded['tab'], 'Tabs should be preserved');
        $this->assertEquals($claims['null_byte'], $decoded['null_byte'], 'Null bytes should be preserved');
    }

    /**
     * Test that encode() and decode() handle very large claim values.
     *
     * Verifies that tokens can contain large strings without issues.
     */
    public function testEncodeDecodeWithLargeClaims(): void
    {
        // Arrange
        $largeString = str_repeat('x', 10000); // 10KB string
        $claims = [
            'user_id' => 123,
            'large_data' => $largeString,
        ];
        $ttl = 3600;

        // Act
        $token = $this->jwt->encode($claims, $ttl);
        $decoded = $this->jwt->decode($token);

        // Assert
        $this->assertEquals(123, $decoded['user_id'], 'user_id should be preserved');
        $this->assertEquals($largeString, $decoded['large_data'], 'Large string should be preserved');
        $this->assertEquals(10000, strlen($decoded['large_data']), 'Large string length should match');
    }

    public function testJwtExceptionStatusCodeIs401(): void
    {
        try {
            JwtException::raise('jwt error');
            $this->fail('Expected JwtException to be thrown.');
        } catch (JwtException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

}
