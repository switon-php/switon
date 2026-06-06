<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Routing\Matcher;
use Switon\Routing\MatcherInterface;

/**
 * Test cases for Matcher handler resolution and parameter management.
 *
 * Tests handler template processing, naming convention transformations,
 * parameter consumption, and variable modification.
 */
class MatcherTest extends TestCase
{
    /**
     * Test static handler without template placeholders.
     *
     * Verifies that handlers without {param} placeholders are returned as-is
     * and all variables are preserved.
     */
    public function testStaticHandlerReturnedAsIs(): void
    {
        // Arrange & Act
        $matcher = new Matcher('UserController::index', ['page' => '2']);

        // Assert
        $this->assertSame('UserController::index', $matcher->getHandler(), 'Static handler should be returned unchanged');
        $this->assertSame(['page' => '2'], $matcher->getVariables(), 'All variables should be preserved for static handler');
    }

    /**
     * Test static handler with empty variables.
     *
     * Verifies that Matcher works with no variables at all.
     */
    public function testStaticHandlerWithEmptyVariables(): void
    {
        // Arrange & Act
        $matcher = new Matcher('HomeController::index', []);

        // Assert
        $this->assertSame('HomeController::index', $matcher->getHandler());
        $this->assertSame([], $matcher->getVariables());
    }

    /**
     * Test action parameter applies camelCase transformation.
     *
     * Verifies that {action} placeholder is replaced with camelCase value
     * and consumed from variables.
     */
    public function testActionParameterAppliesCamelCase(): void
    {
        // Arrange & Act
        $matcher = new Matcher('UserController::{action}Action', ['action' => 'show-profile']);

        // Assert
        $this->assertSame('UserController::showProfileAction', $matcher->getHandler(), '{action} should be converted to camelCase');
        $this->assertArrayNotHasKey('action', $matcher->getVariables(), 'action should be consumed from variables');
    }

    /**
     * Test non-action parameter applies PascalCase transformation.
     *
     * Verifies that non-action placeholders like {controller} are replaced
     * with PascalCase value and consumed from variables.
     */
    public function testControllerParameterAppliesPascalCase(): void
    {
        // Arrange & Act
        $matcher = new Matcher('{controller}::index', ['controller' => 'user-profile']);

        // Assert
        $this->assertSame('UserProfile::index', $matcher->getHandler(), '{controller} should be converted to PascalCase');
        $this->assertArrayNotHasKey('controller', $matcher->getVariables(), 'controller should be consumed from variables');
    }

    /**
     * Test multiple template parameters with mixed naming conventions.
     *
     * Verifies that multiple placeholders are resolved correctly and
     * only non-template variables remain.
     */
    public function testMultipleTemplateParameters(): void
    {
        // Arrange & Act
        $matcher = new Matcher('{controller}::{action}Action', [
            'controller' => 'admin-panel',
            'action' => 'list-all',
            'id' => '42',
        ]);

        // Assert
        $this->assertSame('AdminPanel::listAllAction', $matcher->getHandler(), 'Both controller and action should be resolved');
        $this->assertSame(['id' => '42'], $matcher->getVariables(), 'Only non-template variables should remain');
    }

    /**
     * Test parameter consumption removes template variables from getVariables().
     *
     * Verifies that variables used in handler template are NOT present
     * in the returned variables array.
     */
    public function testParameterConsumption(): void
    {
        // Arrange & Act
        $matcher = new Matcher('Controller::{action}', [
            'action' => 'index',
            'page' => '1',
            'sort' => 'name',
        ]);

        // Assert
        $variables = $matcher->getVariables();
        $this->assertArrayNotHasKey('action', $variables, 'Template parameter should be consumed');
        $this->assertArrayHasKey('page', $variables, 'Non-template parameter should be preserved');
        $this->assertArrayHasKey('sort', $variables, 'Non-template parameter should be preserved');
    }

    /**
     * Test setVariables modifies the variables after construction.
     *
     * Verifies that setVariables() allows modifying route parameters
     * (used by event listeners for parameter transformation).
     */
    public function testSetVariablesModifiesParameters(): void
    {
        // Arrange
        $matcher = new Matcher('UserController::show', ['id' => '123']);

        // Act
        $matcher->setVariables(['id' => 456, 'validated' => true]);

        // Assert
        $this->assertSame(['id' => 456, 'validated' => true], $matcher->getVariables(), 'setVariables should replace all variables');
    }

    /**
     * Test handler template with missing variable uses empty string.
     *
     * Verifies that {param} placeholders with no corresponding variable
     * are replaced with empty string after naming transformation.
     */
    public function testTemplateWithMissingVariableUsesEmptyString(): void
    {
        // Arrange & Act
        $matcher = new Matcher('Controller::{action}Action', []);

        // Assert
        $this->assertSame('Controller::Action', $matcher->getHandler(), 'Missing action should result in empty camelCase value');
    }

    /**
     * Test Matcher implements MatcherInterface.
     */
    public function testImplementsMatcherInterface(): void
    {
        // Arrange & Act
        $matcher = new Matcher('Controller::index', []);

        // Assert
        $this->assertInstanceOf(MatcherInterface::class, $matcher);
    }

    /**
     * Test simple action without hyphen stays as-is in camelCase.
     *
     * Verifies single-word actions like 'index' or 'create' are not modified.
     */
    public function testSimpleActionStaysAsIs(): void
    {
        // Arrange & Act
        $matcher = new Matcher('UserController::{action}Action', ['action' => 'index']);

        // Assert
        $this->assertSame('UserController::indexAction', $matcher->getHandler());
    }
}
