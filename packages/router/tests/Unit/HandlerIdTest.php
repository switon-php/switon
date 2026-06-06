<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Core\Attribute\Autowired;
use Switon\Routing\HandlerIdInterface;
use Switon\Routing\Tests\TestCase;

/**
 * Test cases for HandlerId component.
 *
 * Tests handler ID generation from controller and action names.
 */
class HandlerIdTest extends TestCase
{
    #[Autowired] protected HandlerIdInterface $handlerId;

    public function testGetIdGeneratesIdFromControllerAndAction(): void
    {
        $controller = 'App\\Controller\\UserController';
        $action = 'index';

        $result = $this->handlerId->getId($controller, $action);

        $this->assertIsString($result, 'getId() should return string');
        $this->assertNotEmpty($result, 'getId() should return non-empty string');
    }

    public function testGetIdGeneratesConsistentIds(): void
    {
        $controller = 'App\\Controller\\UserController';
        $action = 'show';

        $result1 = $this->handlerId->getId($controller, $action);
        $result2 = $this->handlerId->getId($controller, $action);

        $this->assertSame($result1, $result2, 'getId() should generate consistent IDs for same input');
    }

    public function testGetIdGeneratesDifferentIdsForDifferentControllers(): void
    {
        $controller1 = 'App\\Controller\\UserController';
        $controller2 = 'App\\Controller\\PostController';
        $action = 'index';

        $result1 = $this->handlerId->getId($controller1, $action);
        $result2 = $this->handlerId->getId($controller2, $action);

        $this->assertNotSame($result1, $result2, 'getId() should generate different IDs for different controllers');
    }

    public function testGetIdGeneratesDifferentIdsForDifferentActions(): void
    {
        $controller = 'App\\Controller\\UserController';
        $action1 = 'index';
        $action2 = 'show';

        $result1 = $this->handlerId->getId($controller, $action1);
        $result2 = $this->handlerId->getId($controller, $action2);

        $this->assertNotSame($result1, $result2, 'getId() should generate different IDs for different actions');
    }

    public function testGetIdHandlesSimpleControllerNames(): void
    {
        $controller = 'UserController';
        $action = 'index';

        $result = $this->handlerId->getId($controller, $action);

        $this->assertIsString($result, 'getId() should handle simple controller names');
        $this->assertNotEmpty($result, 'getId() should return non-empty string for simple names');
    }

    public function testGetIdHandlesNamespacedControllerNames(): void
    {
        $controller = 'App\\Http\\Controller\\Admin\\UserController';
        $action = 'index';

        $result = $this->handlerId->getId($controller, $action);

        $this->assertIsString($result, 'getId() should handle namespaced controller names');
        $this->assertNotEmpty($result, 'getId() should return non-empty string for namespaced names');
    }

    public function testGetIdHandlesActionNamesWithUnderscores(): void
    {
        $controller = 'UserController';
        $action = 'show_profile';

        $result = $this->handlerId->getId($controller, $action);

        $this->assertIsString($result, 'getId() should handle action names with underscores');
        $this->assertNotEmpty($result, 'getId() should return non-empty string');
    }

    public function testGetIdHandlesCamelCaseActionNames(): void
    {
        $controller = 'UserController';
        $action = 'showProfile';

        $result = $this->handlerId->getId($controller, $action);

        $this->assertIsString($result, 'getId() should handle camelCase action names');
        $this->assertNotEmpty($result, 'getId() should return non-empty string');
    }
}
