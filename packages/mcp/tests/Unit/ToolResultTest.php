<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\ToolResult;

#[CoversClass(ToolResult::class)]
class ToolResultTest extends TestCase
{
    public function testThrowableContentMarksError(): void
    {
        $error = new RuntimeException('x');
        $result = new ToolResult($error);

        $this->assertTrue($result->isError);
        $this->assertSame($error, $result->content);
    }

    public function testPlainContentStaysSuccessful(): void
    {
        $result = new ToolResult('ok');

        $this->assertFalse($result->isError);
        $this->assertSame('ok', $result->content);
    }
}
