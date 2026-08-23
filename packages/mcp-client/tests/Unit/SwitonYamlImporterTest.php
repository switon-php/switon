<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\McpClient\Importer\SwitonYamlImporter;
use Switon\McpClient\Tests\TestCase;
use Switon\Yaml\YamlReader;
use Switon\Yaml\YamlReaderInterface;

use function bin2hex;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(SwitonYamlImporter::class)]
class SwitonYamlImporterTest extends TestCase
{
    public function testImportKeepsEnabledFalseAndSkipsShapeless(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $file = sys_get_temp_dir() . '/switon-mcp-yml-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($file, <<<'YAML'
docs:
  url: https://example.com/mcp?token=secret
off:
  command: echo
  enabled: false
bad:
  type: http
YAML);

        try {
            $out = $this->make(SwitonYamlImporter::class)->import($file);
            $this->assertSame([
                'docs' => ['url' => 'https://example.com/mcp?token=secret'],
                'off' => ['command' => 'echo', 'enabled' => false],
            ], $out);
        } finally {
            @unlink($file);
        }
    }

    public function testImportReturnsEmptyWhenMissing(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->assertSame(
            [],
            $this->make(SwitonYamlImporter::class)->import('/tmp/switon-mcp-missing-' . bin2hex(random_bytes(4)) . '.yml'),
        );
    }
}
