<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Switon\Beacon\Command\DiCommand;
use Switon\Beacon\Tests\Fixtures\DiCommand\DummyDiService;
use Switon\Beacon\Tests\Fixtures\DiCommand\DummyDiServiceInterface;
use Switon\Core\ConsoleInterface;
use Switon\Testing\Container;

use function json_decode;

#[AllowMockObjectsWithoutExpectations]
class DiCommandTest extends TestCase
{
    protected DiCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->command = $this->makeCommand();
    }

    public function testBindingActionPrefersSingleBoundInterfaceAndReturnsAutowiredScalars(): void
    {
        $id = DummyDiServiceInterface::class;
        $service = new DummyDiService();

        $this->container->expects($this->exactly(2))
            ->method('has')
            ->with($id)
            ->willReturn(true);
        $this->container->expects($this->once())
            ->method('get')
            ->with($id)
            ->willReturn($service);

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->bindingAction(DummyDiService::class);

        $this->assertSame(0, $code);
        $this->assertSame($id, $captured['id']);
        $this->assertSame(DummyDiService::class, $captured['class']);
        $this->assertSame(
            [
                ['name' => 'retries', 'type' => 'int', 'value' => 3],
                ['name' => 'servers', 'type' => 'array', 'value' => ['a', 'b']],
                ['name' => 'base', 'type' => 'string', 'value' => 'base-value'],
            ],
            $captured['autowired']
        );
    }

    public function testBindingActionReturnsNotBoundErrorWhenServiceIsMissing(): void
    {
        $id = DummyDiServiceInterface::class;
        $this->container->expects($this->exactly(2))
            ->method('has')
            ->with($id)
            ->willReturnOnConsecutiveCalls(true, false);
        $this->container->expects($this->never())->method('get');

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->bindingAction(DummyDiService::class);

        $this->assertSame(1, $code);
        $this->assertSame('not bound', $captured['error']);
        $this->assertSame($id, $captured['id']);
    }

    public function testBindingActionReturnsJsonErrorWhenContainerGetThrows(): void
    {
        $id = DummyDiServiceInterface::class;
        $this->container->expects($this->exactly(2))
            ->method('has')
            ->with($id)
            ->willReturn(true);
        $this->container->expects($this->once())
            ->method('get')
            ->with($id)
            ->willThrowException(new \RuntimeException('resolve failed'));

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->bindingAction(DummyDiService::class);

        $this->assertSame(1, $code);
        $this->assertSame('resolve failed', $captured['error']);
        $this->assertSame($id, $captured['id']);
    }

    public function testResolveIdPrefersSameNamespaceInterfaceWhenBound(): void
    {
        $this->container->expects($this->once())
            ->method('has')
            ->with(DiCommandSameNamespaceServiceInterface::class)
            ->willReturn(true);

        $this->assertSame(
            DiCommandSameNamespaceServiceInterface::class,
            $this->command->exposedResolveId(DiCommandSameNamespaceService::class)
        );
    }

    public function testResolveIdPrefersContractInterfaceWhenBound(): void
    {
        $contract = DiCommandContractService::class;
        $contractInterface = \Switon\Beacon\Tests\Unit\Contract\DiCommandContractServiceInterface::class;

        $this->container->expects($this->once())
            ->method('has')
            ->with($contractInterface)
            ->willReturn(true);

        $this->assertSame(
            $contractInterface,
            $this->command->exposedResolveId($contract)
        );
    }

    public function testResolveIdReturnsInterfaceTargetAsIs(): void
    {
        $this->container->expects($this->never())->method('has');

        $this->assertSame(
            DummyDiServiceInterface::class,
            $this->command->exposedResolveId(DummyDiServiceInterface::class)
        );
    }

    public function testResolveIdFallsBackToOriginalTargetWhenNothingMatches(): void
    {
        $this->container->expects($this->never())->method('has');

        $target = 'Switon\\Beacon\\Tests\\Unit\\No\\Such\\Type';
        $this->assertSame($target, $this->command->exposedResolveId($target));
    }

    public function testScalarTypeHelpersRecognizeNamedAndUnionScalarTypes(): void
    {
        $intType = (new \ReflectionProperty(DiCommandTypeProbe::class, 'intValue'))->getType();
        $scalarUnionType = (new \ReflectionProperty(DiCommandTypeProbe::class, 'scalarUnion'))->getType();
        $objectUnionType = (new \ReflectionProperty(DiCommandTypeProbe::class, 'objectUnion'))->getType();
        $intersectionType = (new \ReflectionProperty(DiCommandTypeProbe::class, 'intersectionValue'))->getType();

        $this->assertInstanceOf(\ReflectionType::class, $intType);
        $this->assertTrue($this->command->exposedIsScalarOrArrayType($intType));
        $this->assertSame('int', $this->command->exposedTypeToString($intType));

        $this->assertInstanceOf(\ReflectionType::class, $scalarUnionType);
        $this->assertTrue($this->command->exposedIsScalarOrArrayType($scalarUnionType));
        $this->assertSame('string|int', $this->command->exposedTypeToString($scalarUnionType));

        $this->assertInstanceOf(\ReflectionType::class, $objectUnionType);
        $this->assertFalse($this->command->exposedIsScalarOrArrayType($objectUnionType));
        $this->assertSame('stdClass|int', $this->command->exposedTypeToString($objectUnionType));

        $this->assertInstanceOf(\ReflectionType::class, $intersectionType);
        $this->assertFalse($this->command->exposedIsScalarOrArrayType($intersectionType));
        $this->assertSame('mixed', $this->command->exposedTypeToString($intersectionType));
    }

    private function makeCommand(): DiCommandHarness
    {
        $container = new Container([
            ConsoleInterface::class => $this->console,
            ContainerInterface::class => $this->container,
        ]);

        return $container->make(DiCommandHarness::class);
    }
}

class DiCommandHarness extends DiCommand
{
    public function exposedResolveId(string $target): string
    {
        return $this->resolveId($target);
    }

    public function exposedIsScalarOrArrayType(\ReflectionType $type): bool
    {
        return $this->isScalarOrArrayType($type);
    }

    public function exposedTypeToString(\ReflectionType $type): string
    {
        return $this->typeToString($type);
    }
}

class DiCommandSameNamespaceService
{
}

interface DiCommandSameNamespaceServiceInterface
{
}

class DiCommandContractService
{
}

class DiCommandTypeProbe
{
    public int $intValue = 1;

    public string|int $scalarUnion = 1;

    public \stdClass|int $objectUnion;

    public \Countable&\ArrayAccess $intersectionValue;
}

namespace Switon\Beacon\Tests\Unit\Contract;

interface DiCommandContractServiceInterface
{
}
