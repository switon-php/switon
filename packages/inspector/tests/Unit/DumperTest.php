<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextManagerInterface;
use Switon\Inspector\Dumper;
use Switon\Inspector\DumperInterface;
use Switon\Inspector\Tests\Fixtures\{TestContextAwareObject,
    TestContextObject,
    TestObject,
    TestObjectWithInstancesArray,
    TestObjectWithWeakMap,};
use Switon\Inspector\Tests\Fixtures\TestableDumper;
use Switon\Inspector\Tests\TestCase;
use Countable;
use JsonSerializable;
use WeakMap;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class DumperTest extends TestCase
{
    #[Autowired] protected DumperInterface $dumper;
    #[Autowired] protected ContextManagerInterface $contextManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Remove existing ContextManagerInterface that was resolved in parent::setUp()
        $this->container->remove(ContextManagerInterface::class);

        $this->contextManager = $this->createMock(ContextManagerInterface::class);
        $this->container->set(ContextManagerInterface::class, $this->contextManager);

        $dumper = $this->container->make(Dumper::class);
        $this->container->set(DumperInterface::class, $dumper);

        $this->injector->inject($this);
    }

    protected function getTestableDumper(): TestableDumper
    {
        $dumper = $this->container->make(TestableDumper::class);
        $this->injector->inject($dumper);
        return $dumper;
    }

    public function testGetPropertiesReturnsAllNonStaticProperties(): void
    {
        $object = new TestObject();

        $properties = $this->dumper->getProperties($object);

        $this->assertArrayHasKey('publicProperty', $properties);
        $this->assertArrayHasKey('protectedProperty', $properties);
        $this->assertArrayHasKey('privateProperty', $properties);
        $this->assertArrayHasKey('nullProperty', $properties);
        $this->assertArrayHasKey('arrayProperty', $properties);
        $this->assertArrayHasKey('objectProperty', $properties);
        $this->assertArrayNotHasKey('staticProperty', $properties);
    }

    public function testGetPropertiesReturnsCorrectValues(): void
    {
        $object = new TestObject();

        $properties = $this->dumper->getProperties($object);

        $this->assertSame('public', $properties['publicProperty']);
        $this->assertSame('protected', $properties['protectedProperty']);
        $this->assertSame('private', $properties['privateProperty']);
        $this->assertNull($properties['nullProperty']);
        $this->assertIsArray($properties['arrayProperty']);
        $this->assertIsString($properties['objectProperty']); // Object converted to class name
    }

    public function testGetPropertiesConvertsObjectsToClassName(): void
    {
        $object = new TestObject();

        $properties = $this->dumper->getProperties($object);

        $this->assertSame(stdClass::class, $properties['objectProperty']);
    }

    public function testGetPropertiesIncludesContextForContextAwareObjects(): void
    {
        $object = new TestContextAwareObject();
        $context = new TestContextObject();

        $this->contextManager->expects($this->once())
            ->method('getContext')
            ->with($object)
            ->willReturn($context);

        $properties = $this->dumper->getProperties($object);

        $this->assertArrayHasKey('context', $properties);
        $this->assertIsArray($properties['context']);
        $this->assertArrayHasKey('value', $properties['context']);
        $this->assertSame('context-value', $properties['context']['value']);
    }

    public function testDumpNormalizesProperties(): void
    {
        $object = new TestObject();

        $result = $this->dumper->dump($object);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('publicProperty', $result);
        // Normalize should convert objects to class names, arrays remain arrays
        $this->assertIsString($result['objectProperty']);
    }

    public function testDumpTruncatesLongStrings(): void
    {
        $object = new TestObject();
        $object->publicProperty = str_repeat('a', 200);

        $result = $this->dumper->dump($object);

        $this->assertStringContainsString('...', $result['publicProperty']);
        $this->assertLessThanOrEqual(131, strlen($result['publicProperty'])); // 128 + '...'
    }

    public function testDumpEscapesScriptTags(): void
    {
        $object = new TestObject();
        $object->publicProperty = 'Test </script> tag';

        $result = $this->dumper->dump($object);

        $this->assertStringContainsString('[/script]', $result['publicProperty']);
        $this->assertStringNotContainsString('</script>', $result['publicProperty']);
    }

    public function testNormalizeSerializesJsonSerializableObjects(): void
    {
        $jsonSerializable = new class () implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return [
                    'message' => 'value',
                    'nested' => ['item' => 'ok'],
                ];
            }
        };

        $result = $this->getTestableDumper()->testNormalize([
            'payload' => $jsonSerializable,
        ]);

        $this->assertSame('value', $result['payload']['message']);
        $this->assertSame('ok', $result['payload']['nested']['item']);
    }

    public function testNormalizeConvertsPlainObjectsToArrays(): void
    {
        $object = new class () {
            public string $name = 'plain';
        };

        $result = $this->getTestableDumper()->testNormalize([
            'payload' => $object,
        ]);

        $this->assertSame('plain', $result['payload']['name']);
    }

    public function testNormalizeConvertsWeakMapToArrayAndInterfaceObjectsToClassNames(): void
    {
        $key = new stdClass();
        $weakMap = new WeakMap();
        $weakMap[$key] = 'value';

        $countable = new class () implements Countable {
            public function count(): int
            {
                return 1;
            }
        };

        $result = $this->getTestableDumper()->testNormalize([
            'weakMap' => $weakMap,
            'countable' => $countable,
        ]);

        $this->assertSame([], $result['weakMap']);
        $this->assertSame($countable::class, $result['countable']);
    }

    public function testNormalizeTruncatesLongStrings(): void
    {
        $result = $this->getTestableDumper()->testNormalize([
            'payload' => str_repeat('a', 200),
        ]);

        $this->assertStringEndsWith('...', $result['payload']);
        $this->assertLessThanOrEqual(131, strlen($result['payload']));
    }

    public function testGetPropertiesExpandsWeakMapToClassKeyedArray(): void
    {
        $object = new TestObjectWithWeakMap();

        $properties = $this->dumper->getProperties($object);

        $this->assertIsArray($properties['weakMap']);
        $this->assertSame(42, $properties['weakMap'][stdClass::class]);
    }

    public function testGetPropertiesHandlesAutowiredInstancesArray(): void
    {
        // Arrange
        $object = new TestObjectWithInstancesArray();

        // Act
        $properties = $this->dumper->getProperties($object);

        // Assert
        $this->assertArrayHasKey('instances', $properties);
        $this->assertIsArray($properties['instances']);
        // Verify objects are converted to class names but array structure is preserved
        $this->assertArrayHasKey('key1', $properties['instances']);
        $this->assertArrayHasKey('key2', $properties['instances']);
        $this->assertArrayHasKey(0, $properties['instances']);
        $this->assertSame(stdClass::class, $properties['instances']['key1']);
        $this->assertSame(stdClass::class, $properties['instances']['key2']);
        $this->assertSame(stdClass::class, $properties['instances'][0]);
    }
}
