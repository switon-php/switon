<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\Runtime;
use Switon\Core\Tests\Support\RuntimeStateReset;

/**
 * Test cases for Runtime class.
 *
 * Tests runtime environment state management including coroutine mode
 * and project root detection.
 */
class RuntimeTest extends TestCase
{
    /**
     * Reset Runtime state before each test.
     */
    protected function setUp(): void
    {
        $this->resetRuntimeState();
    }

    /**
     * Clean up after each test to prevent global state pollution.
     */
    protected function tearDown(): void
    {
        $this->resetRuntimeState();
        parent::tearDown();
    }

    protected function resetRuntimeState(): void
    {
        RuntimeStateReset::reset();
    }

    /**
     * Test that setCoroutineEnabled() and isCoroutineEnabled() work correctly.
     */
    public function testSetAndGetCoroutineEnabled(): void
    {
        // Default should be false
        $this->assertFalse(Runtime::isCoroutineEnabled());

        // Enable coroutine
        Runtime::setCoroutineEnabled(true);
        $this->assertTrue(Runtime::isCoroutineEnabled());

        // Disable coroutine
        Runtime::setCoroutineEnabled(false);
        $this->assertFalse(Runtime::isCoroutineEnabled());
    }

    /**
     * Test that getRoot() returns cached value on subsequent calls.
     */
    public function testGetRootCachesResult(): void
    {
        // Set root manually to avoid detection logic
        Runtime::setRoot('/test/root');

        $root1 = Runtime::getRoot();
        $root2 = Runtime::getRoot();

        $this->assertSame($root1, $root2);
        $this->assertSame('/test/root', $root1);
    }

    /**
     * Test that setRoot() normalizes path separators.
     */
    public function testSetRootNormalizesPathSeparators(): void
    {
        Runtime::setRoot('C:\\Users\\myapp\\');

        $root = Runtime::getRoot();
        $this->assertSame('C:/Users/myapp', $root);
    }

    /**
     * Test that setRoot() trims trailing slashes.
     */
    public function testSetRootTrimsTrailingSlashes(): void
    {
        Runtime::setRoot('/var/www/myapp/');

        $root = Runtime::getRoot();
        $this->assertSame('/var/www/myapp', $root);
    }

    /**
     * Test that getRoot() falls back to autoload.php detection.
     */
    public function testGetRootFallsBackToAutoloadDetection(): void
    {
        $root = Runtime::getRoot();

        $this->assertNotEmpty($root);
        $this->assertStringNotContainsString('@', $root);
    }

    /**
     * Test that getRoot() detects root from autoload.php in included files.
     */
    public function testGetRootFromAutoloadPhp(): void
    {
        // This test relies on autoload.php being in included files
        // which should be true in normal test execution
        $root = Runtime::getRoot();

        $this->assertIsString($root);
        $this->assertNotEmpty($root);
        $this->assertStringNotContainsString('\\', $root); // Should use forward slashes
        $this->assertStringEndsWithNot('/', $root); // Should not end with slash
    }

    /**
     * Test that setRoot() overrides automatic detection.
     */
    public function testSetRootOverridesAutomaticDetection(): void
    {
        // First get automatic detection result
        $autoRoot = Runtime::getRoot();

        // Reset cache to test override
        $this->resetRuntimeState();

        // Set custom root
        Runtime::setRoot('/custom/override/path');

        $root = Runtime::getRoot();
        $this->assertSame('/custom/override/path', $root);
        $this->assertNotSame($autoRoot, $root);
    }

    /**
     * Test that coroutine state is process-level (not reset between calls).
     */
    public function testCoroutineStateIsPersistent(): void
    {
        Runtime::setCoroutineEnabled(true);

        // Call multiple times
        $this->assertTrue(Runtime::isCoroutineEnabled());
        $this->assertTrue(Runtime::isCoroutineEnabled());
        $this->assertTrue(Runtime::isCoroutineEnabled());

        Runtime::setCoroutineEnabled(false);

        $this->assertFalse(Runtime::isCoroutineEnabled());
        $this->assertFalse(Runtime::isCoroutineEnabled());
    }

    /**
     * Test that getRoot() result is persistent across calls.
     */
    public function testGetRootResultIsPersistent(): void
    {
        Runtime::setRoot('/test/path');

        $root1 = Runtime::getRoot();
        $root2 = Runtime::getRoot();
        $root3 = Runtime::getRoot();

        $this->assertSame('/test/path', $root1);
        $this->assertSame($root1, $root2);
        $this->assertSame($root2, $root3);
    }

    /**
     * Helper method to check string does not end with suffix.
     */
    protected function assertStringEndsWithNot(string $suffix, string $string): void
    {
        $this->assertFalse(
            str_ends_with($string, $suffix),
            "Failed asserting that '$string' does not end with '$suffix'"
        );
    }
}
