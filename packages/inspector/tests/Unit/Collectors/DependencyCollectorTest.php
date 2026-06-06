<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Di\ContainerInterface;
use Switon\Inspector\Collector\DependencyCollector;
use Switon\Inspector\DumperInterface;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Testing\TestCase;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class DependencyCollectorTest extends TestCase
{
    public function testCollectFiltersAndNormalizesDependencyItems(): void
    {
        $collector = new DependencyCollector();

        $psrContainer = $this->createMock(PsrContainerInterface::class);
        $nestedCollector = new DependencyCollectorFakeCollector();
        $controller = new DependencyCollectorFakeController();
        $service = new DependencyCollectorAutowiredService();
        $contextManager = new DependencyCollectorFakeContextManager();
        $factoryService = new stdClass();

        $instances = [
            'container' => $psrContainer,
            'collector' => $nestedCollector,
            'controller' => $controller,
            'service.main' => $service,
            'service.factory' => $factoryService,
            'context.manager' => $contextManager,
        ];
        $definitions = [
            'service.main' => DependencyCollectorAutowiredService::class,
            'service.factory' => 'FactoryClass#build',
            'context.manager' => DependencyCollectorFakeContextManager::class,
        ];

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('getInstances')->willReturn($instances);
        $container->expects($this->once())->method('getDefinitions')->willReturn($definitions);

        $dumper = $this->createMock(DumperInterface::class);
        $dumper->expects($this->exactly(2))
            ->method('dump')
            ->willReturnCallback(static function (object $object): array {
                if ($object instanceof DependencyCollectorAutowiredService) {
                    return [
                        'dsn' => 'mysql:host=127.0.0.1',
                        'ignored' => 'should-be-removed',
                        'context' => ['request' => 'abc'],
                    ];
                }
                if ($object instanceof DependencyCollectorFakeContextManager) {
                    return [
                        'contexts' => ['leak' => true],
                        'name' => 'ctx-manager',
                    ];
                }
                return [];
            });

        $collector = $this->make(DependencyCollector::class, [
            'container' => $container,
            'dumper' => $dumper,
        ]);

        $result = $collector->collect();

        $this->assertArrayNotHasKey('container', $result);
        $this->assertArrayNotHasKey('collector', $result);
        $this->assertArrayNotHasKey('controller', $result);

        $this->assertArrayHasKey('service.main', $result);
        $this->assertSame(DependencyCollectorAutowiredService::class, $result['service.main']['class']);
        $this->assertIsInt($result['service.main']['object_id']);
        $this->assertSame(
            [
                'dsn' => 'mysql:host=127.0.0.1',
                'context' => ['request' => 'abc'],
            ],
            $result['service.main']['properties']
        );

        $this->assertArrayHasKey('service.factory', $result);
        $this->assertSame('FactoryClass#build', $result['service.factory']['properties']);

        $this->assertArrayHasKey('context.manager', $result);
        $this->assertSame(
            ['name' => 'ctx-manager'],
            $result['context.manager']['properties'],
            'ContextManager internal contexts should be removed from inspector output'
        );
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $collector = new DependencyCollector();
        $this->assertSame([], $collector->jsonSerialize());
    }

    public function testBootIsNoOp(): void
    {
        $collector = new DependencyCollector();
        $collector->boot();
        $this->assertTrue(true);
    }
}

class DependencyCollectorAutowiredService
{
    #[Autowired] protected string $dsn = 'default';

    protected string $ignored = 'ignore';
}

#[RequestMapping]
class DependencyCollectorFakeController
{
}

class DependencyCollectorFakeCollector implements CollectorInterface
{
    public function boot(): void
    {
    }

    public function collect(): array
    {
        return [];
    }
}

class DependencyCollectorFakeContextManager implements ContextManagerInterface
{
    #[Autowired] protected array $contexts = [];

    #[Autowired] protected string $name = 'fake';

    public function getContext(ContextAware $object, int $cid = 0): mixed
    {
        return new stdClass();
    }

    public function resetContexts(): void
    {
    }
}
