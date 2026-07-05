<?php

declare(strict_types=1);

namespace Switon\Command\Tests\Unit;

use DateTimeImmutable;
use Switon\Command\Attribute\Tool;
use ReflectionMethod;
use Switon\Command\CommandInspector;
use Switon\Command\Tests\TestCase;
use stdClass;
use Countable;
use IteratorAggregate;

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
        $this->assertSame('Detailed tool doc', $inspector->getActionAiDoc(CommandInspectorCoverageFixture::class, 'tool'));
        $this->assertNull($inspector->getActionAiDoc(CommandInspectorCoverageFixture::class, 'blankTool'));

        $actions = $inspector->getActions(CommandInspectorCoverageFixture::class);
        $this->assertArrayHasKey('fallback', $actions);
        $this->assertSame('Fallback action', $actions['fallback']);
        $this->assertArrayNotHasKey('hidden', $actions);
        $this->assertArrayNotHasKey('helper', $actions);

        $options = $inspector->getActionParameters(CommandInspectorCoverageFixture::class, 'fallback');
        $this->assertSame('string', $options['name']['type']);
        $this->assertSame('Name filter', $options['name']['description']);
        $this->assertSame('int', $options['count']['type']);
        $this->assertSame(3, $options['count']['default']);
        $this->assertNotSame([], $options);
        $this->assertSame([], $inspector->getActionParameters(CommandInspectorCoverageFixture::class, 'tool'));
        $this->assertSame([], $inspector->getActionParameters(CommandInspectorCoverageFixture::class, 'typed'));
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

        $this->assertSame([], $inspector->getActionParameters(CommandInspectorCoverageFixture::class, 'typed'));
    }

    public function testInspectorOptionTypeHelpersCoverUnionIntersectionAndNullTypes(): void
    {
        $method = new ReflectionMethod(CommandInspectorOptionTypeFixture::class, 'runAction');

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            match ($name) {
                'untyped' => $this->assertTrue(CommandInspector::isOptionType($type)),
                'scalar' => $this->assertTrue(CommandInspector::isOptionType($type)),
                'unionBuiltin' => $this->assertTrue(CommandInspector::isOptionType($type)),
                'service' => $this->assertFalse(CommandInspector::isOptionType($type)),
                'intersection' => $this->assertFalse(CommandInspector::isOptionType($type)),
                default => $this->fail('Unexpected parameter: ' . $name),
            };
            $this->assertIsString(CommandInspector::optionTypeName($type));
        }

        $this->assertSame('mixed', CommandInspector::optionTypeName(null));
    }

    public function testInspectorIgnoresMalformedAndUnknownParamDocLinesInOptions(): void
    {
        $inspector = $this->make(CommandInspector::class);
        $options = $inspector->getActionParameters(CommandInspectorNoisyDocFixture::class, 'noisy');

        $this->assertArrayHasKey('verbose', $options);
        $this->assertSame('Verbose mode', $options['verbose']['description']);
        $this->assertArrayNotHasKey('database', $options);
    }

    public function testInspectorReturnsEmptyDescriptionWhenClassDocHasNoSummaryLine(): void
    {
        $inspector = $this->make(CommandInspector::class);

        $this->assertSame('', $inspector->getCommandDescription(CommandInspectorEmptyDocLineFixture::class));
    }
}

class CommandInspectorOptionTypeFixture
{
    public function runAction(
        $untyped,
        string $scalar,
        int|string $unionBuiltin,
        DateTimeImmutable $service,
        Countable&IteratorAggregate $intersection,
    ): void {
    }
}

class CommandInspectorNoisyDocFixture
{
    /**
     * @param bool
     * @param bool $verbose Verbose mode
     * @param string $database Ignored because not an option
     */
    public function noisyAction(bool $verbose = false): void
    {
    }
}

class CommandInspectorEmptyDocLineFixture
{
}

/**
 * Visible summary.
 */
class CommandInspectorCoverageFixture
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
class CommandInspectorClassDocFixture
{
}

/**
 * @internal
 */
class CommandInspectorAtDocFixture
{
}
