<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use Switon\Db\PreparedEmulator;
use PHPUnit\Framework\TestCase;

/**
 * Test cases for PreparedEmulator class.
 *
 * Tests SQL parameter binding emulation functionality.
 */
class PreparedEmulatorTest extends TestCase
{
    protected PreparedEmulator $emulator;

    protected function setUp(): void
    {
        $this->emulator = new PreparedEmulator();
    }

    /**
     * Test that emulate() returns SQL unchanged when bind is empty.
     */
    public function testEmulateReturnsSqlUnchangedWhenBindEmpty(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE id = :id';
        // Act
        $result = $this->emulator->emulate($sql, []);
        // Assert
        $this->assertSame($sql, $result);
    }

    /**
     * Test that emulate() returns SQL unchanged when bind uses numeric keys.
     */
    public function testEmulateReturnsSqlUnchangedWhenBindHasNumericKeys(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE id = ?';
        // Act
        $result = $this->emulator->emulate($sql, [1, 2, 3]);
        // Assert
        $this->assertSame($sql, $result);
    }

    /**
     * Test that emulate() replaces named parameters with string values.
     */
    public function testEmulateReplacesNamedParametersWithStringValues(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE name = :name AND email = :email';
        $bind = ['name' => 'John', 'email' => 'john@example.com'];
        // Act
        $result = $this->emulator->emulate($sql, $bind);
        // Assert
        // Verify parameters were replaced (not present in result)
        $this->assertStringNotContainsString(':name', $result);
        $this->assertStringNotContainsString(':email', $result);
        // Verify values are present (format may vary, but values should be there)
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('john@example.com', $result);
    }

    /**
     * Test that emulate() replaces named parameters with integer values.
     */
    public function testEmulateReplacesNamedParametersWithIntegerValues(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE id = :id AND age = :age';
        $bind = ['id' => 123, 'age' => 25];
        // Act
        $result = $this->emulator->emulate($sql, $bind);
        // Assert
        // Verify parameters were replaced
        $this->assertStringNotContainsString(':id', $result);
        $this->assertStringNotContainsString(':age', $result);
        // Verify integer values are present
        $this->assertStringContainsString('123', $result);
        $this->assertStringContainsString('25', $result);
    }

    /**
     * Test that emulate() replaces named parameters with null values.
     */
    public function testEmulateReplacesNamedParametersWithNullValues(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE deleted_at = :deleted_at';
        $bind = ['deleted_at' => null];
        // Act
        $result = $this->emulator->emulate($sql, $bind);
        // Assert
        // Verify parameter was replaced
        $this->assertStringNotContainsString(':deleted_at', $result);
        // Verify NULL value is present
        $this->assertStringContainsString('NULL', $result);
    }

    /**
     * Test that emulate() replaces named parameters with boolean values.
     */
    public function testEmulateReplacesNamedParametersWithBooleanValues(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE active = :active';
        $bind = ['active' => true];
        // Act
        $result = $this->emulator->emulate($sql, $bind);
        // Assert
        // Verify parameter was replaced
        $this->assertStringNotContainsString(':active', $result);
        // Verify boolean true is converted to 1
        $this->assertStringContainsString('1', $result);

        // Arrange
        $bind = ['active' => false];
        // Act
        $result = $this->emulator->emulate($sql, $bind);
        // Assert
        // Verify boolean false is converted to 0
        $this->assertStringContainsString('0', $result);
    }

    /**
     * Test that emulate() escapes single quotes in string values.
     */
    public function testEmulateEscapesSingleQuotesInStringValues(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE name = :name';
        $bind = ['name' => "O'Brien"];
        // Act
        $result = $this->emulator->emulate($sql, $bind);
        // Assert
        // Verify parameter was replaced
        $this->assertStringNotContainsString(':name', $result);
        // Verify single quote is escaped (not present as-is)
        $this->assertStringNotContainsString("O'Brien", $result);
        // Verify escaped quote is present
        $this->assertStringContainsString("\\'", $result);
    }

    /**
     * Test that emulate() truncates long strings when preservedStrLength is set.
     */
    public function testEmulateTruncatesLongStringsWhenPreservedStrLengthSet(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE description = :description';
        $bind = ['description' => str_repeat('a', 100)];
        // Act
        $result = $this->emulator->emulate($sql, $bind, 20);
        // Assert
        $this->assertStringContainsString('...', $result);
        // The preservedStrLength applies to the quoted string length, not the entire SQL
        // So we just verify that truncation occurred
        $this->assertStringNotContainsString(str_repeat('a', 100), $result);
    }

    /**
     * Test that emulate() handles multiple parameters in complex SQL.
     */
    public function testEmulateHandlesMultipleParametersInComplexSql(): void
    {
        // Arrange
        $sql = 'INSERT INTO users (name, email, age, active) VALUES (:name, :email, :age, :active)';
        $bind = [
            'name' => 'John',
            'email' => 'john@example.com',
            'age' => 25,
            'active' => true,
        ];
        // Act
        $result = $this->emulator->emulate($sql, $bind);
        // Assert
        // Verify all parameters were replaced
        $this->assertStringNotContainsString(':name', $result);
        $this->assertStringNotContainsString(':email', $result);
        $this->assertStringNotContainsString(':age', $result);
        $this->assertStringNotContainsString(':active', $result);
        // Verify values are present (format may vary)
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('john@example.com', $result);
        $this->assertStringContainsString('25', $result);
        $this->assertStringContainsString('1', $result);
    }

    /**
     * Test that emulate() does not truncate short strings when preservedStrLength is set.
     */
    public function testEmulateDoesNotTruncateShortStringsWhenPreservedStrLengthSet(): void
    {
        // Arrange
        $sql = 'SELECT * FROM users WHERE name = :name';
        $bind = ['name' => 'John'];
        // Act
        $result = $this->emulator->emulate($sql, $bind, 100);
        // Assert
        // Verify string was not truncated (no '...' in result)
        $this->assertStringNotContainsString('...', $result);
        // Verify full value is present
        $this->assertStringContainsString('John', $result);
    }
}
