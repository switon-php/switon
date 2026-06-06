<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Switon\Captcha\Exception\CaptchaAttemptsExhaustedException;
use Switon\Captcha\Exception\CaptchaDataInvalidException;
use Switon\Captcha\Exception\CaptchaExpiredException;
use Switon\Captcha\Exception\CaptchaMismatchException;
use Switon\Captcha\Exception\CaptchaNotFoundException;
use Switon\Captcha\Exception\CaptchaTooFrequentException;
use Switon\Captcha\Exception\InvalidCaptchaException;

/**
 * Test cases for CAPTCHA exception classes.
 *
 * Tests exception hierarchy, HTTP status codes, and JSON response format.
 */
class InvalidCaptchaExceptionTest extends TestCase
{
    /**
     * Test that InvalidCaptchaException returns HTTP 200.
     */
    public function testStatusCodeIs200(): void
    {
        // Arrange
        $exception = InvalidCaptchaException::of('Test error');

        // Act
        $statusCode = $exception->getStatusCode();

        // Assert
        $this->assertSame(200, $statusCode, 'Invalid captcha exception should return HTTP 200');
    }

    /**
     * Test that getJson() returns correct structure.
     */
    public function testGetJsonReturnsCorrectStructure(): void
    {
        // Arrange
        $exception = InvalidCaptchaException::of('Test error');

        // Act
        $json = $exception->getJson();

        // Assert
        $this->assertIsArray($json, 'getJson() should return an array');
        $this->assertArrayHasKey('code', $json, 'JSON should have code key');
        $this->assertArrayHasKey('msg', $json, 'JSON should have msg key');
        $this->assertArrayHasKey('field', $json, 'JSON should have field key');
        $this->assertSame(-1, $json['code'], 'Code should be -1');
        $this->assertSame('captcha', $json['field'], 'Field should be captcha');
    }

    /**
     * Test that getJson() returns message key for i18n.
     */
    public function testGetJsonReturnsMessageKey(): void
    {
        // Arrange
        $exception = InvalidCaptchaException::of('Test error');

        // Act
        $json = $exception->getJson();

        // Assert
        $this->assertSame('captcha.invalid', $json['msg'], 'Message should be translation key');
    }

    /**
     * Test that all subclasses inherit from InvalidCaptchaException.
     */
    #[DataProvider('exceptionClassProvider')]
    public function testSubclassesInheritFromInvalidCaptchaException(string $className): void
    {
        // Arrange
        $exception = $className::of('Test error');

        // Assert
        $this->assertInstanceOf(InvalidCaptchaException::class, $exception, "{$className} should extend InvalidCaptchaException");
    }

    /**
     * Test that all subclasses return HTTP 200.
     */
    #[DataProvider('exceptionClassProvider')]
    public function testSubclassesReturnHttp200(string $className): void
    {
        // Arrange
        $exception = $className::of('Test error');

        // Act
        $statusCode = $exception->getStatusCode();

        // Assert
        $this->assertSame(200, $statusCode, "{$className} should return HTTP 200");
    }

    /**
     * Provides all CAPTCHA exception classes for testing.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function exceptionClassProvider(): array
    {
        return [
            'CaptchaNotFoundException' => [CaptchaNotFoundException::class],
            'CaptchaDataInvalidException' => [CaptchaDataInvalidException::class],
            'CaptchaExpiredException' => [CaptchaExpiredException::class],
            'CaptchaMismatchException' => [CaptchaMismatchException::class],
            'CaptchaTooFrequentException' => [CaptchaTooFrequentException::class],
            'CaptchaAttemptsExhaustedException' => [CaptchaAttemptsExhaustedException::class],
        ];
    }

    /**
     * Test that exception raise() method throws.
     */
    public function testRaiseThrowsException(): void
    {
        // Assert
        $this->expectException(InvalidCaptchaException::class);

        // Act
        InvalidCaptchaException::raise('Test error');
    }

    /**
     * Test that exception supports message templates.
     */
    public function testExceptionSupportsMessageTemplates(): void
    {
        // Arrange
        $exception = CaptchaNotFoundException::of('Captcha not found: {session_var}', ['session_var' => 'my_captcha']);

        // Assert
        $this->assertSame('Captcha not found: my_captcha', $exception->getMessage(), 'Message template should be interpolated');
    }
}
