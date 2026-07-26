<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use Switon\OpenApi\OperationIdInterface;
use Switon\OpenApi\PhpReturnSchema;
use Switon\OpenApi\ResponseSchemaGuesser;
use Switon\OpenApi\RouteCollector;
use Switon\OpenApi\Tests\Unit\Fixtures\RouteCollectorControllerFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\RouteCollectorPlainControllerFixture;
use Switon\Routing\ControllerScannerInterface;
use Switon\Routing\MappingPath;
use Switon\Routing\RouterInterface;
use Switon\OpenApi\Tests\TestCase;
use stdClass;

class RouteCollectorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/SampleGroup.php';
        require_once __DIR__ . '/Fixtures/PhpReturnSchemaFixtures.php';
        require_once __DIR__ . '/Fixtures/RouteCollectorFixtures.php';
    }

    public function testCollectScansMappedControllersAndSkipsViewMappings(): void
    {
        $scanner = $this->createStub(ControllerScannerInterface::class);
        $scanner->method('getControllers')->willReturn([
            stdClass::class,
            RouteCollectorPlainControllerFixture::class,
            RouteCollectorControllerFixture::class,
        ]);
        $collector = $this->make(RouteCollector::class, [
            'controllerScanner' => $scanner,
            'mappingPath' => new MappingPath(),
            'router' => $this->routerStub(''),
            'operationIds' => $this->operationIdStub(),
            'responseSchemaGuesser' => $this->responseGuesserStub(),
        ]);

        $routes = $collector->collect();

        $this->assertCount(6, $routes);
        $patterns = array_column($routes, 'pattern');
        $this->assertContains('/users/{id}', $patterns);
        $this->assertContains('/api/admin', $patterns);
        $this->assertContains('/api/v2/admin', $patterns);
        $this->assertContains('/bulk', $patterns);
        $this->assertNotContains('pageAction', array_column($routes, 'handler'));

        $first = array_values(array_filter(
            $routes,
            static fn (array $route): bool => $route['handler'] === RouteCollectorControllerFixture::class . '::showAction'
        ))[0];
        $this->assertSame('GET', $first['verb']);
        $this->assertSame('admin::show', $first['operationId']);
        $this->assertArrayHasKey('responseSchema', $first);
        $this->assertSame('object', $first['responseSchema']['type'] ?? null);
        $this->assertArrayHasKey('properties', $first['responseSchema']);
    }

    public function testCollectSkipsControllersWhoseClassIsMissing(): void
    {
        $scanner = $this->createStub(ControllerScannerInterface::class);
        $scanner->method('getControllers')->willReturn([
            'Switon\\OpenApi\\Tests\\Unit\\MissingRouteCollectorController',
            RouteCollectorPlainControllerFixture::class,
        ]);
        $collector = $this->make(RouteCollector::class, [
            'controllerScanner' => $scanner,
            'mappingPath' => new MappingPath(),
            'router' => $this->routerStub(''),
            'operationIds' => $this->operationIdStub(),
            'responseSchemaGuesser' => $this->createStub(ResponseSchemaGuesser::class),
        ]);

        $this->assertSame([], $collector->collect());
    }

    public function testCollectPrependsStandardRouterPrefix(): void
    {
        $scanner = $this->createStub(ControllerScannerInterface::class);
        $scanner->method('getControllers')->willReturn([RouteCollectorControllerFixture::class]);
        $collector = $this->make(RouteCollector::class, [
            'controllerScanner' => $scanner,
            'mappingPath' => new MappingPath(),
            'router' => $this->routerStub('/api'),
            'operationIds' => $this->operationIdStub(),
            'responseSchemaGuesser' => $this->responseGuesserStub(),
        ]);

        $patterns = array_column($collector->collect(), 'pattern');
        $this->assertNotSame([], $patterns);
        foreach ($patterns as $pattern) {
            $this->assertStringStartsWith('/api', $pattern);
        }
    }

    public function testCollectKeepsOptionalQuestionMarkRouterPrefix(): void
    {
        $scanner = $this->createStub(ControllerScannerInterface::class);
        $scanner->method('getControllers')->willReturn([RouteCollectorControllerFixture::class]);
        $collector = $this->make(RouteCollector::class, [
            'controllerScanner' => $scanner,
            'mappingPath' => new MappingPath(),
            'router' => $this->routerStub('?/v1'),
            'operationIds' => $this->operationIdStub(),
            'responseSchemaGuesser' => $this->responseGuesserStub(),
        ]);

        $patterns = array_column($collector->collect(), 'pattern');
        $this->assertNotSame([], $patterns);
        foreach ($patterns as $pattern) {
            $this->assertStringStartsWith('?/v1', $pattern);
        }
    }

    public function testCollectReturnsEmptyArrayWhenNoControllerHasRequestMapping(): void
    {
        $scanner = $this->createStub(ControllerScannerInterface::class);
        $scanner->method('getControllers')->willReturn([RouteCollectorPlainControllerFixture::class]);
        $collector = $this->make(RouteCollector::class, [
            'controllerScanner' => $scanner,
            'mappingPath' => new MappingPath(),
            'router' => $this->routerStub(''),
            'operationIds' => $this->operationIdStub(),
            'responseSchemaGuesser' => $this->createStub(ResponseSchemaGuesser::class),
        ]);

        $this->assertSame([], $collector->collect());
    }

    protected function routerStub(string $prefix): RouterInterface
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getPrefix')->willReturn($prefix);

        return $router;
    }

    protected function operationIdStub(): OperationIdInterface
    {
        $ids = $this->createStub(OperationIdInterface::class);
        $ids->method('getOperationId')->willReturnCallback(
            static function (string $controller, string $action): string {
                return match ($action) {
                    'showAction' => 'admin::show',
                    'saveAction' => 'admin::save',
                    default => 'admin::' . preg_replace('/Action$/', '', $action),
                };
            }
        );

        return $ids;
    }

    protected function responseGuesserStub(): ResponseSchemaGuesser
    {
        return $this->make(ResponseSchemaGuesser::class, [
            'phpReturnSchema' => new PhpReturnSchema(),
        ]);
    }
}
