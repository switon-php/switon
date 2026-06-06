<?php

declare(strict_types=1);

namespace Switon\Command\Tests\Unit;

use DateTimeImmutable;
use ReflectionMethod;
use Switon\Command\Attribute\Tool;
use Switon\Command\CommandInspector;
use Switon\Command\Tests\TestCase;
use stdClass;

class CommandInspectorCoverageTest extends TestCase
{
    public function testInspectorReturnsFallbacksForMissingCommandAndAction(): void
    {
        $inspector = $this->make(CommandInspector::class);

        $this->assertFalse($inspector->isHiddenCommand('Switon\\Command\\Tests\\Unit\\DoesNotExist'));
        $this->assertSame('', $inspector->getCommandDescription('Switon\\Command\\Tests\\Unit\\DoesNotExist'));
        $this->assertSame([], $inspector->getActions('Switon\\Command\\Tests\\Unit\\DoesNotExist'));
        $this->assertSame('', $inspector->getCommandDescription(stdClass::class));
        $this->assertNull($inspector->getActionAiDoc(CommandInspectorCoverageFixture::class, 'missing'));
        $this->assertNull($inspector->getActionAiDoc(CommandInspectorCoverageFixture::class, 'fallback'));
        $this->assertSame([], $inspector->getActionParameters(CommandInspectorCoverageFixture::class, 'missing'));
        $this->assertSame([], $inspector->getActionParameters('Switon\\Command\\Tests\\Unit\\DoesNotExist', 'missing'));
    }

    public function testInspectorDerivesDescriptionsAndOptionsFromReflection(): void
    {
        $inspector = $this->make(CommandInspector::class);

        $this->assertSame('Visible summary', $inspector->getCommandDescription(CommandInspectorCoverageFixture::class));
        $this->assertSame('Fallback action', $inspector->getMethodDescription(new ReflectionMethod(CommandInspectorCoverageFixture::class, 'fallbackAction')));
        $this->assertSame('Detailed tool doc', $inspector->getActionAiDoc(CommandInspectorCoverageFixture::class, 'tool'));
        $this->assertNull($inspector->getActionAiDoc(CommandInspectorCoverageFixture::class, 'blankTool'));

        $actions = $inspector->getActions(CommandInspectorCoverageFixture::class);
        $this->assertArrayHasKey('fallback', $actions);
        $this->assertArrayNotHasKey('hidden', $actions);
        $this->assertArrayNotHasKey('helper', $actions);

        $options = $inspector->getOptions(new ReflectionMethod(CommandInspectorCoverageFixture::class, 'fallbackAction'));
        $this->assertSame('string', $options['name']['type']);
        $this->assertSame('Name filter', $options['name']['description']);
        $this->assertSame('int', $options['count']['type']);
        $this->assertSame(3, $options['count']['default']);
        $this->assertTrue($inspector->hasOptions(new ReflectionMethod(CommandInspectorCoverageFixture::class, 'fallbackAction')));
        $this->assertFalse($inspector->hasOptions(new ReflectionMethod(CommandInspectorCoverageFixture::class, 'toolAction')));
        $this->assertFalse($inspector->hasOptions(new ReflectionMethod(CommandInspectorCoverageFixture::class, 'typedAction')));
        $this->assertSame('helper', $inspector->getMethodDescription(new ReflectionMethod(CommandInspectorCoverageFixture::class, 'helper')));

        $params = $inspector->getActionParameters(CommandInspectorCoverageFixture::class, 'fallback');
        $this->assertSame($options, $params);
    }

    public function testInspectorIncludesHiddenActionsWhenRequested(): void
    {
        $inspector = $this->make(CommandInspector::class);

        $actions = $inspector->getActions(CommandInspectorCoverageFixture::class, true);

        $this->assertArrayHasKey('hidden', $actions);
        $this->assertSame('Hidden action', $actions['hidden']);
    }

    public function testInspectorRejectsCommandDocsThatOnlyContainMetadata(): void
    {
        $inspector = $this->make(CommandInspector::class);

        $this->assertSame('', $inspector->getCommandDescription(CommandInspectorClassDocFixture::class));
        $this->assertSame('', $inspector->getCommandDescription(CommandInspectorAtDocFixture::class));
    }

    public function testInspectorIgnoresObjectTypedActionsForOptions(): void
    {
        $inspector = $this->make(CommandInspector::class);

        $method = new ReflectionMethod(CommandInspectorCoverageFixture::class, 'typedAction');

        $this->assertFalse($inspector->hasOptions($method));
        $this->assertSame([], $inspector->getOptions($method));
    }
}

/**
 * Visible summary.
 */
final class CommandInspectorCoverageFixture
{
    /**
     * Fallback action.
     *
     * @param string $name Name filter
     * @param int $count Count filter
     */
    public function fallbackAction(string $name, int $count = 3): void
    {
    }

    public function helper(): void
    {
    }

    #[Tool('Detailed tool doc')]
    public function toolAction(): void
    {
    }

    #[Tool('   ')]
    public function blankToolAction(): void
    {
    }

    public function typedAction(DateTimeImmutable $date): void
    {
    }

    /**
     * Hidden action.
     */
    #[\Switon\Command\Attribute\Hidden]
    public function hiddenAction(): void
    {
    }
}

/**
 * Class-only summary.
 */
final class CommandInspectorClassDocFixture
{
}

/**
 * @internal
 */
final class CommandInspectorAtDocFixture
{
}
