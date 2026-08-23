<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\McpClient\Importer\CodexTomlImporter;
use Switon\McpClient\Tests\TestCase;

use function bin2hex;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * Fixtures mirror Codex `~/.codex/config.toml` `[mcp_servers.*]` / `.env` tables (anonymized paths).
 */
#[CoversClass(CodexTomlImporter::class)]
class CodexTomlImporterTest extends TestCase
{
    public function testImportReadsCommandArgsEnvAndSkipsDisabled(): void
    {
        $toml = <<<'TOML'
model = "gpt"

[mcp_servers]

[mcp_servers.computer-use]
command = "./App/SkyComputerUseClient"
args = ["mcp"]
cwd = "."
enabled = false

[mcp_servers.node_repl]
args = ["mcp"]
command = "/Applications/ChatGPT.app/Contents/Resources/cua_node/bin/node_repl"
env_vars = ["BROWSER_USE_ACCESS_EXECUTOR_ID"]
startup_timeout_sec = 120

[mcp_servers.node_repl.env]
CODEX_HOME = "/Users/demo/.codex"
BROWSER_USE_TINYSKY_ENABLED = "1"

[features]
js_repl = true
TOML;

        $file = $this->writeToml($toml);
        try {
            $out = $this->make(CodexTomlImporter::class)->import($file);
            $this->assertArrayNotHasKey('computer-use', $out);
            $this->assertSame([
                'node_repl' => [
                    'command' => '/Applications/ChatGPT.app/Contents/Resources/cua_node/bin/node_repl',
                    'args' => ['mcp'],
                    'env' => [
                        'CODEX_HOME' => '/Users/demo/.codex',
                        'BROWSER_USE_TINYSKY_ENABLED' => '1',
                    ],
                ],
            ], $out);
        } finally {
            @unlink($file);
        }
    }

    public function testImportReturnsEmptyWhenNoMcpTables(): void
    {
        $file = $this->writeToml("model = \"gpt\"\n[features]\njs_repl = true\n");
        try {
            $this->assertSame([], $this->make(CodexTomlImporter::class)->import($file));
        } finally {
            @unlink($file);
        }
    }

    protected function writeToml(string $toml): string
    {
        $file = sys_get_temp_dir() . '/switon-mcp-toml-' . bin2hex(random_bytes(4)) . '.toml';
        file_put_contents($file, $toml);

        return $file;
    }
}
