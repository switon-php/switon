<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Ai\AgentInterface;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Core\Exception\CreateDirectoryFailedException;
use Switon\Core\Exception\RuntimeException as CoreRuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\ClientsInterface;
use Switon\McpClient\Tests\Fixtures\StubToolAgent;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Tool\McpCallTool;

use function base64_encode;
use function bin2hex;
use function file_get_contents;
use function mkdir;
use function random_bytes;
use function str_repeat;
use function sys_get_temp_dir;

#[CoversClass(McpCallTool::class)]
class McpCallToolTest extends TestCase
{
    public string $requestedServer = '';

    public function testCallSpillsTextResult(): void
    {
        $body = str_repeat('x', 4000);
        $dir = sys_get_temp_dir() . '/switon-mcp-spill-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tool-result', 0777, true);

        $result = $this->callOn($this->clientReturning($body), [], StubToolAgent::in($dir), budget: 1, callId: 'mcp');

        $this->assertIsString($result);
        $this->assertStringContainsString('recall: @thread/tool-result/mcp.txt', $result);
        $this->assertSame($body, file_get_contents($dir . '/tool-result/mcp.txt'));
    }

    public function testCallLeavesStructuredResultInline(): void
    {
        $payload = ['error' => str_repeat('e', 4000)];

        $result = $this->callOn($this->clientReturning($payload), [], StubToolAgent::in());

        $this->assertSame($payload, $result);
    }

    public function testCallStoresImageBlocksAsContentParts(): void
    {
        $png = 'PNG';
        $wire = [
            'content' => [
                ['type' => 'text', 'text' => '### Result\n- [Screenshot](./example.com.png)'],
                ['type' => 'image', 'data' => base64_encode($png), 'mimeType' => 'image/png'],
            ],
        ];
        $dir = sys_get_temp_dir() . '/switon-mcp-image-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        $result = $this->callOn($this->clientReturning($wire), [], StubToolAgent::in($dir), callId: 'call_1');

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertStringContainsString('Screenshot', Json::text($result->content));
        $ref = 'tool-asset/call_1-1.png';
        $this->assertCount(1, $result->parts);
        $this->assertInstanceOf(MediaPart::class, $result->parts[0]);
        $this->assertSame($ref, $result->parts[0]->ref);
        $this->assertSame('image/png', $result->parts[0]->mime);
        $this->assertSame($png, file_get_contents($dir . '/' . $ref));
    }

    public function testCallDropsAnImageWhoseSubtypeLeavesTheDataDir(): void
    {
        $wire = [
            'content' => [
                ['type' => 'image', 'data' => base64_encode('PNG'), 'mimeType' => 'image/../../../../tmp/escape'],
            ],
        ];
        $dir = sys_get_temp_dir() . '/switon-mcp-image-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        $result = $this->callOn($this->clientReturning($wire), [], StubToolAgent::in($dir), callId: 'call_1');

        $this->assertIsArray($result);
        $this->assertSame([], glob($dir . '/tool-asset/*') ?: []);
    }

    public function testCallDropsAnImagePastTheByteCap(): void
    {
        $wire = [
            'content' => [
                ['type' => 'image', 'data' => base64_encode('01234567'), 'mimeType' => 'image/png'],
            ],
        ];
        $dir = sys_get_temp_dir() . '/switon-mcp-image-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        $result = $this->callOn(
            $this->clientReturning($wire),
            [],
            StubToolAgent::in($dir),
            maxImageBytes: 4,
        );

        $this->assertIsArray($result);
        $this->assertSame([], glob($dir . '/tool-asset/*') ?: []);
    }

    public function testCallReturnsTextBlock(): void
    {
        $result = $this->callOn($this->clientReturning([
            'content' => [['type' => 'text', 'text' => 'mcp:ping']],
        ]), [], StubToolAgent::in());

        $this->assertSame('mcp:ping', $result);
        $this->assertSame('app', $this->requestedServer);
        $this->assertFalse($this->make(McpCallTool::class, ['clients' => $this->clients($this->clientReturning([]))])->isReadonly('mcp_call', []));
        $this->assertSame(['server', 'tool'], $this->make(McpCallTool::class, [
            'clients' => $this->clients($this->clientReturning([])),
        ])->schema('mcp_call')->definition['inputSchema']['required']);
    }

    public function testCallReturnsANonArrayResult(): void
    {
        $this->assertSame(7, $this->callOn($this->clientReturning(7), [], StubToolAgent::in()));
    }

    public function testCallJoinsTextAndSkipsANonArrayBlock(): void
    {
        $result = $this->callOn($this->clientReturning([
            'content' => ['skip', ['type' => 'text', 'text' => 'kept']],
        ]), [], StubToolAgent::in());

        $this->assertSame('kept', $result);
    }

    public function testCallDropsAnImageWhenTheThreadHasNoDataDir(): void
    {
        $wire = [
            'content' => [
                ['type' => 'image', 'data' => base64_encode('PNG'), 'mimeType' => 'image/png'],
            ],
        ];

        $result = $this->callOn($this->clientReturning($wire), [], new StubToolAgent(Thread::of('memory', null)));

        $this->assertSame($wire, $result);
    }

    public function testCallStoresAJpegWithAJpgExtension(): void
    {
        $dir = sys_get_temp_dir() . '/switon-mcp-image-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $wire = [
            'content' => [
                ['type' => 'image', 'data' => base64_encode('JPG'), 'mimeType' => 'image/jpeg'],
            ],
        ];

        $result = $this->callOn($this->clientReturning($wire), [], StubToolAgent::in($dir), callId: 'shot');

        $this->assertSame('tool-asset/shot-1.jpg', $result->parts[0]->ref);
    }

    public function testCallDropsAnImageThatIsNotBase64(): void
    {
        $dir = sys_get_temp_dir() . '/switon-mcp-image-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $wire = [
            'content' => [
                ['type' => 'image', 'data' => '!!!', 'mimeType' => 'image/png'],
            ],
        ];

        $result = $this->callOn($this->clientReturning($wire), [], StubToolAgent::in($dir));

        $this->assertSame($wire, $result);
    }

    public function testCallDropsAnImageWhenTheDirectoryCannotBeCreated(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('mkdir')->willThrowException(new class ('no dir') extends CreateDirectoryFailedException {
            public function __construct(string $message)
            {
                parent::__construct($message);
            }
        });
        $dir = sys_get_temp_dir() . '/switon-mcp-image-' . bin2hex(random_bytes(4));
        $wire = [
            'content' => [
                ['type' => 'image', 'data' => base64_encode('PNG'), 'mimeType' => 'image/png'],
            ],
        ];

        $result = $this->callOn($this->clientReturning($wire), [], StubToolAgent::in($dir), filesystem: $filesystem);

        $this->assertSame($wire, $result);
    }

    public function testCallDropsAnImageWhenTheWriteFails(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('write')->willThrowException(new class ('no write') extends CoreRuntimeException {
            public function __construct(string $message)
            {
                parent::__construct($message);
            }
        });
        $dir = sys_get_temp_dir() . '/switon-mcp-image-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $wire = [
            'content' => [
                ['type' => 'image', 'data' => base64_encode('PNG'), 'mimeType' => 'image/png'],
            ],
        ];

        $result = $this->callOn($this->clientReturning($wire), [], StubToolAgent::in($dir), filesystem: $filesystem);

        $this->assertSame($wire, $result);
    }

    public function testCallIsErrorReturnsErrorPayloadWithContentText(): void
    {
        $result = $this->callOn($this->clientReturning([
            'isError' => true,
            'content' => [['type' => 'text', 'text' => 'boom: invalid args']],
        ]), [], StubToolAgent::in());

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertSame('boom: invalid args', $result->content);
    }

    public function testCallWireExceptionPropagates(): void
    {
        $client = new class () implements ClientInterface {
            public function brief(): string
            {
                return '';
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
                throw new RuntimeException('wire down');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wire down');
        $this->callOn($client, [], StubToolAgent::in());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callOn(
        ClientInterface $client,
        array $arguments,
        AgentInterface $agent,
        int $budget = 32,
        string $callId = 'test',
        int $maxImageBytes = 8388608,
        ?FilesystemInterface $filesystem = null,
    ): mixed {
        $parameters = [
            'clients' => $this->clients($client),
            'budget' => $budget,
            'maxImageBytes' => $maxImageBytes,
        ];
        if ($filesystem !== null) {
            $parameters['filesystem'] = $filesystem;
        }
        $tool = $this->make(McpCallTool::class, $parameters);

        return $tool->call(
            $agent,
            new ToolCall($callId, 'mcp_call'),
            ['server' => 'app', 'tool' => 'echo', ...$arguments],
        );
    }

    protected function clientReturning(mixed $payload): ClientInterface
    {
        return new class ($payload) implements ClientInterface {
            public function __construct(protected mixed $payload)
            {
            }

            public function brief(): string
            {
                return '';
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
                return $this->payload;
            }
        };
    }

    protected function clients(ClientInterface $client): ClientsInterface
    {
        $test = $this;

        return new class ($client, $test) implements ClientsInterface {
            public function __construct(
                protected ClientInterface $client,
                protected McpCallToolTest $test,
            ) {
            }

            public function add(string $id, ClientInterface $client): void
            {
            }

            public function get(string $id): ClientInterface
            {
                $this->test->requestedServer = $id;

                return $this->client;
            }

            public function all(): array
            {
                return ['app' => $this->client];
            }

            public function remove(string $id): void
            {
            }
        };
    }
}
