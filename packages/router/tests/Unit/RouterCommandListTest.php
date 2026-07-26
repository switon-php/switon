<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Core\ConsoleInterface;
use Switon\Core\FilterMatcher;
use Switon\Core\FilterMatcherInterface;
use Switon\Routing\Command\RouterCommand;
use Switon\Routing\RouterInterface;
use Switon\Routing\Tests\TestCase;
use Switon\Testing\Mock\MockConsole;

class RouterCommandListTest extends TestCase
{
    protected RouterCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container->set(RouterInterface::class, $this->router);
        $this->container->set(ConsoleInterface::class, new MockConsole());
        $this->container->set(FilterMatcherInterface::class, new FilterMatcher());
        $this->command = $this->container->make(RouterCommand::class);
    }

    public function testListActionJsonReturnsRoutesSortedByPatternThenVerb(): void
    {
        $this->router->addPost('/a', 'App\\Controller\\AController::create');
        $this->router->addGet('/b', 'App\\Controller\\BController::index');
        $this->router->addGet('/a', 'App\\Controller\\AController::index');

        $exit = $this->command->listAction('', true);

        $this->assertSame(0, $exit);
        $json = $this->getLastJsonLine();
        $this->assertSame('', $json['prefix']);
        $this->assertSame(
            [
                ['verb' => 'GET', 'pattern' => '/a', 'handler' => 'App\\Controller\\AController::index'],
                ['verb' => 'POST', 'pattern' => '/a', 'handler' => 'App\\Controller\\AController::create'],
                ['verb' => 'GET', 'pattern' => '/b', 'handler' => 'App\\Controller\\BController::index'],
            ],
            array_map(
                static fn (array $route): array => [
                    'verb' => $route['verb'],
                    'pattern' => $route['pattern'],
                    'handler' => $route['handler'],
                ],
                $json['routes']
            )
        );
    }

    public function testListActionJsonAppliesFilterToVerbAndPath(): void
    {
        $this->router->addGet('/users', 'App\\Controller\\UserController::index');
        $this->router->addPost('/users', 'App\\Controller\\UserController::create');

        $exit = $this->command->listAction('POST', true);

        $this->assertSame(0, $exit);
        $json = $this->getLastJsonLine();
        $this->assertCount(1, $json['routes']);
        $this->assertSame('POST', $json['routes'][0]['verb']);
        $this->assertSame('/users', $json['routes'][0]['pattern']);
    }

    public function testPrefixActionReturnsJsonAndPlainFormats(): void
    {
        $this->router->setPrefix('/api');
        $jsonExit = $this->command->prefixAction(true);
        $json = $this->getLastJsonLine();

        $this->assertSame(0, $jsonExit);
        $this->assertSame('/api', $json['prefix']);

        $this->router->setPrefix('');
        $plainExit = $this->command->prefixAction(false);
        $this->assertSame(0, $plainExit);
        $this->assertSame('(empty)', $this->getLastConsoleLine());
    }

    protected function getLastJsonLine(): array
    {
        $console = $this->container->get(ConsoleInterface::class);
        $this->assertInstanceOf(MockConsole::class, $console);
        $lines = $console->getOutput();
        $this->assertNotEmpty($lines);
        $decoded = json_decode($lines[array_key_last($lines)], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    protected function getLastConsoleLine(): string
    {
        $console = $this->container->get(ConsoleInterface::class);
        $this->assertInstanceOf(MockConsole::class, $console);
        $lines = $console->getOutput();
        $this->assertNotEmpty($lines);
        return (string)$lines[array_key_last($lines)];
    }
}
