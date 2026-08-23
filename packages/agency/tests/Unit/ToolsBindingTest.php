<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\ReadTool;
use Switon\Tooling\Tool\WriteTool;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\ToolsTestCase;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

#[CoversClass(Tools::class)]
class ToolsBindingTest extends ToolsTestCase
{
    protected function tool(): ToolsInterface
    {
        return $this->container->get(ToolsInterface::class);
    }

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo'),
                'read' => ReadTool::class,
                'write' => WriteTool::class,
                'bash' => BashTool::class,
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    public function testFileReadMissingFileReturnsToolError(): void
    {
        $result = $this->callStandalone($this->tool(), 'read', ['path' => '/no/such/file.txt']);

        $this->assertInstanceOf(\Switon\Tooling\Exception::class, $result->content);
        $this->assertSame('File not found.', $result->content->getMessage());
    }

    public function testFileWriteAllowsEmptyContent(): void
    {
        $dir = sys_get_temp_dir() . '/switon-agent-binding-' . uniqid('', true);
        mkdir($dir);

        try {
            $file = $dir . '/empty.txt';
            $payload = $this->toolPayload($this->callStandalone($this->tool(), 'write', ['path' => $file, 'content' => '']));

            $this->assertArrayNotHasKey('path', $payload);
            $this->assertSame(0, $payload['bytes']);
            $this->assertSame('', file_get_contents($file));
        } finally {
            if (is_file($dir . '/empty.txt')) {
                unlink($dir . '/empty.txt');
            }
            rmdir($dir);
        }
    }

    public function testMissingRequiredDispatchesToolFailedThenRethrows(): void
    {
        $schema = $this->tool()->get('read');
        $this->assertNotNull($schema);

        $this->expectException(ArgumentBindException::class);
        (new ArgumentBinder())->bind($schema, []);
    }

    public function testWrongTypeDispatchesToolFailedThenRethrows(): void
    {
        $schema = $this->tool()->get('bash');
        $this->assertNotNull($schema);

        $this->expectException(ArgumentBindException::class);
        (new ArgumentBinder())->bind($schema, ['command' => 123]);
    }

    public function testSchemaDefaultAppliedBeforeCall(): void
    {
        $result = $this->callStandalone($this->tool(), 'bash', ['command' => 'echo ok']);

        $payload = $this->toolPayload($result);
        $this->assertSame(0, $payload['exit']);
        $this->assertSame("ok\n", $payload['stdout']);
    }
}
