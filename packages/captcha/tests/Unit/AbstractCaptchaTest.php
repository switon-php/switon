<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\CaptchaProof;
use Switon\Captcha\Exception\CaptchaAttemptsExhaustedException;
use Switon\Captcha\Exception\CaptchaDataInvalidException;
use Switon\Captcha\Exception\CaptchaExpiredException;
use Switon\Captcha\Exception\CaptchaMismatchException;
use Switon\Captcha\Exception\CaptchaNotFoundException;
use Switon\Captcha\Exception\CaptchaTooFrequentException;
use Switon\Captcha\Tests\Fixtures\TestCaptcha;
use Switon\Captcha\Tests\TestCase;

use function strlen;
use function strtolower;
use function strtoupper;
use function time;

/**
 * Test cases for AbstractCaptcha base class.
 *
 * Tests CAPTCHA creation, verification, storage interaction, and security features.
 */
class AbstractCaptchaTest extends TestCase
{
    protected TestCaptcha $captcha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captcha = $this->make(TestCaptcha::class);
    }

    // ========================================
    // Creation Tests
    // ========================================

    /**
     * Test that create() generates a CAPTCHA with default dimensions and structure.
     */
    public function testCreateWithDefaultDimensions(): void
    {
        // Act
        $result = $this->captcha->create();

        // Assert
        $this->assertIsArray($result, 'Result should be an array');
        $this->assertArrayHasKey('key', $result, 'Result should have key');
        $this->assertArrayHasKey('blob', $result, 'Result should have blob');
        $this->assertStringStartsWith('data:image/jpeg;base64,', $result['blob'], 'Image should be base64 data URI');

        $this->assertNotNull($this->captcha->getLastDrawnCode(), 'A code should have been drawn');
        $this->assertEquals(['width' => 120, 'height' => 40], $this->captcha->getLastDrawnDimensions(), 'Default dimensions should be 120x40');
    }

    /**
     * Test that create() generates a CAPTCHA with custom dimensions.
     */
    public function testCreateWithCustomDimensions(): void
    {
        // Act
        $this->captcha->create(150, 50);

        // Assert
        $this->assertEquals(['width' => 150, 'height' => 50], $this->captcha->getLastDrawnDimensions(), 'Custom dimensions should be used');
    }

    /**
     * Test that create() stores CAPTCHA data in Store.
     */
    public function testCreateStoresCaptchaData(): void
    {
        // Act
        $result = $this->captcha->create(100, 30, 300);

        // Assert
        $key = $result['key'];
        $storedData = $this->mockStore->get($key);

        $this->assertIsArray($storedData, 'Stored data should be an array');
        $this->assertArrayHasKey('code', $storedData, 'Stored data should have code');
        $this->assertArrayHasKey('created_at', $storedData, 'Stored data should have created_at');
        $this->assertArrayHasKey('ttl', $storedData, 'Stored data should have ttl');
        $this->assertSame(300, $storedData['ttl'], 'TTL should match provided value');
    }

    /**
     * Test that create() creates codes with correct length.
     */
    public function testCreateGeneratesCodeWithCorrectLength(): void
    {
        // Act
        $this->captcha->create();

        // Assert
        $drawnCode = $this->captcha->getLastDrawnCode();
        $this->assertSame($this->captcha->getLength(), strlen($drawnCode), 'Generated code length should match configured length');
    }

    /**
     * Test that create() uses only characters from charset.
     */
    public function testCreateUsesOnlyCharsetCharacters(): void
    {
        // Arrange
        $charset = strtoupper($this->captcha->getCharset());

        // Act
        $this->captcha->create();

        // Assert
        $drawnCode = $this->captcha->getLastDrawnCode();
        for ($i = 0; $i < strlen($drawnCode); $i++) {
            $this->assertStringContainsString($drawnCode[$i], $charset, "Character '{$drawnCode[$i]}' should be in charset");
        }
    }

    // ========================================
    // Verification Tests
    // ========================================

    /**
     * Test that verify() consumes attempt on success and keeps key with attempts=0.
     */
    public function testVerifyConsumesAttemptOnSuccess(): void
    {
        // Arrange (attempts = 1 by default)
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();
        $code = $this->captcha->getLastDrawnCode();

        // Act
        $this->captcha->verify(CaptchaProof::of($result['key'], $code));

        // Assert — key still exists with attempts=0, TTL handles cleanup
        $data = $this->mockStore->get($result['key']);
        $this->assertNotNull($data, 'Data should still exist after successful verify');
        $this->assertSame(0, $data['attempts'], 'Attempts should be 0 after final verify');
    }

    /**
     * Test that verify() allows retries if attempts > 1.
     */
    public function testVerifyAllowsRetries(): void
    {
        // Arrange
        $this->captcha->setAttempts(2);
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();
        $code = $this->captcha->getLastDrawnCode();

        // Act 1: First verify
        $this->captcha->verify(CaptchaProof::of($result['key'], $code));

        // Assert 1: Data should still exist (attempts -> 1)
        $data = $this->mockStore->get($result['key']);
        $this->assertNotNull($data, 'Data should exist after first verify');
        $this->assertEquals(1, $data['attempts'], 'Attempts should be decremented to 1');

        // Act 2: Second verify
        $this->captcha->verify(CaptchaProof::of($result['key'], $code));

        // Assert 2: Data should still exist with attempts=0
        $data = $this->mockStore->get($result['key']);
        $this->assertNotNull($data, 'Data should still exist after second verify');
        $this->assertSame(0, $data['attempts'], 'Attempts should be 0 after final verify');
    }

    /**
     * Test that verify() consumes attempt on failure.
     */
    public function testVerifyConsumesAttemptOnFailure(): void
    {
        // Arrange
        $this->captcha->setAttempts(2);
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();

        // Act 1: Wrong code
        try {
            $this->captcha->verify(CaptchaProof::of($result['key'], 'WRONG'));
        } catch (CaptchaMismatchException $e) {
            // Expected
        }

        // Assert 1: Data should still exist but attempts decremented
        $data = $this->mockStore->get($result['key']);
        $this->assertNotNull($data, 'Data should exist after failed attempt');
        $this->assertEquals(1, $data['attempts'], 'Attempts should be decremented to 1');

        // Act 2: Wrong code again — last attempt consumed
        try {
            $this->captcha->verify(CaptchaProof::of($result['key'], 'WRONG'));
        } catch (CaptchaMismatchException $e) {
            // Expected
        }

        // Assert 2: Data should still exist with attempts=0
        $data = $this->mockStore->get($result['key']);
        $this->assertNotNull($data, 'Data should still exist after exhausted attempts');
        $this->assertSame(0, $data['attempts'], 'Attempts should be 0');

        // Act 3: Any further verify should throw attempts exhausted
        $this->expectException(CaptchaAttemptsExhaustedException::class);
        $this->captcha->verify(CaptchaProof::of($result['key'], 'WRONG'));
    }

    /**
     * Test that verify() handles mixed failure and success.
     */
    public function testVerifyMixedFailureAndSuccess(): void
    {
        // Arrange
        $this->captcha->setAttempts(2);
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();
        $code = $this->captcha->getLastDrawnCode();

        // Act 1: Wrong code (Failure)
        try {
            $this->captcha->verify(CaptchaProof::of($result['key'], 'WRONG'));
        } catch (CaptchaMismatchException $e) {
            // Expected
        }

        // Assert 1: Data should still exist (attempts -> 1)
        $data = $this->mockStore->get($result['key']);
        $this->assertNotNull($data, 'Data should exist after first failed attempt');
        $this->assertEquals(1, $data['attempts'], 'Attempts should be decremented to 1');

        // Act 2: Correct code (Success) — last attempt consumed
        $this->captcha->verify(CaptchaProof::of($result['key'], $code));

        // Assert 2: Data should still exist with attempts=0
        $data = $this->mockStore->get($result['key']);
        $this->assertNotNull($data, 'Data should still exist after final successful verify');
        $this->assertSame(0, $data['attempts'], 'Attempts should be 0');

        // Act 3: Any further verify should throw attempts exhausted
        $this->expectException(CaptchaAttemptsExhaustedException::class);
        $this->captcha->verify(CaptchaProof::of($result['key'], $code));
    }

    /**
     * Test that verify() is case-insensitive (uppercase input).
     */
    public function testVerifyIsCaseInsensitiveUppercase(): void
    {
        // Arrange
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();
        $code = $this->captcha->getLastDrawnCode();

        // Act & Assert
        $this->captcha->verify(CaptchaProof::of($result['key'], strtoupper($code)));
        $this->assertTrue(true, 'Verification should succeed with uppercase input');
    }

    /**
     * Test that verify() is case-insensitive (lowercase input).
     */
    public function testVerifyIsCaseInsensitiveLowercase(): void
    {
        // Arrange
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();
        $code = $this->captcha->getLastDrawnCode();

        // Act & Assert
        $this->captcha->verify(CaptchaProof::of($result['key'], strtolower($code)));
        $this->assertTrue(true, 'Verification should succeed with lowercase input');
    }

    /**
     * Test that verify() throws exception when key not found.
     */
    public function testVerifyThrowsExceptionWhenKeyNotFound(): void
    {
        // Assert
        $this->expectException(CaptchaNotFoundException::class);

        // Act
        $this->captcha->verify(CaptchaProof::of('non-existent-key', 'ABCD'));
    }

    /**
     * Test that verify() throws exception when code is incorrect.
     */
    public function testVerifyThrowsExceptionWhenCodeMismatch(): void
    {
        // Arrange
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();

        // Assert
        $this->expectException(CaptchaMismatchException::class);

        // Act
        $this->captcha->verify(CaptchaProof::of($result['key'], 'WRONG'));
    }

    /**
     * Test that verify() throws exception when CAPTCHA expired.
     */
    public function testVerifyThrowsExceptionWhenExpired(): void
    {
        // Arrange
        $key = 'expired-key';
        $this->mockStore->put($key, [
            'code' => 'TEST',
            'created_at' => time() - 400, // 400s ago
            'ttl' => 300, // 300s TTL
        ], 300);

        // Assert
        $this->expectException(CaptchaExpiredException::class);

        // Act
        $this->captcha->verify(CaptchaProof::of($key, 'TEST'));
    }

    /**
     * Test that verify() throws exception when verification too frequent.
     */
    public function testVerifyThrowsExceptionWhenTooFrequent(): void
    {
        // Arrange
        $this->captcha->setMinInterval(5);
        $key = 'frequent-key';
        $this->mockStore->put($key, [
            'code' => 'TEST',
            'created_at' => time(), // Just created
            'ttl' => 300,
        ], 300);

        // Assert
        $this->expectException(CaptchaTooFrequentException::class);

        // Act
        $this->captcha->verify(CaptchaProof::of($key, 'TEST'));
    }

    /**
     * Test that verify() throws exception when data is corrupted.
     */
    public function testVerifyThrowsExceptionWhenDataInvalid(): void
    {
        // Arrange
        $key = 'invalid-data-key';
        // MockStore stores raw array, so we store incomplete array
        $this->mockStore->put($key, ['code' => 'TEST'], 300); // Missing created_at/ttl

        // Assert
        $this->expectException(CaptchaDataInvalidException::class);

        // Act
        $this->captcha->verify(CaptchaProof::of($key, 'TEST'));
    }

    /**
     * Test that verify() throws exception when attempts are exhausted.
     */
    public function testVerifyThrowsExceptionWhenAttemptsExhausted(): void
    {
        // Arrange
        $this->captcha->setMinInterval(0);
        $result = $this->captcha->create();
        $code = $this->captcha->getLastDrawnCode();

        // Exhaust the single attempt
        $this->captcha->verify(CaptchaProof::of($result['key'], $code));

        // Assert
        $this->expectException(CaptchaAttemptsExhaustedException::class);

        // Act
        $this->captcha->verify(CaptchaProof::of($result['key'], $code));
    }

    /**
     * Test that verify() defaults attempts to 1 when field is missing.
     */
    public function testVerifyDefaultsAttemptsWhenMissing(): void
    {
        // Arrange
        $this->captcha->setMinInterval(0);
        $key = 'missing-attempts-key';
        $this->mockStore->put($key, [
            'code' => 'TEST',
            'created_at' => time(),
            'ttl' => 300,
            // attempts intentionally omitted
        ], 300);

        // Act: first successful verify should consume the implicit single attempt.
        $this->captcha->verify(CaptchaProof::of($key, 'TEST'));

        // Assert: attempts should be persisted as 0.
        $data = $this->mockStore->get($key);
        $this->assertNotNull($data);
        $this->assertSame(0, $data['attempts']);

        // Any subsequent verify should fail as attempts exhausted.
        $this->expectException(CaptchaAttemptsExhaustedException::class);
        $this->captcha->verify(CaptchaProof::of($key, 'TEST'));
    }

    /**
     * Test that blocked verify does not consume attempts.
     */
    public function testVerifyTooFrequentDoesNotConsumeAttempts(): void
    {
        // Arrange
        $this->captcha->setMinInterval(5);
        $key = 'too-frequent-attempts-key';
        $this->mockStore->put($key, [
            'code' => 'TEST',
            'created_at' => time(),
            'ttl' => 300,
            'attempts' => 2,
        ], 300);

        // Act
        try {
            $this->captcha->verify(CaptchaProof::of($key, 'TEST'));
            $this->fail('Expected CaptchaTooFrequentException was not thrown');
        } catch (CaptchaTooFrequentException) {
            // expected
        }

        // Assert
        $data = $this->mockStore->get($key);
        $this->assertNotNull($data);
        $this->assertSame(2, $data['attempts'], 'Attempts should not change when blocked by min interval');
    }

    // ========================================
    // Configuration Tests
    // ========================================

}
