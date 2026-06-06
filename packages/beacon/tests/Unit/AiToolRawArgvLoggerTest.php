<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\Listener\AiToolRawArgvLogger;
use Switon\Cli\Event\ToolInvoking;
use Switon\Cli\RouterInterface;
use Switon\Core\FilesystemInterface;
use Switon\Testing\Container;
use stdClass;

use function is_string;
use function json_decode;
use function trim;

#[AllowMockObjectsWithoutExpectations]
class AiToolRawArgvLoggerTest extends TestCase
{
    public function testOnToolInvokingAppendsJsonLineWithRedactedSecrets(): void
    {
        $capturedLine = null;
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())
            ->method('append')
            ->with(
                '@runtime/beacon/tool.log',
                $this->callback(static function (mixed $line) use (&$capturedLine): bool {
                    $capturedLine = $line;
                    return is_string($line);
                })
            );

        $router = $this->createMock(RouterInterface::class);
        $router->method('getCommand')->willReturn('tool');
        $router->method('getAction')->willReturn('list');

        $event = new ToolInvoking(
            argv: [
                'switon',
                'tool:list',
                '--token',
                'secret-token',
                '--api-key=secret-api',
                '--name',
                'alice',
                '--password',
                'pass123',
                '--mode=test',
            ],
            router: $router,
            command: new stdClass(),
            method: 'listAction',
            action: 'list'
        );

        $container = new Container([
            FilesystemInterface::class => $filesystem,
        ]);
        $logger = $container->make(AiToolRawArgvLogger::class);

        $logger->onToolInvoking($event);

        $this->assertIsString($capturedLine);
        $json = json_decode(trim((string)$capturedLine), true);
        $this->assertIsArray($json);
        $this->assertSame('tool:list', $json['invocation']);
        $this->assertSame('***', $json['argv'][3]);
        $this->assertSame('--api-key=***', $json['argv'][4]);
        $this->assertSame('alice', $json['argv'][6]);
        $this->assertSame('***', $json['argv'][8]);
        $this->assertSame('--mode=test', $json['argv'][9]);
        $this->assertArrayHasKey('ts', $json);
        $this->assertArrayHasKey('pid', $json);
    }
}
