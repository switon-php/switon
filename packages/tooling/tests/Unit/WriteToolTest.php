<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\WriteTool;

#[CoversClass(WriteTool::class)]
class WriteToolTest extends ToolTestCase
{
    public function testSchemaRequiresPathAndContent(): void
    {
        $schema = $this->make(WriteTool::class)->schema('write');

        $this->assertSame('Write a file to the local filesystem. On success, may include a `lint` report for the path when a driver exists.', $schema->definition['description']);
        $this->assertSame(['path', 'content'], $schema->definition['inputSchema']['required']);
        $this->assertSame('Absolute path.', $schema->definition['inputSchema']['properties']['path']['description']);
        $this->assertArrayNotHasKey('default', $schema->definition['inputSchema']['properties']['content']);
    }

    public function testCallWritesContentAndReportsBytes(): void
    {
        $dir = sys_get_temp_dir() . '/switon-tooling-write-' . uniqid('', true);
        mkdir($dir);
        $path = $dir . '/out.txt';
        $content = "hello tooling\n";

        try {
            $result = $this->callTool($this->make(WriteTool::class), 'write', [
                'path' => $path,
                'content' => $content,
            ]);

            $this->assertArrayNotHasKey('path', $result);
            $this->assertSame(strlen($content), $result['bytes']);
            $this->assertSame($content, file_get_contents($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }
}
