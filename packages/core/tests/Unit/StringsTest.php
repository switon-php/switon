<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use JsonSerializable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Core\Strings;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * Test cases for Strings class.
 *
 * Tests general string manipulation utilities.
 * For handler IDs and FQCN id(), see ClassNameTest.
 */
#[CoversClass(Strings::class)]
class StringsTest extends TestCase
{
    /**
     * Test that chain() builds chain string from elements.
     */
    public function testChainBuildsChainString(): void
    {
        // Arrange
        $elements = ['A', 'B', 'C'];

        // Act
        $result = Strings::chain($elements);

        // Assert
        $this->assertSame('A -> B -> C', $result);
    }

    /**
     * Test that chain() uses custom separator.
     */
    public function testChainUsesCustomSeparator(): void
    {
        // Arrange
        $elements = ['X', 'Y', 'Z'];

        // Act
        $result = Strings::chain($elements, ' | ');

        // Assert
        $this->assertSame('X | Y | Z', $result);
    }

    /**
     * Test that chain() appends element when provided.
     */
    public function testChainAppendsElement(): void
    {
        // Arrange
        $elements = ['service1', 'service2'];

        // Act
        $result = Strings::chain($elements, ' -> ', 'service3');

        // Assert
        $this->assertSame('service1 -> service2 -> service3', $result);
    }

    /**
     * Test that chain() handles empty array.
     */
    public function testChainHandlesEmptyArray(): void
    {
        // Arrange & Act
        $result = Strings::chain([]);

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * Test that chain() handles single element.
     */
    public function testChainHandlesSingleElement(): void
    {
        // Arrange & Act
        $result = Strings::chain(['only']);

        // Assert
        $this->assertSame('only', $result);
    }

    /**
     * Test that chain() handles empty array with append.
     */
    public function testChainHandlesEmptyArrayWithAppend(): void
    {
        // Arrange & Act
        $result = Strings::chain([], ' -> ', 'appended');

        // Assert
        $this->assertSame('appended', $result);
    }

    // ========== interpolate() tests ==========

    public function testInterpolateWithStringValues(): void
    {
        $message = 'Hello {name}, you are {age} years old';
        $context = ['name' => 'John', 'age' => '30'];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Hello John, you are 30 years old', $result);
    }

    public function testInterpolateWithIntegerValues(): void
    {
        $message = 'Count: {count}';
        $context = ['count' => 42];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Count: 42', $result);
    }

    public function testInterpolateWithFloatValues(): void
    {
        $message = 'Price: {price}';
        $context = ['price' => 19.99];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Price: 19.99', $result);
    }

    public function testInterpolateWithBooleanValues(): void
    {
        $message = 'Active: {active}';
        $context = ['active' => true];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Active: true', $result);
    }

    public function testInterpolateWithArrayValues(): void
    {
        $message = 'Items: {items}';
        $context = ['items' => ['a', 'b', 'c']];

        $result = Strings::interpolate($message, $context);

        $this->assertStringContainsString('Items:', $result);
        $this->assertStringContainsString('"a"', $result);
    }

    public function testInterpolateWithObjectValues(): void
    {
        $message = 'Data: {data}';
        $context = ['data' => (object)['key' => 'value']];

        $result = Strings::interpolate($message, $context);

        $this->assertStringContainsString('Data:', $result);
        $this->assertStringContainsString('"key"', $result);
    }

    public function testInterpolateWithJsonSerializable(): void
    {
        $jsonSerializable = new class () implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['key' => 'value'];
            }
        };

        $message = 'Data: {data}';
        $context = ['data' => $jsonSerializable];

        $result = Strings::interpolate($message, $context);

        $this->assertStringContainsString('Data:', $result);
        $this->assertStringContainsString('"key"', $result);
    }

    public function testInterpolateWithStringable(): void
    {
        $stringable = new class () {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $message = 'Value: {value}';
        $context = ['value' => $stringable];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Value: stringable-value', $result);
    }

    public function testInterpolateWithMissingContextKeys(): void
    {
        $message = 'Hello {name}, missing: {missing}';
        $context = ['name' => 'John'];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Hello John, missing: {missing}', $result);
    }

    public function testInterpolateWithNullValues(): void
    {
        $message = 'Value: {value}';
        $context = ['value' => null];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Value: {value}', $result);
    }

    /**
     * Values that are not string/scalar/array/object (e.g. resource) cannot be rendered; placeholder stays literal.
     * Documents {@see Strings::interpolate()} contract for log templates with partial context.
     */
    public function testInterpolateLeavesPlaceholderWhenValueCannotBeRendered(): void
    {
        $h = fopen('php://memory', 'rb');
        $this->assertNotFalse($h);

        try {
            $result = Strings::interpolate('pid {x}', ['x' => $h]);
        } finally {
            fclose($h);
        }

        $this->assertSame('pid {x}', $result);
    }

    public function testInterpolateWithNestedKeys(): void
    {
        $message = 'User: {user.name}';
        $context = ['user.name' => 'John'];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('User: John', $result);
    }

    public function testInterpolateWithStringableMessage(): void
    {
        $message = new class () {
            public function __toString(): string
            {
                return 'Hello {name}';
            }
        };
        $context = ['name' => 'John'];

        $result = Strings::interpolate($message, $context);

        $this->assertSame('Hello John', $result);
    }

    // ========== uuid() tests ==========

    public function testUuidMatchesFormat(): void
    {
        $uuid = Strings::uuid();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    // ========== isSlug() tests ==========

    public function testIsSlugAcceptsValidSlugs(): void
    {
        $this->assertTrue(Strings::isSlug('demo'));
        $this->assertTrue(Strings::isSlug('api-v2'));
        $this->assertTrue(Strings::isSlug('550e8400-e29b-41d4-a716-446655440000.png'));
    }

    public function testIsSlugRejectsInvalidSlugs(): void
    {
        $this->assertFalse(Strings::isSlug(''));
        $this->assertFalse(Strings::isSlug('.'));
        $this->assertFalse(Strings::isSlug('..'));
        $this->assertFalse(Strings::isSlug('a..b'));
        $this->assertFalse(Strings::isSlug('foo..md'));
        $this->assertFalse(Strings::isSlug('has space'));
        $this->assertFalse(Strings::isSlug('a/b'));
        $this->assertFalse(Strings::isSlug(str_repeat('a', 129)));
    }

    // ========== lines() / lf() / eol() ==========

    public function testLinesEmptyAndSingleLine(): void
    {
        $this->assertSame([], Strings::lines(''));
        $this->assertSame(['only'], Strings::lines('only'));
        $this->assertSame(['only'], Strings::lines("only\n"));
        $this->assertSame([''], Strings::lines("\n"));
        $this->assertSame(['', ''], Strings::lines("\n\n"));
    }

    public function testRawLinesKeepsTrailingEmptySegment(): void
    {
        $this->assertSame([''], Strings::rawLines(''));
        $this->assertSame(['only'], Strings::rawLines('only'));
        $this->assertSame(['only', ''], Strings::rawLines("only\n"));
        $this->assertSame(['a', 'b', ''], Strings::rawLines("a\r\nb\r\n"));
        $this->assertSame(['a', '', 'b'], Strings::rawLines("a\n\nb"));
        $this->assertSame(['', ''], Strings::rawLines("\n"));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function linesProvider(): array
    {
        return [
            'lf with trailing' => ["a\nb\n", ['a', 'b']],
            'lf without trailing' => ["a\nb", ['a', 'b']],
            'crlf with trailing' => ["a\r\nb\r\n", ['a', 'b']],
            'crlf without trailing' => ["a\r\nb", ['a', 'b']],
            'cr with trailing' => ["a\rb\r", ['a', 'b']],
            'cr without trailing' => ["a\rb", ['a', 'b']],
            'blank lf lines' => ["a\n\nb\n", ['a', '', 'b']],
            'blank crlf lines' => ["a\r\n\r\nb\r\n", ['a', '', 'b']],
            'mixed crlf then lf' => ["a\r\nb\nc", ['a', 'b', 'c']],
            'mixed lf then crlf' => ["a\nb\r\nc", ['a', 'b', 'c']],
            'cr then lf' => ["a\rb\nc", ['a', 'b', 'c']],
            'only crlfs' => ["\r\n\r\n", ['', '']],
        ];
    }

    #[DataProvider('linesProvider')]
    public function testLinesSplitsNewlines(string $text, array $expected): void
    {
        $this->assertSame($expected, Strings::lines($text));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function lfProvider(): array
    {
        return [
            'already lf' => ["a\nb\n", "a\nb\n"],
            'crlf' => ["a\r\nb\r\n", "a\nb\n"],
            'cr' => ["a\rb", "a\nb"],
            'mixed' => ["a\r\nb\rc\n", "a\nb\nc\n"],
            'empty' => ['', ''],
            'no newline' => ['plain', 'plain'],
        ];
    }

    #[DataProvider('lfProvider')]
    public function testLfNormalizesToUnix(string $text, string $expected): void
    {
        $this->assertSame($expected, Strings::lf($text));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function eolProvider(): array
    {
        return [
            'crlf wins over lf' => ["a\r\nb\n", "\r\n"],
            'crlf only' => ["a\r\nb\r\n", "\r\n"],
            'cr only' => ["a\rb", "\r"],
            'lf only' => ["a\nb", "\n"],
            'no newline defaults lf' => ['plain', "\n"],
            'empty defaults lf' => ['', "\n"],
            'lone cr before later content without crlf' => ["x\ry", "\r"],
        ];
    }

    #[DataProvider('eolProvider')]
    public function testEolDetectsPrimaryNewline(string $text, string $expected): void
    {
        $this->assertSame($expected, Strings::eol($text));
    }

    public function testLinesLfEolRoundTripForHomogeneousFiles(): void
    {
        foreach (["\n", "\r\n", "\r"] as $eol) {
            $raw = 'one' . $eol . 'two' . $eol . 'three' . $eol;
            $this->assertSame(['one', 'two', 'three'], Strings::lines($raw));
            $this->assertSame($eol, Strings::eol($raw));
            $rejoined = implode($eol, Strings::lines($raw)) . $eol;
            $this->assertSame($raw, $rejoined);
        }
    }

    // ========== renderException() tests ==========

    public function testRenderExceptionFormatsException(): void
    {
        $exception = new RuntimeException('Test exception');

        $result = Strings::renderException($exception);

        $this->assertStringContainsString('RuntimeException: Test exception', $result);
        $this->assertStringContainsString('at', $result);
    }

    /**
     * Asserts the documented output format: "ClassName: message", then "    at file:line", then "    at file(line): ...".
     * Keeps docs/en/log/README.md exception example in sync with Strings::renderException().
     */
    public function testRenderExceptionOutputFormat(): void
    {
        $exception = new RuntimeException('Insufficient funds');

        $result = Strings::renderException($exception);

        $lines = explode("\n", $result);
        $this->assertGreaterThanOrEqual(2, count($lines), 'At least exception line and one at line');

        // First line: Full\ClassName: message (no "Stack trace:" label)
        $this->assertStringContainsString('RuntimeException: Insufficient funds', $lines[0]);
        $this->assertStringNotContainsString('Stack trace', $result);

        // Second line: "    at file:line"
        $this->assertMatchesRegularExpression('/^\s+at .+:\d+$/', $lines[1], 'Second line must be "    at file:line"');

        // Trace lines use "    at " prefix (not "#0 ")
        foreach (array_slice($lines, 2) as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringStartsWith('    at ', $line, 'Trace lines must start with "    at "');
        }
    }

    public function testRenderExceptionWithPreviousException(): void
    {
        $previous = new InvalidArgumentException('Previous exception');
        $exception = new RuntimeException('Test exception', 0, $previous);

        $result = Strings::renderException($exception);

        $this->assertStringContainsString('RuntimeException: Test exception', $result);
        $this->assertStringContainsString('Caused by', $result);
        $this->assertStringContainsString('InvalidArgumentException: Previous exception', $result);
    }

    /** Previous created in another stack frame so trace strings differ from the wrapping exception. */
    protected static function newInvalidArgumentForRenderChain(string $message): InvalidArgumentException
    {
        return new InvalidArgumentException($message);
    }

    public function testRenderExceptionAppendsPreviousTraceWhenStacksDiffer(): void
    {
        $previous = self::newInvalidArgumentForRenderChain('inner');
        $exception = new RuntimeException('outer', 0, $previous);

        $result = Strings::renderException($exception);

        $this->assertStringContainsString('Caused by', $result);
        $this->assertStringNotContainsString('    at ...', $result);
        $this->assertStringContainsString('newInvalidArgumentForRenderChain', $result);
    }

    public function testRenderExceptionWithRootStripsPrefix(): void
    {
        $exception = new RuntimeException('Test exception');

        $result = Strings::renderException($exception, __DIR__);

        $this->assertStringContainsString('RuntimeException: Test exception', $result);
        // Path prefix should be stripped
        $this->assertStringNotContainsString(__DIR__, $result);
    }

    public function testCutByCodePoints(): void
    {
        $this->assertSame('你好', Strings::cut('你好世界', 2));
        $this->assertSame('你好…', Strings::cut('你好世界', 3, '…'));
        $this->assertSame('abc', Strings::cut('abc', 10));
        $this->assertSame('', Strings::cut('abc', 0));
    }

    public function testCutBytesUtf8Safe(): void
    {
        // "你" is 3 bytes; budget 4 must not split the next code point.
        $this->assertSame('你', Strings::cutBytes('你好', 4));
        $this->assertSame('你…', Strings::cutBytes('你好世界', 6, '…'));
        $this->assertSame('abc', Strings::cutBytes('abc', 10));
        $this->assertSame('', Strings::cutBytes('abc', 0));
    }

    public function testSquishCollapsesWhitespaceAndTrims(): void
    {
        $this->assertSame('hello world', Strings::squish("  hello   \n\t  world  "));
        $this->assertSame('a b', Strings::squish("a\u{00A0}\u{00A0}b"));
        $this->assertSame('', Strings::squish(" \n\t "));
        $this->assertSame('ok', Strings::squish('ok'));
    }

}
