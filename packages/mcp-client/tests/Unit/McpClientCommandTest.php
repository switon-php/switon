<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\ToolSchema;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\InputInterface;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\ClientsInterface;
use Switon\McpClient\Command\McpClientCommand;
use Switon\McpClient\Importer\McpServersJsonImporter;
use Switon\McpClient\ImportersInterface;
use Switon\McpClient\Tests\TestCase;
use Switon\Tooling\ArgumentBinder;
use Switon\Yaml\YamlReader;
use Switon\Yaml\YamlReaderInterface;
use Switon\Yaml\YamlWriter;
use Switon\Yaml\YamlWriterInterface;

use function array_key_exists;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(McpClientCommand::class)]
class McpClientCommandTest extends TestCase
{
    protected function setUpContainer(): void
    {
        $this->container->set(InputInterface::class, $this->emptyInput());
    }

    public function testListPrintsIdAndBrief(): void
    {
        $app = $this->createStub(ClientInterface::class);
        $app->method('brief')->willReturn('php noop.php');
        $docs = $this->createStub(ClientInterface::class);
        $docs->method('brief')->willReturn('https://example.com/mcp');

        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('all')->willReturn([
            'app' => $app,
            'docs' => $docs,
        ]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('table')->with(
            ['id', 'brief'],
            [
                ['app', 'php noop.php'],
                ['docs', 'https://example.com/mcp'],
            ],
        );
        $console->expects($this->never())->method('writeLn');

        $this->assertSame(0, $this->command($clients, $console)->listAction());
    }

    public function testEmptyListSaysNone(): void
    {
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('all')->willReturn([]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('No MCP servers.');
        $console->expects($this->never())->method('table');

        $this->assertSame(0, $this->command($clients, $console)->listAction());
    }

    public function testToolsPrintsNameAndDescription(): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('listTools')->willReturn([
            'ping' => new ToolSchema(description: 'Ping the server.'),
            'echo' => new ToolSchema(description: 'Echo text.'),
        ]);
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('table')->with(
            ['name', 'description'],
            [
                ['ping', 'Ping the server.'],
                ['echo', 'Echo text.'],
            ],
        );

        $this->assertSame(0, $this->command($clients, $console)->toolsAction('app'));
    }

    public function testSchemaPrintsDescriptionAndInputSchema(): void
    {
        $schema = new ToolSchema(description: 'Get jobs.', properties: [
            'limit' => ['type' => 'integer'],
        ]);
        $client = $this->createStub(ClientInterface::class);
        $client->method('listTools')->willReturn(['getJobs' => $schema]);
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $lines = [];
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->exactly(2))->method('writeLn')->willReturnCallback(
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $this->assertSame(0, $this->command($clients, $console)->schemaAction('ci', 'getJobs'));
        $this->assertSame('Get jobs.', $lines[0]);
        $this->assertStringContainsString('"limit"', $lines[1]);
        $this->assertStringContainsString('"integer"', $lines[1]);
    }

    public function testPingWritesOk(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('ping');
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('ok');

        $this->assertSame(0, $this->command($clients, $console)->pingAction('app'));
    }

    public function testPingFailureReturnsFailure(): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('ping')->willThrowException(new \RuntimeException('down'));
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('down');

        $this->assertSame(ConsoleInterface::FAILURE, $this->command($clients, $console)->pingAction('app'));
    }

    public function testCallPrintsTextBlocksByDefault(): void
    {
        $schema = new ToolSchema(description: 'Get jobs.', properties: [
            'limit' => ['type' => 'integer'],
        ]);
        $client = $this->createMock(ClientInterface::class);
        $client->method('listTools')->willReturn(['getJobs' => $schema]);
        $client->expects($this->once())->method('callTool')->with(
            'getJobs',
            ['limit' => 10],
            null,
            true,
        )->willReturn(['content' => [['type' => 'text', 'text' => '{"ok":true}']]]);

        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('{"ok":true}');

        $this->assertSame(0, $this->command($clients, $console)->callAction('ci', 'getJobs', '{"limit":"10"}'));
    }

    public function testCallRawPrintsWireJson(): void
    {
        $schema = new ToolSchema(description: 'x', properties: []);
        $wire = ['content' => [['type' => 'text', 'text' => 'hi']]];
        $client = $this->createStub(ClientInterface::class);
        $client->method('listTools')->willReturn(['echo' => $schema]);
        $client->method('callTool')->willReturn($wire);
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with(
            $this->callback(static fn (string $line): bool => str_contains($line, '"content"')),
        );

        $this->assertSame(0, $this->command($clients, $console)->callAction('app', 'echo', '{}', true));
    }

    public function testCallIsErrorReturnsFailure(): void
    {
        $schema = new ToolSchema(description: 'x', properties: []);
        $client = $this->createStub(ClientInterface::class);
        $client->method('listTools')->willReturn(['echo' => $schema]);
        $client->method('callTool')->willReturn([
            'content' => [['type' => 'text', 'text' => 'boom']],
            'isError' => true,
        ]);
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('boom');

        $this->assertSame(
            ConsoleInterface::FAILURE,
            $this->command($clients, $console)->callAction('app', 'echo', '{}'),
        );
    }

    public function testCallReadsArgumentsFromFile(): void
    {
        $schema = new ToolSchema(description: 'Get jobs.', properties: [
            'limit' => ['type' => 'integer'],
        ]);
        $client = $this->createMock(ClientInterface::class);
        $client->method('listTools')->willReturn(['getJobs' => $schema]);
        $client->expects($this->once())->method('callTool')->with(
            'getJobs',
            ['limit' => 5],
            null,
            true,
        )->willReturn(['content' => [['type' => 'text', 'text' => 'ok']]]);

        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())->method('read')->with('/tmp/args.json')->willReturn('{"limit":"5"}');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('ok');

        $code = $this->command($clients, $console, $filesystem)->callAction('ci', 'getJobs', '@/tmp/args.json');
        $this->assertSame(0, $code);
    }

    public function testCallRejectsNonObjectArguments(): void
    {
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('Arguments must be a JSON object.');

        $clients = $this->createStub(ClientsInterface::class);
        $this->assertSame(
            ConsoleInterface::INVALID,
            $this->command($clients, $console)->callAction('ci', 'getJobs', '[1]'),
        );
    }

    public function testCallUnknownToolReturnsFailure(): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('listTools')->willReturn([]);
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('get')->willReturn($client);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn');

        $this->assertSame(
            ConsoleInterface::FAILURE,
            $this->command($clients, $console)->callAction('ci', 'missing', '{}'),
        );
    }

    public function testGetPrintsYamlEntry(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-get-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($file, "docs:\n  url: https://example.com/mcp\n  headers:\n    A: '1'\n");

        $lines = [];
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->willReturnCallback(
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->getAction('docs');

            $this->assertSame(0, $code);
            $this->assertNotSame([], $lines);
            $this->assertStringContainsString('docs:', $lines[0]);
            $this->assertStringContainsString('https://example.com/mcp', $lines[0]);
        } finally {
            @unlink($file);
        }
    }

    public function testGetUnknownServerFails(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-get-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($file, "docs:\n  command: echo\n");

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('Unknown MCP server "missing".');

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->getAction('missing');
            $this->assertSame(ConsoleInterface::FAILURE, $code);
        } finally {
            @unlink($file);
        }
    }

    public function testGetFailsWhenMcpYmlMissing(): void
    {
        $file = sys_get_temp_dir() . '/switon-mcp-get-missing-' . bin2hex(random_bytes(4)) . '.yml';
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->with($file)->willReturn(false);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('No ~/.switon/mcp.yml.');

        $code = $this->make(McpClientCommand::class, [
            'clients' => $this->createStub(ClientsInterface::class),
            'console' => $console,
            'argumentBinder' => new ArgumentBinder(),
            'filesystem' => $filesystem,
            'file' => $file,
        ])->getAction('docs');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
    }

    public function testAddUrlWritesEntry(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-add-' . bin2hex(random_bytes(4)) . '.yml';
        @unlink($file);

        $console = $this->createStub(ConsoleInterface::class);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->addAction('docs', false, 'https://example.com/mcp');

            $this->assertSame(0, $code);
            $this->assertFileExists($file);
            $this->assertStringContainsString('https://example.com/mcp', (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testAddCommandWritesStdioEntry(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-add-cmd-' . bin2hex(random_bytes(4)) . '.yml';
        @unlink($file);

        $console = $this->createStub(ConsoleInterface::class);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->addAction('app', false, '', ['npx', '-y', 'tool']);

            $this->assertSame(0, $code);
            $raw = (string) file_get_contents($file);
            $this->assertStringContainsString('command: npx', $raw);
            $this->assertStringContainsString('-y', $raw);
            $this->assertStringContainsString('tool', $raw);
        } finally {
            @unlink($file);
        }
    }

    public function testAddRefusesBothUrlAndCommand(): void
    {
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with(
            'Pass either --url= or -- <command> [args...], not both.',
        );

        $code = $this->make(McpClientCommand::class, [
            'clients' => $this->createStub(ClientsInterface::class),
            'console' => $console,
            'argumentBinder' => new ArgumentBinder(),
        ])->addAction('app', false, 'https://example.com/mcp', ['npx']);

        $this->assertSame(ConsoleInterface::INVALID, $code);
    }

    public function testAddRefusesExistingWithoutForce(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-add-exists-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($file, "docs:\n  command: echo\n");

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with(
            'MCP server "docs" already exists; pass --force to replace.',
        );

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->addAction('docs', false, 'https://example.com/mcp');
            $this->assertSame(ConsoleInterface::INVALID, $code);
        } finally {
            @unlink($file);
        }
    }

    public function testRemoveDeletesEntry(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-remove-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($file, "docs:\n  url: https://example.com/mcp\nkeep:\n  command: echo\n");

        $console = $this->createStub(ConsoleInterface::class);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->removeAction('docs');

            $this->assertSame(0, $code);
            $raw = (string) file_get_contents($file);
            $this->assertStringNotContainsString('docs:', $raw);
            $this->assertStringContainsString('keep:', $raw);
        } finally {
            @unlink($file);
        }
    }

    public function testDisableSetsEnabledFalse(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-disable-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($file, "docs:\n  url: https://example.com/mcp\n");

        $console = $this->createStub(ConsoleInterface::class);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->disableAction('docs');

            $this->assertSame(0, $code);
            $this->assertStringContainsString('enabled: false', (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testEnableClearsEnabledFlag(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);

        $file = sys_get_temp_dir() . '/switon-mcp-enable-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($file, "docs:\n  url: https://example.com/mcp\n  enabled: false\n");

        $console = $this->createStub(ConsoleInterface::class);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->enableAction('docs');

            $this->assertSame(0, $code);
            $this->assertStringNotContainsString('enabled:', (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testAddHttpWithHeadersRoundTripsYaml(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);
        $this->container->set(InputInterface::class, $this->inputWith([
            'header' => ['Authorization=Bearer tok=en', 'X-Trace=1'],
        ]));

        $file = sys_get_temp_dir() . '/switon-mcp-add-headers-' . bin2hex(random_bytes(4)) . '.yml';
        @unlink($file);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $this->createStub(ConsoleInterface::class),
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->addAction('github', false, 'https://example.com/mcp');

            $this->assertSame(0, $code);
            $raw = (string) file_get_contents($file);
            $this->assertStringContainsString('github:', $raw);
            $this->assertStringContainsString('url:', $raw);
            $this->assertStringContainsString('headers:', $raw);
            $this->assertStringContainsString('Authorization:', $raw);
            $this->assertStringContainsString('Bearer tok=en', $raw);
            $this->assertMatchesRegularExpression("/X-Trace['\"]?:/", $raw);

            $loaded = $this->container->get(YamlReaderInterface::class)->load($file);
            $this->assertSame('https://example.com/mcp', $loaded['github']['url'] ?? null);
            $this->assertSame(
                ['Authorization' => 'Bearer tok=en', 'X-Trace' => '1'],
                $loaded['github']['headers'] ?? null,
            );
        } finally {
            @unlink($file);
        }
    }

    public function testAddStdioWithEnvAndArgsRoundTripsYaml(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);
        $this->container->set(YamlWriterInterface::class, ['class' => YamlWriter::class]);
        $this->container->set(InputInterface::class, $this->inputWith([
            'env' => ['API_KEY=secret', 'DEBUG=1'],
        ]));

        $file = sys_get_temp_dir() . '/switon-mcp-add-env-' . bin2hex(random_bytes(4)) . '.yml';
        @unlink($file);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $this->createStub(ConsoleInterface::class),
                'argumentBinder' => new ArgumentBinder(),
                'file' => $file,
            ])->addAction('app', false, '', ['npx', '-y', 'some-mcp']);

            $this->assertSame(0, $code);
            $raw = (string) file_get_contents($file);
            $this->assertStringContainsString('command: npx', $raw);
            $this->assertStringContainsString('args:', $raw);
            $this->assertStringContainsString('-y', $raw);
            $this->assertStringContainsString('some-mcp', $raw);
            $this->assertStringContainsString('env:', $raw);
            $this->assertStringContainsString('API_KEY:', $raw);
            $this->assertStringContainsString('secret', $raw);

            $loaded = $this->container->get(YamlReaderInterface::class)->load($file);
            $this->assertSame('npx', $loaded['app']['command'] ?? null);
            $this->assertSame(['-y', 'some-mcp'], $loaded['app']['args'] ?? null);
            $this->assertSame(['API_KEY' => 'secret', 'DEBUG' => '1'], $loaded['app']['env'] ?? null);
        } finally {
            @unlink($file);
        }
    }

    public function testAddRejectsEnvWithUrl(): void
    {
        $this->container->set(InputInterface::class, $this->inputWith(['env' => 'A=1']));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with(
            '--env applies to stdio servers; use -- <command>, not --url=.',
        );

        $code = $this->make(McpClientCommand::class, [
            'clients' => $this->createStub(ClientsInterface::class),
            'console' => $console,
            'argumentBinder' => new ArgumentBinder(),
        ])->addAction('docs', false, 'https://example.com/mcp');

        $this->assertSame(ConsoleInterface::INVALID, $code);
    }

    public function testImportRefusesExistingWithoutForce(): void
    {
        $target = sys_get_temp_dir() . '/switon-mcp-import-target-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($target, "keep:\n  command: echo\n");

        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->with($target)->willReturn(true);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('~/.switon/mcp.yml already exists; pass --force to overwrite.');

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'filesystem' => $filesystem,
                'file' => $target,
            ])->importAction(false);
            $this->assertSame(ConsoleInterface::INVALID, $code);
        } finally {
            @unlink($target);
        }
    }

    public function testImportMergesRegisteredPathsAndPrintsIdBrief(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);

        $source = sys_get_temp_dir() . '/switon-mcp-import-src-' . bin2hex(random_bytes(4)) . '.json';
        $target = sys_get_temp_dir() . '/switon-mcp-import-out-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($source, json_encode([
            'mcpServers' => [
                'docs' => ['url' => 'https://example.com/mcp?x=1'],
                'app' => ['command' => 'npx', 'args' => ['-y', 'tool']],
            ],
        ], JSON_THROW_ON_ERROR));

        $importers = $this->make(ImportersInterface::class);
        $importers->set($source, McpServersJsonImporter::class);

        $written = null;
        $yamlWriter = $this->createMock(YamlWriterInterface::class);
        $yamlWriter->expects($this->once())->method('write')->willReturnCallback(
            function (string $file, array $entries) use ($target, &$written): void {
                $this->assertSame($target, $file);
                $written = $entries;
            },
        );

        $lines = [];
        $console = $this->createMock(ConsoleInterface::class);
        $console->method('writeLn')->willReturnCallback(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $console->expects($this->once())->method('table')->with(
            ['id', 'brief'],
            [
                ['docs', 'https://example.com/mcp'],
                ['app', 'npx -y tool'],
            ],
        );

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'importers' => $importers,
                'yamlWriter' => $yamlWriter,
                'file' => $target,
            ])->importAction(false);

            $this->assertSame(0, $code);
            $this->assertSame([
                'docs' => ['url' => 'https://example.com/mcp?x=1'],
                'app' => ['command' => 'npx', 'args' => ['-y', 'tool']],
            ], $written);
            $this->assertContains('From:', $lines);
            $this->assertTrue((bool) array_filter($lines, static fn (string $l): bool => str_contains($l, $source)));
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testImportDrySkipsWrite(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);

        $source = sys_get_temp_dir() . '/switon-mcp-import-src-' . bin2hex(random_bytes(4)) . '.json';
        $target = sys_get_temp_dir() . '/switon-mcp-import-out-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($source, json_encode([
            'mcpServers' => [
                'docs' => ['url' => 'https://example.com/mcp'],
            ],
        ], JSON_THROW_ON_ERROR));

        $importers = $this->make(ImportersInterface::class);
        $importers->set($source, McpServersJsonImporter::class);

        $yamlWriter = $this->createMock(YamlWriterInterface::class);
        $yamlWriter->expects($this->never())->method('write');

        $console = $this->createMock(ConsoleInterface::class);
        $console->method('writeLn');
        $console->expects($this->once())->method('table')->with(
            ['id', 'brief'],
            [['docs', 'https://example.com/mcp']],
        );

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'importers' => $importers,
                'yamlWriter' => $yamlWriter,
                'file' => $target,
            ])->importAction(false, true);

            $this->assertSame(0, $code);
            $this->assertFileDoesNotExist($target);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testImportDryDoesNotRefuseExistingFile(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);

        $source = sys_get_temp_dir() . '/switon-mcp-import-src-' . bin2hex(random_bytes(4)) . '.json';
        $target = sys_get_temp_dir() . '/switon-mcp-import-out-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($source, json_encode([
            'mcpServers' => ['docs' => ['command' => 'echo']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($target, "keep:\n  command: keep\n");

        $importers = $this->make(ImportersInterface::class);
        $importers->set($source, McpServersJsonImporter::class);

        $yamlWriter = $this->createMock(YamlWriterInterface::class);
        $yamlWriter->expects($this->never())->method('write');

        $console = $this->createMock(ConsoleInterface::class);
        $console->method('writeLn');
        $console->expects($this->once())->method('table');

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'importers' => $importers,
                'yamlWriter' => $yamlWriter,
                'file' => $target,
            ])->importAction(false, true);

            $this->assertSame(0, $code);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testImportSaysNoneWhenNoRegisteredFilesExist(): void
    {
        $importers = $this->make(ImportersInterface::class);
        $importers->set('/tmp/switon-mcp-missing-' . bin2hex(random_bytes(4)) . '.json', McpServersJsonImporter::class);

        $yamlWriter = $this->createMock(YamlWriterInterface::class);
        $yamlWriter->expects($this->never())->method('write');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('No MCP configs found.');

        $code = $this->make(McpClientCommand::class, [
            'clients' => $this->createStub(ClientsInterface::class),
            'console' => $console,
            'argumentBinder' => new ArgumentBinder(),
            'importers' => $importers,
            'yamlWriter' => $yamlWriter,
            'file' => sys_get_temp_dir() . '/switon-mcp-import-out-' . bin2hex(random_bytes(4)) . '.yml',
        ])->importAction(false);

        $this->assertSame(0, $code);
    }

    public function testImportReportsEmptyWhenSourcesYieldNoServers(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);

        $source = sys_get_temp_dir() . '/switon-mcp-import-src-' . bin2hex(random_bytes(4)) . '.json';
        $target = sys_get_temp_dir() . '/switon-mcp-import-out-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($source, json_encode(['mcpServers' => []], JSON_THROW_ON_ERROR));

        $importers = $this->make(ImportersInterface::class);
        $importers->set($source, McpServersJsonImporter::class);

        $yamlWriter = $this->createMock(YamlWriterInterface::class);
        $yamlWriter->expects($this->once())->method('write')->with($target, []);

        $lines = [];
        $console = $this->createMock(ConsoleInterface::class);
        $console->method('writeLn')->willReturnCallback(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $console->expects($this->never())->method('table');

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'importers' => $importers,
                'yamlWriter' => $yamlWriter,
                'file' => $target,
            ])->importAction(false);

            $this->assertSame(0, $code);
            $this->assertTrue((bool) array_filter(
                $lines,
                static fn (string $l): bool => str_contains($l, 'No servers imported into'),
            ));
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testImportForceAppliesOwnYamlLast(): void
    {
        $this->container->set(YamlReaderInterface::class, ['class' => YamlReader::class]);

        $source = sys_get_temp_dir() . '/switon-mcp-import-src-' . bin2hex(random_bytes(4)) . '.json';
        $target = sys_get_temp_dir() . '/switon-mcp-import-out-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($source, json_encode([
            'mcpServers' => [
                'docs' => ['url' => 'https://foreign.example/mcp'],
                'only-foreign' => ['command' => 'foreign'],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($target, "docs:\n  url: https://own.example/mcp\nkeep:\n  command: keep\n");

        $importers = $this->make(ImportersInterface::class);
        $importers->set($source, McpServersJsonImporter::class);

        $written = null;
        $yamlWriter = $this->createMock(YamlWriterInterface::class);
        $yamlWriter->expects($this->once())->method('write')->willReturnCallback(
            function (string $file, array $entries) use (&$written): void {
                $written = $entries;
            },
        );

        $console = $this->createStub(ConsoleInterface::class);

        try {
            $code = $this->make(McpClientCommand::class, [
                'clients' => $this->createStub(ClientsInterface::class),
                'console' => $console,
                'argumentBinder' => new ArgumentBinder(),
                'importers' => $importers,
                'yamlWriter' => $yamlWriter,
                'file' => $target,
            ])->importAction(true);

            $this->assertSame(0, $code);
            $this->assertSame('https://own.example/mcp', $written['docs']['url'] ?? null);
            $this->assertSame(['command' => 'foreign'], $written['only-foreign'] ?? null);
            $this->assertSame(['command' => 'keep'], $written['keep'] ?? null);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    protected function command(
        ClientsInterface $clients,
        ConsoleInterface $console,
        ?FilesystemInterface $filesystem = null,
    ): McpClientCommand {
        return $this->make(McpClientCommand::class, [
            'clients' => $clients,
            'console' => $console,
            'argumentBinder' => new ArgumentBinder(),
            'filesystem' => $filesystem ?? $this->createStub(FilesystemInterface::class),
        ]);
    }

    protected function emptyInput(): InputInterface
    {
        return $this->inputWith([]);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function inputWith(array $options): InputInterface
    {
        return new class ($options) implements InputInterface {
            /** @param array<string, mixed> $options */
            public function __construct(protected array $options)
            {
            }

            public function has(string|int $name): bool
            {
                return array_key_exists((string) $name, $this->options);
            }

            public function get(string|int $name, mixed $default = null): mixed
            {
                $key = (string) $name;

                return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
            }

            public function all(): array
            {
                return $this->options;
            }
        };
    }
}
