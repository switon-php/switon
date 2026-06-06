<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Routing\PatternCompiler;
use Switon\Routing\Tests\TestCase;

/**
 * Tests for PatternCompiler functionality.
 *
 * Verifies pattern compilation, snippet expansion, parameter types,
 * and custom regex patterns.
 */
#[AllowMockObjectsWithoutExpectations]
class PatternCompilerTest extends TestCase
{
    protected PatternCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock app that returns false for isEnv('dev')
        $mockApp = $this->createMock(\Switon\Core\App::class);
        $mockApp->method('isEnv')->willReturn(false);
        $this->container->set(\Switon\Core\App::class, $mockApp);

        // Create PatternCompiler via container - dependencies will be autowired
        $this->compiler = $this->container->make(PatternCompiler::class);
    }

    /**
     * Test that literal patterns (no parameters) are returned as-is.
     */
    public function testLiteralPatternReturnedAsIs(): void
    {
        $pattern = '/users';
        $compiled = $this->compiler->compile($pattern);

        $this->assertEquals('/users', $compiled);
    }

    /**
     * Test basic integer parameter compilation.
     */
    public function testBasicIntegerParameter(): void
    {
        $pattern = '/users/{id}';
        $compiled = $this->compiler->compile($pattern);

        // Should compile to regex with named capture group for digits
        $this->assertStringContainsString('(?<id>', $compiled);
        $this->assertStringContainsString('\d+', $compiled);
        $this->assertStringStartsWith('#^', $compiled);
        $this->assertStringEndsWith('$#', $compiled);
    }

    /**
     * Test explicit integer type parameter.
     */
    public function testExplicitIntegerType(): void
    {
        $pattern = '/users/{id:int}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<id>\d+)', $compiled);
    }

    /**
     * Test variable name parameter type.
     */
    public function testVariableNameParameter(): void
    {
        $pattern = '/posts/{slug:var}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<slug>', $compiled);
        $this->assertStringContainsString('[a-zA-Z]', $compiled);
    }

    /**
     * Test UUID parameter type.
     */
    public function testUuidParameter(): void
    {
        $pattern = '/items/{uuid:uuid}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<uuid>', $compiled);
        $this->assertStringContainsString('[A-Fa-f0-9]{8}', $compiled);
    }

    /**
     * Test slug parameter type.
     */
    public function testSlugParameter(): void
    {
        $pattern = '/articles/{slug:slug}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<slug>', $compiled);
        $this->assertStringContainsString('[a-z0-9]+', $compiled);
    }

    /**
     * Test alpha parameter type (letters only).
     */
    public function testAlphaParameter(): void
    {
        $pattern = '/tags/{name:alpha}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<name>[a-zA-Z]+)', $compiled);
    }

    /**
     * Test alphanumeric parameter type.
     */
    public function testAlphanumParameter(): void
    {
        $pattern = '/codes/{code:alphanum}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<code>[a-zA-Z0-9]+)', $compiled);
    }

    /**
     * Test any parameter type (matches everything).
     */
    public function testAnyParameter(): void
    {
        $pattern = '/files/{path:any}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<path>.+)', $compiled);
    }

    /**
     * Test custom regex pattern.
     */
    public function testCustomRegexPattern(): void
    {
        $pattern = '/codes/{code:[A-Z]{3}-\d{4}}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<code>[A-Z]{3}-\d{4})', $compiled);
    }

    /**
     * Test multiple parameters in one pattern.
     */
    public function testMultipleParameters(): void
    {
        $pattern = '/users/{id}/posts/{slug:slug}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringContainsString('(?<id>', $compiled);
        $this->assertStringContainsString('(?<slug>', $compiled);
        $this->assertStringContainsString('\d+', $compiled);
        $this->assertStringContainsString('[a-z0-9]+', $compiled);
    }

    /**
     * Test that compiled pattern has proper regex anchors.
     */
    public function testCompiledPatternHasAnchors(): void
    {
        $pattern = '/users/{id}';
        $compiled = $this->compiler->compile($pattern);

        $this->assertStringStartsWith('#^', $compiled);
        $this->assertStringEndsWith('$#', $compiled);
    }

    /**
     * Test that compiled pattern matches expected URIs.
     */
    public function testCompiledPatternMatchesExpectedUris(): void
    {
        $pattern = '/users/{id}';
        $compiled = $this->compiler->compile($pattern);

        // Should match
        $this->assertEquals(1, preg_match($compiled, '/users/123'));
        $this->assertEquals(1, preg_match($compiled, '/users/456'));

        // Should not match
        $this->assertEquals(0, preg_match($compiled, '/users'));
        $this->assertEquals(0, preg_match($compiled, '/users/abc'));
        $this->assertEquals(0, preg_match($compiled, '/posts/123'));
    }

    /**
     * Test parameter extraction from compiled pattern.
     */
    public function testParameterExtraction(): void
    {
        $pattern = '/users/{id}/posts/{slug:slug}';
        $compiled = $this->compiler->compile($pattern);

        $matches = [];
        preg_match($compiled, '/users/123/posts/my-article', $matches);

        $this->assertEquals('123', $matches['id']);
        $this->assertEquals('my-article', $matches['slug']);
    }

    /**
     * Test optional parameter pattern.
     */
    public function testOptionalParameter(): void
    {
        $pattern = '/users(/{id})?';
        $compiled = $this->compiler->compile($pattern);

        // Should match both with and without ID
        $this->assertEquals(1, preg_match($compiled, '/users'));
        $this->assertEquals(1, preg_match($compiled, '/users/123'));
    }

    /**
     * Test pattern with numeric quantifiers (e.g., {1,3}).
     */
    public function testNumericQuantifiersPreserved(): void
    {
        $pattern = '/codes/{code:[A-Z]{3}}';
        $compiled = $this->compiler->compile($pattern);

        // Numeric quantifier {3} should be preserved
        $this->assertStringContainsString('{3}', $compiled);

        // Should match exactly 3 uppercase letters
        $this->assertEquals(1, preg_match($compiled, '/codes/ABC'));
        $this->assertEquals(0, preg_match($compiled, '/codes/AB'));
        $this->assertEquals(0, preg_match($compiled, '/codes/ABCD'));
    }

    /**
     * Test controller snippet expansion.
     */
    public function testControllerSnippetExpansion(): void
    {
        $pattern = '/{controller}/{action}';
        $compiled = $this->compiler->compile($pattern);

        // Snippets should be expanded to var type
        $this->assertStringContainsString('(?<controller>', $compiled);
        $this->assertStringContainsString('(?<action>', $compiled);
        $this->assertStringContainsString('[a-zA-Z]', $compiled);
    }

    /**
     * Test pattern with no type defaults to [^/]+.
     */
    public function testParameterWithoutTypeDefaultsToNonSlash(): void
    {
        $pattern = '/items/{name}';
        $compiled = $this->compiler->compile($pattern);

        // Default pattern should match anything except slashes
        $this->assertStringContainsString('(?<name>', $compiled);

        // Should match
        $this->assertEquals(1, preg_match($compiled, '/items/abc'));
        $this->assertEquals(1, preg_match($compiled, '/items/123'));
        $this->assertEquals(1, preg_match($compiled, '/items/abc-123'));

        // Should not match (contains slash)
        $this->assertEquals(0, preg_match($compiled, '/items/abc/def'));
    }

    /**
     * Test complex pattern with mixed parameter types.
     */
    public function testComplexPatternWithMixedTypes(): void
    {
        $pattern = '/api/{version:int}/users/{id}/posts/{slug:slug}';
        $compiled = $this->compiler->compile($pattern);

        $matches = [];
        preg_match($compiled, '/api/2/users/123/posts/my-article', $matches);

        $this->assertEquals('2', $matches['version']);
        $this->assertEquals('123', $matches['id']);
        $this->assertEquals('my-article', $matches['slug']);
    }

    /**
     * Test that pattern is case-sensitive by default.
     */
    public function testPatternIsCaseSensitiveByDefault(): void
    {
        $pattern = '/Users/{id}';
        $compiled = $this->compiler->compile($pattern);

        // Should not have 'i' flag
        $this->assertStringNotContainsString('#i', $compiled);

        // Should be case-sensitive
        $this->assertEquals(1, preg_match($compiled, '/Users/123'));
        $this->assertEquals(0, preg_match($compiled, '/users/123'));
    }

    /**
     * Test slug pattern matches valid slugs.
     */
    public function testSlugPatternMatchesValidSlugs(): void
    {
        $pattern = '/posts/{slug:slug}';
        $compiled = $this->compiler->compile($pattern);

        // Valid slugs
        $this->assertEquals(1, preg_match($compiled, '/posts/my-article'));
        $this->assertEquals(1, preg_match($compiled, '/posts/hello-world-123'));
        $this->assertEquals(1, preg_match($compiled, '/posts/test'));

        // Invalid slugs
        $this->assertEquals(0, preg_match($compiled, '/posts/My-Article')); // uppercase
        $this->assertEquals(0, preg_match($compiled, '/posts/hello_world')); // underscore
        $this->assertEquals(0, preg_match($compiled, '/posts/-hello')); // starts with dash
    }

    /**
     * Test UUID pattern matches valid UUIDs.
     */
    public function testUuidPatternMatchesValidUuids(): void
    {
        $pattern = '/items/{uuid:uuid}';
        $compiled = $this->compiler->compile($pattern);

        // Valid UUID
        $this->assertEquals(1, preg_match($compiled, '/items/550e8400-e29b-41d4-a716-446655440000'));
        $this->assertEquals(1, preg_match($compiled, '/items/A1B2C3D4-E5F6-7890-ABCD-EF1234567890'));

        // Invalid UUID
        $this->assertEquals(0, preg_match($compiled, '/items/not-a-uuid'));
        $this->assertEquals(0, preg_match($compiled, '/items/550e8400-e29b-41d4-a716')); // too short
    }
}
