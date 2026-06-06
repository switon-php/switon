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

/**
 * Tests for RouterCommand::resolveAction (router:resolve path [verb] --json).
 */
class RouterCommandResolveTest extends TestCase
{
    private RouterCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container->set(RouterInterface::class, $this->router);
        $this->container->set(ConsoleInterface::class, new MockConsole());
        $this->container->set(FilterMatcherInterface::class, new FilterMatcher());
        $this->command = $this->container->make(RouterCommand::class);
    }

    public function testResolveSingleVerbReturnsMatchedAndResults(): void
    {
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addPost('/users', 'UserController::create');

        $exit = $this->command->resolveAction('/users', 'GET', true);

        $this->assertSame(0, $exit);
        $json = $this->getLastJsonLine();
        $this->assertTrue($json['matched']);
        $this->assertSame('/users', $json['path']);
        $this->assertSame('GET', $json['verb']);
        $this->assertNull($json['error']);
        $this->assertCount(1, $json['results']);
        $this->assertSame('GET', $json['results'][0]['verb']);
        $this->assertSame('UserController::index', $json['results'][0]['handler']);
        $this->assertSame([], $json['results'][0]['params']);
    }

    public function testResolveNoVerbTriesAllCommonVerbsAndReturnsMultipleResults(): void
    {
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addPost('/users', 'UserController::create');

        $exit = $this->command->resolveAction('/users', '', true);

        $this->assertSame(0, $exit);
        $json = $this->getLastJsonLine();
        $this->assertTrue($json['matched']);
        $this->assertSame('/users', $json['path']);
        $this->assertNull($json['verb']);
        $this->assertNull($json['error']);
        $this->assertGreaterThanOrEqual(2, count($json['results']));
        $handlers = array_column($json['results'], 'handler', 'verb');
        $this->assertSame('UserController::index', $handlers['GET'] ?? null);
        $this->assertSame('UserController::create', $handlers['POST'] ?? null);
    }

    public function testResolveNoMatchReturnsMatchedFalseAndError(): void
    {
        $this->router->addGet('/users', 'UserController::index');

        $exit = $this->command->resolveAction('/posts', 'GET', true);

        $this->assertSame(1, $exit);
        $json = $this->getLastJsonLine();
        $this->assertFalse($json['matched']);
        $this->assertSame('/posts', $json['path']);
        $this->assertSame([], $json['results']);
        $this->assertSame('no route matches', $json['error']);
    }

    public function testResolvePathWithParamsReturnsParamsInResult(): void
    {
        $this->router->addGet('/users/{id}', 'UserController::show');

        $exit = $this->command->resolveAction('/users/42', 'GET', true);

        $this->assertSame(0, $exit);
        $json = $this->getLastJsonLine();
        $this->assertTrue($json['matched']);
        $this->assertCount(1, $json['results']);
        $this->assertSame(['id' => '42'], $json['results'][0]['params']);
    }

    public function testResolvePathReflectedInOutput(): void
    {
        $this->router->addGet('/users', 'UserController::index');

        $exit = $this->command->resolveAction('/users', 'GET', true);

        $this->assertSame(0, $exit);
        $json = $this->getLastJsonLine();
        $this->assertSame('/users', $json['path']);
    }

    private function getLastJsonLine(): array
    {
        $console = $this->container->get(ConsoleInterface::class);
        $this->assertInstanceOf(MockConsole::class, $console);
        $lines = $console->getOutput();
        $this->assertNotEmpty($lines);
        $decoded = json_decode($lines[array_key_last($lines)], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }
}
