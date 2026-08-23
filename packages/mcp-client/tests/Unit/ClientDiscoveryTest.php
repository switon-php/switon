<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\MakerInterface;
use Switon\McpClient\ClientDiscovery;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\Clients;
use Switon\McpClient\ClientsInterface;
use Switon\McpClient\Exception;
use Switon\McpClient\Tests\Fixtures\StubToolAgent;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Tool\McpCallTool;
use Switon\McpClient\Tool\McpSchemaTool;
use Switon\McpClient\Tool\McpServersTool;
use Switon\McpClient\Tool\McpToolsTool;
use Switon\Yaml\YamlReader;
use Switon\Yaml\YamlReaderInterface;

use function array_keys;
use function file_put_contents;
use function is_string;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(ClientDiscovery::class)]
#[CoversClass(Clients::class)]
#[CoversClass(McpServersTool::class)]
#[CoversClass(McpToolsTool::class)]
#[CoversClass(McpSchemaTool::class)]
class ClientDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container->set(YamlReaderInterface::class, [
            'class' => YamlReader::class,
        ]);
    }

    public function testLoadFromUrlConfig(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig("app:\n  url: https://example.com/mcp\n");

        try {
            [, $clients] = $this->load($configFile);
            $listed = $this->make(McpServersTool::class, ['clients' => $clients])->call(
                StubToolAgent::in(),
                new ToolCall(name: 'mcp_servers'),
                [],
            );

            $this->assertSame(['app'], array_keys($clients->all()));
            $this->assertSame('https://example.com/mcp', $clients->get('app')->brief());
            $this->assertSame([['id' => 'app', 'brief' => 'https://example.com/mcp']], $listed['servers']);
        } finally {
            @unlink($configFile);
        }
    }

    public function testHttpBriefStripsQueryString(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig(
            "app:\n  url: https://example.com/mcp?access_key=secret&smart=0\n",
        );

        try {
            [, $clients] = $this->load($configFile);
            $this->assertSame('https://example.com/mcp', $clients->get('app')->brief());
        } finally {
            @unlink($configFile);
        }
    }

    public function testLoadCommandBriefAndCall(): void
    {
        $this->stubClient(new class () implements ClientInterface {
            public string $brief = '';

            public function brief(): string
            {
                return $this->brief;
            }

            public function onNotification(callable $handler): void
            {
            }

            public function ping(): void
            {
            }

            public function listTools(bool $refresh = false): array
            {
                return [
                    'time_now' => new ToolSchema(description: 'current time', properties: []),
                ];
            }

            public function callTool(string $name, array $arguments = [], ?callable $shouldAbort = null, bool $raw = true): mixed
            {
                return ['content' => [['type' => 'text', 'text' => '12:00']]];
            }
        });
        $configFile = $this->writeConfig("app:\n  command: php\n  args: [noop.php]\n");

        try {
            [, $clients] = $this->load($configFile);
            $this->assertSame(['app'], array_keys($clients->all()));
            $this->assertSame('php noop.php', $clients->get('app')->brief());

            $mcp = $this->make(McpCallTool::class, ['clients' => $clients]);
            $dir = sys_get_temp_dir() . '/switon-mcp-call-' . uniqid('', true);
            mkdir($dir . '/tool-result', 0777, true);
            $this->assertSame('12:00', $mcp->call(StubToolAgent::in($dir), new ToolCall(name: 'mcp_call'), [
                'server' => 'app',
                'tool' => 'time_now',
            ]));
        } finally {
            @unlink($configFile);
        }
    }

    public function testLoadSkipsWhenConfigFileMissing(): void
    {
        [, $clients] = $this->load(sys_get_temp_dir() . '/missing-mcp-' . uniqid('', true) . '.yml');
        $this->assertSame([], $clients->all());
    }

    public function testLoadReplacesPreviousServers(): void
    {
        $this->stubClient($this->emptyClient());
        $first = $this->writeConfig("old:\n  command: php\n  args: [noop.php]\n");
        $second = $this->writeConfig("new:\n  command: php\n  args: [noop.php]\n");

        try {
            [, $clients] = $this->load($first);
            $this->assertSame(['old'], array_keys($clients->all()));

            $this->load($second, $clients);

            $this->assertSame(['new'], array_keys($clients->all()));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function testLoadClearsWhenConfigFileMissing(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig("app:\n  command: php\n  args: [noop.php]\n");

        try {
            [, $clients] = $this->load($configFile);
            $this->assertCount(1, $clients->all());

            $this->load(sys_get_temp_dir() . '/missing-mcp-' . uniqid('', true) . '.yml', $clients);

            $this->assertSame([], $clients->all());
        } finally {
            @unlink($configFile);
        }
    }

    public function testLoadSkipsDisabledServers(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig(<<<'YAML'
off:
  enabled: false
  command: php
  args: [noop.php]
on:
  command: php
  args: [noop.php]
YAML);

        try {
            [, $clients] = $this->load($configFile);
            $this->assertSame(['on'], array_keys($clients->all()));
        } finally {
            @unlink($configFile);
        }
    }

    public function testDiscoverTreatsAnEmptyDocumentAsNoServers(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig("# none\n");

        try {
            [, $clients] = $this->load($configFile);
            $this->assertSame([], $clients->all());
        } finally {
            @unlink($configFile);
        }
    }

    public function testDiscoverSkipsANonObjectEntry(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig("- command: php\n");

        try {
            [, $clients] = $this->load($configFile);
            $this->assertSame([], $clients->all());
        } finally {
            @unlink($configFile);
        }
    }

    public function testDiscoverSkipsWhenCommandIsNotText(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig("app:\n  command: 123\n");

        try {
            [, $clients] = $this->load($configFile);
            $this->assertSame([], $clients->all());
        } finally {
            @unlink($configFile);
        }
    }

    public function testGetThrowsWhenTheServerIsUnknown(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown MCP server "missing".');
        (new Clients())->get('missing');
    }

    public function testDiscoverAllowsAServerIdThatContainsAnUnderscore(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig("bad_id:\n  command: php\n");

        try {
            [, $clients] = $this->load($configFile);
            $this->assertArrayHasKey('bad_id', $clients->all());
        } finally {
            @unlink($configFile);
        }
    }

    public function testDiscoverSkipsAnEntryWithoutCommandOrUrl(): void
    {
        $this->stubClient($this->emptyClient());
        $configFile = $this->writeConfig("only:\n  headers:\n    Authorization: token\n");

        try {
            [, $clients] = $this->load($configFile);
            $this->assertSame([], $clients->all());
        } finally {
            @unlink($configFile);
        }
    }

    public function testDiscoverKeepsTheSameClientUntilTheEntryChanges(): void
    {
        $made = $this->countingClients();
        $configFile = $this->writeConfig("app:\n  command: php\n  args: [noop.php]\n");

        try {
            [$discovery, $clients] = $this->load($configFile);
            $first = $clients->get('app');
            $discovery->discover();

            $this->assertSame($first, $clients->get('app'));
            $this->assertSame(1, $made->n);

            file_put_contents($configFile, "app:\n  command: php\n  args: [other.php]\n");
            $this->assertSame('php noop.php', $clients->get('app')->brief());

            $discovery->discover();
            $this->assertSame('php other.php', $clients->get('app')->brief());
            $this->assertNotSame($first, $clients->get('app'));
            $this->assertSame(2, $made->n);
        } finally {
            @unlink($configFile);
        }
    }

    public function testToolsReloadRefetchesAndSchemaReadsArguments(): void
    {
        $wire = new class () implements ClientInterface {
            public string $brief = '';
            /** @var list<bool> */
            public array $refreshes = [];

            public function brief(): string
            {
                return $this->brief;
            }

            public function onNotification(callable $handler): void
            {
            }

            public function ping(): void
            {
            }

            public function listTools(bool $refresh = false): array
            {
                $this->refreshes[] = $refresh;

                return [
                    'ping' => ToolSchema::of([
                        'name' => 'ping',
                        'description' => 'Ping',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'keywords' => ['type' => 'string'],
                                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            ],
                            'required' => ['keywords'],
                        ],
                    ]),
                    'noop' => ToolSchema::of([
                        'name' => 'noop',
                        'description' => 'No arguments',
                    ]),
                    'save' => ToolSchema::of([
                        'name' => 'save',
                        'description' => 'Nested',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'page' => [
                                    'type' => 'object',
                                    'properties' => ['title' => ['type' => 'string']],
                                ],
                            ],
                        ],
                    ]),
                ];
            }

            public function callTool(string $name, array $arguments = [], ?callable $shouldAbort = null, bool $raw = true): mixed
            {
                return ['content' => []];
            }
        };
        $this->stubClient($wire);
        $configFile = $this->writeConfig("docs:\n  url: https://example.com/mcp\n");

        try {
            [, $clients] = $this->load($configFile);
            $tools = $this->make(McpToolsTool::class, ['clients' => $clients]);
            $host = new ToolCall(name: 'mcp_tools');
            $listed = $tools->call(StubToolAgent::in(), $host, ['server' => 'docs']);
            $again = $tools->call(StubToolAgent::in(), $host, ['server' => 'docs']);
            $reloaded = $tools->call(StubToolAgent::in(), $host, ['server' => 'docs', 'reload' => true]);

            $this->assertSame(['keywords', 'ids'], $listed['tools'][0]['params']);
            $this->assertSame([], $listed['tools'][1]['params']);
            $this->assertSame('Params structure is complex. Read with mcp_schema.', $listed['tools'][2]['params']);
            $this->assertSame('ping', $listed['tools'][0]['name']);
            $this->assertSame($listed, $again);
            $this->assertSame([false, false, true], $wire->refreshes);
            $this->assertSame('ping', $reloaded['tools'][0]['name']);

            $schema = $this->make(McpSchemaTool::class, ['clients' => $clients]);
            $this->assertSame(['keywords'], $schema->call(StubToolAgent::in(), new ToolCall(name: 'mcp_schema'), [
                'server' => 'docs',
                'tool' => 'ping',
            ])['arguments']['required']);
        } finally {
            @unlink($configFile);
        }
    }

    protected function writeConfig(string $yaml): string
    {
        $configFile = sys_get_temp_dir() . '/mcp-' . uniqid('', true) . '.yml';
        file_put_contents($configFile, $yaml);

        return $configFile;
    }

    /** @return array{ClientDiscovery, ClientsInterface} */
    protected function load(string $file, ?ClientsInterface $clients = null): array
    {
        $clients ??= $this->make(Clients::class);
        $discovery = $this->make(ClientDiscovery::class, [
            'file' => $file,
            'clients' => $clients,
        ]);
        $discovery->discover();

        return [$discovery, $clients];
    }

    protected function stubClient(ClientInterface $wire): void
    {
        $delegate = $this->container->get(MakerInterface::class);
        $this->container->replace(MakerInterface::class, new class ($wire, $delegate) implements MakerInterface {
            public function __construct(
                protected ClientInterface $wire,
                protected MakerInterface $delegate,
            ) {
            }

            public function make(string $name, array $parameters = []): mixed
            {
                if ($name === ClientInterface::class) {
                    if (property_exists($this->wire, 'brief') && is_string($parameters['brief'] ?? null)) {
                        $this->wire->brief = $parameters['brief'];
                    }

                    return $this->wire;
                }

                return $this->delegate->make($name, $parameters);
            }
        });
    }

    protected function countingClients(): stdClass
    {
        $made = new stdClass();
        $made->n = 0;
        $delegate = $this->container->get(MakerInterface::class);
        $this->container->replace(MakerInterface::class, new class ($delegate, $made) implements MakerInterface {
            public function __construct(
                protected MakerInterface $delegate,
                protected stdClass $made,
            ) {
            }

            public function make(string $name, array $parameters = []): mixed
            {
                if ($name === ClientInterface::class) {
                    $this->made->n++;
                    $brief = is_string($parameters['brief'] ?? null) ? $parameters['brief'] : '';

                    return new class ($brief) implements ClientInterface {
                        public function __construct(protected string $brief)
                        {
                        }

                        public function brief(): string
                        {
                            return $this->brief;
                        }

                        public function onNotification(callable $handler): void
                        {
                        }

                        public function ping(): void
                        {
                        }

                        public function listTools(bool $refresh = false): array
                        {
                            return [];
                        }

                        public function callTool(string $name, array $arguments = [], ?callable $shouldAbort = null, bool $raw = true): mixed
                        {
                            return ['content' => []];
                        }
                    };
                }

                return $this->delegate->make($name, $parameters);
            }
        });

        return $made;
    }

    protected function emptyClient(): ClientInterface
    {
        return new class () implements ClientInterface {
            public string $brief = '';

            public function brief(): string
            {
                return $this->brief;
            }

            public function onNotification(callable $handler): void
            {
            }

            public function ping(): void
            {
            }

            public function listTools(bool $refresh = false): array
            {
                return [];
            }

            public function callTool(string $name, array $arguments = [], ?callable $shouldAbort = null, bool $raw = true): mixed
            {
                return ['content' => []];
            }
        };
    }
}
