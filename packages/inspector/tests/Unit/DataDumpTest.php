<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use ArrayObject;
use JsonSerializable;
use RuntimeException;
use Switon\Core\Attribute\Autowired;
use Switon\Inspector\DataDump;
use Switon\Inspector\DataDumpInterface;
use Switon\Inspector\Tests\Fixtures\TestableDataDump;
use Switon\Inspector\Tests\TestCase;

require_once __DIR__ . '/../Fixtures/ConsoleLogOverride.php';

class DataDumpTest extends TestCase
{
    #[Autowired] protected DataDumpInterface $dataDump;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDump = $this->container->make(DataDump::class);
        $this->container->set(DataDumpInterface::class, $this->dataDump);
        $this->injector->inject($this);
    }

    protected function getTestableDataDump(): TestableDataDump
    {
        $testable = $this->container->make(TestableDataDump::class);
        $this->injector->inject($testable);
        return $testable;
    }

    public function testGetLocationReturnsNextTraceAfterOutput(): void
    {
        $testable = $this->getTestableDataDump();
        $location = $testable->testGetLocation([
            ['file' => '/tmp/source.php', 'line' => 10, 'function' => 'bootstrap'],
            ['file' => '/tmp/inner.php', 'line' => 20, 'function' => 'output'],
            ['file' => '/tmp/caller.php', 'line' => 30, 'function' => 'test'],
        ]);

        $this->assertSame('/tmp/caller.php', $location['file']);
        $this->assertSame(30, $location['line']);
    }

    public function testGetLocationReturnsEmptyArrayWhenOutputIsMissing(): void
    {
        $testable = $this->getTestableDataDump();
        $location = $testable->testGetLocation([
            ['file' => '/tmp/source.php', 'line' => 10, 'function' => 'bootstrap'],
            ['file' => '/tmp/inner.php', 'line' => 20, 'function' => 'render'],
        ]);

        $this->assertSame([], $location);
    }

    public function testFormatMessageWithString(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage('Simple string message');

        $this->assertSame('Simple string message', $result);
    }

    public function testFormatMessageWithThrowable(): void
    {
        $exception = new RuntimeException('Test error');

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($exception);

        $this->assertStringContainsString('RuntimeException', $result);
        $this->assertStringContainsString('Test error', $result);
    }

    public function testFormatMessageWithJsonSerializable(): void
    {
        $jsonObject = new class () implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['key' => 'value'];
            }
        };

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($jsonObject);

        $this->assertStringContainsString('"key"', $result);
        $this->assertStringContainsString('"value"', $result);
    }

    public function testFormatMessageWithArrayObject(): void
    {
        $arrayObject = new ArrayObject(['key' => 'value']);

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($arrayObject);

        $this->assertStringContainsString('"key"', $result);
        $this->assertStringContainsString('"value"', $result);
    }

    public function testFormatMessageWithArrayMessageContainingMixedValues(): void
    {
        $exception = new RuntimeException('Boom');
        $jsonObject = new class () implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['kind' => 'json'];
            }
        };
        $arrayObject = new ArrayObject(['kind' => 'array-object']);

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage([
            'Issue %s %s %s %s %s',
            $exception,
            ['nested' => true],
            $jsonObject,
            $arrayObject,
            42,
        ]);

        $this->assertStringContainsString('RuntimeException', $result);
        $this->assertStringContainsString('"nested"', $result);
        $this->assertStringContainsString('"kind"', $result);
        $this->assertStringContainsString('42', $result);
    }

    public function testFormatMessageAddsTwoElementPlaceholderWhenMissing(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['User name', 'John']);

        $this->assertSame('User name: John', $result);
    }

    public function testFormatMessageAddsThreeElementPlaceholderWhenMissing(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['User', 'name', 'John']);

        $this->assertSame('User: name => John', $result);
    }

    public function testFormatMessageReplacesNonStringValuesWhenPlaceholdersArePresent(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['Value :1 :2', new class () {
            public function __toString(): string
            {
                return 'stringable';
            }
        }, 3.14]);

        $this->assertStringContainsString('stringable', $result);
        $this->assertStringContainsString('3.14', $result);
    }

    public function testFormatMessageReplacesThrowableAndJsonValuesInPlaceholderMode(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['Error :1 => :2', new RuntimeException('Oops'), ['nested' => true]]);

        $this->assertStringContainsString('RuntimeException', $result);
        $this->assertStringContainsString('"nested"', $result);
    }

    public function testFormatMessageKeepsStringValueAsIsInPlaceholderMode(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['Value :1', 'plain-text']);

        $this->assertSame('Value plain-text', $result);
    }

    public function testFormatMessageStringifiesNullAndBoolInPlaceholderMode(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['Value :1 :2', null, true]);

        $this->assertSame('Value null true', $result);
    }

    public function testFormatMessageStringifiesObjectsWithoutSpecialHandlers(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['Value :1', new class () {
            public function __toString(): string
            {
                return 'stringable';
            }
        }]);

        $this->assertSame('Value stringable', $result);
    }

    public function testFormatMessageStringifiesScalarFloat(): void
    {
        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage(['Value :1', 3.14]);

        $this->assertSame('Value 3.14', $result);
        $this->assertStringContainsString('3.14', $result);
    }

    public function testFormatMessageWithNonStringArray(): void
    {
        $message = [['nested' => 'array']];

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($message);

        $this->assertStringContainsString('"nested"', $result);
        $this->assertStringContainsString('"array"', $result);
    }

    public function testFormatMessageWithSprintfFormat(): void
    {
        $message = ['User %s has %d items', 'John', 5];

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($message);

        $this->assertStringContainsString('User', $result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('5', $result);
    }

    public function testFormatMessageWithPlaceholderReplacement(): void
    {
        $message = ['User :1 has :2 items', 'John', 5];

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($message);

        $this->assertStringContainsString('User', $result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('5', $result);
    }

    public function testFormatMessageWithTwoElementsAutoFormat(): void
    {
        $message = ['User name', 'John'];

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($message);

        $this->assertStringContainsString('User name', $result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString(':', $result);
    }

    public function testFormatMessageWithThreeElementsAutoFormat(): void
    {
        $message = ['User', 'name', 'John'];

        $testable = $this->getTestableDataDump();
        $result = $testable->testFormatMessage($message);

        $this->assertStringContainsString('User', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('John', $result);
    }

    public function testOutputWithString(): void
    {
        ob_start();
        $this->dataDump->output('Test message');
        $output = ob_get_clean();

        $this->assertStringContainsString('Test message', $output);
        $this->assertStringContainsString(':', $output); // Time format contains ':'
        $this->assertStringEndsWith(PHP_EOL, $output);
    }

    public function testOutputWithSingleElementArray(): void
    {
        ob_start();
        $this->dataDump->output(['Single message']);
        $output = ob_get_clean();

        $this->assertStringContainsString('Single message', $output);
    }

    public function testOutputWithThrowable(): void
    {
        $exception = new RuntimeException('Test error');
        $exceptionFile = basename($exception->getFile());
        $exceptionLine = $exception->getLine();

        ob_start();
        $this->dataDump->output($exception);
        $output = ob_get_clean();

        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringContainsString('Test error', $output);
        $this->assertStringContainsString($exceptionFile, $output);
        $this->assertStringContainsString((string)$exceptionLine, $output);
    }

    public function testOutputFormatContainsTimeAndLocation(): void
    {
        ob_start();
        $this->dataDump->output('Test');
        $output = ob_get_clean();

        // Check format contains time (HH:MM:SS.mmm pattern)
        $this->assertMatchesRegularExpression('/\d{2}:\d{2}:\d{2}\.\d{3}/', $output);
        // Check format contains location (file:line pattern)
        $this->assertStringContainsString(':', $output);
    }
}
