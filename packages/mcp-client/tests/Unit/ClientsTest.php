<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\MakerInterface;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\Clients;
use Switon\McpClient\Exception;
use Switon\McpClient\Tests\TestCase;

use function is_string;

#[CoversClass(Clients::class)]
class ClientsTest extends TestCase
{
    public function testAddWiresClientAndRemoveDropsIt(): void
    {
        $this->stubBriefClient();
        $clients = $this->make(Clients::class);
        $clients->add('docs', ['url' => 'https://example.com/mcp?x=1']);

        $this->assertSame(['docs'], array_keys($clients->all()));
        $this->assertSame('https://example.com/mcp', $clients->get('docs')->brief());

        $clients->remove('docs');
        $this->assertSame([], $clients->all());
    }

    public function testAddKeepsSameClientWhenEntryUnchanged(): void
    {
        $made = $this->countingBriefClient();
        $clients = $this->make(Clients::class);
        $clients->add('app', ['command' => 'echo']);
        $first = $clients->get('app');
        $clients->add('app', ['command' => 'echo']);

        $this->assertSame($first, $clients->get('app'));
        $this->assertSame(1, $made->n);

        $clients->add('app', ['command' => 'printf']);
        $this->assertNotSame($first, $clients->get('app'));
        $this->assertSame(2, $made->n);
        $this->assertSame('printf', $clients->get('app')->brief());
    }

    public function testGetUnknownRaises(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown MCP server "missing".');
        $this->make(Clients::class)->get('missing');
    }

    protected function stubBriefClient(): void
    {
        $delegate = $this->container->get(MakerInterface::class);
        $this->container->replace(MakerInterface::class, new class ($delegate) implements MakerInterface {
            public function __construct(protected MakerInterface $delegate)
            {
            }

            public function make(string $name, array $parameters = []): mixed
            {
                if ($name === ClientInterface::class) {
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
    }

    /** @return object{n: int} */
    protected function countingBriefClient(): object
    {
        $made = (object) ['n' => 0];
        $delegate = $this->container->get(MakerInterface::class);
        $this->container->replace(MakerInterface::class, new class ($delegate, $made) implements MakerInterface {
            public function __construct(
                protected MakerInterface $delegate,
                protected object $made,
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
}
