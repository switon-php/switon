<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Unit;

use Switon\Orm\Event\EntityCreating;
use Switon\Orm\EntityManagerInterface;
use Switon\Orm\Tests\Fixtures\TestEntity;
use Switon\Orm\Tests\TestCase;

use function json_decode;
use function json_encode;

class AbstractEntityEventTest extends TestCase
{
    public function testConstructorInitializesEntityAndOriginal(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Test']);
        $original = new TestEntity(['id' => 1, 'name' => 'Original']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity, $original);

        $this->assertSame($entity, $event->getEntity());
        $this->assertSame($original, $event->getOriginal());
    }

    public function testConstructorHandlesNullOriginal(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Test']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity);

        $this->assertSame($entity, $event->getEntity());
        $this->assertNull($event->getOriginal());
    }

    public function testGetEntityReturnsCurrentEntity(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Test']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity);

        $result = $event->getEntity();

        $this->assertSame($entity, $result);
    }

    public function testGetOriginalReturnsOriginalEntityWhenProvided(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Test']);
        $original = new TestEntity(['id' => 1, 'name' => 'Original']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity, $original);

        $result = $event->getOriginal();

        $this->assertSame($original, $result);
    }

    public function testHasChangedReturnsTrueWhenFieldHasChanged(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'New Name', 'status' => 1]);
        $original = new TestEntity(['id' => 1, 'name' => 'Original Name', 'status' => 1]);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity, $original);

        $result = $event->hasChanged(['name']);

        $this->assertTrue($result);
    }

    public function testHasChangedReturnsFalseWhenFieldHasNotChanged(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Same Name', 'status' => 1]);
        $original = new TestEntity(['id' => 1, 'name' => 'Same Name', 'status' => 1]);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity, $original);

        $result = $event->hasChanged(['name']);

        $this->assertFalse($result);
    }

    public function testHasChangedReturnsFalseWhenOriginalIsNull(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Test']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity);

        $result = $event->hasChanged(['name']);

        $this->assertFalse($result);
    }

    public function testHasChangedReturnsFalseForEmptyFieldList(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'New']);
        $original = new TestEntity(['id' => 1, 'name' => 'Old']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity, $original);

        $this->assertFalse($event->hasChanged([]));
    }

    public function testHasChangedIgnoresFieldsNotSetOnCurrentEntity(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Same', 'status' => 1]);
        $original = new TestEntity(['id' => 1, 'name' => 'Same', 'status' => 0]);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity, $original);

        // price is absent on both via constructor defaults; isset is false — no positive match
        $this->assertFalse($event->hasChanged(['price']));

        // status differs but we only ask for a non-existent field name on the entity
        $this->assertFalse($event->hasChanged(['nonexistent']));
    }

    public function testHasChangedReturnsTrueWhenAnyFieldHasChanged(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'New Name', 'status' => 1]);
        $original = new TestEntity(['id' => 1, 'name' => 'Original Name', 'status' => 0]);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity, $original);

        $result = $event->hasChanged(['name', 'status']);

        $this->assertTrue($result);
    }

    public function testJsonSerializeReturnsArrayWithEntityClassAndFields(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Test']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity);

        $result = $event->jsonSerialize();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('entity', $result);
        $this->assertArrayHasKey('fields', $result);
        $this->assertSame(TestEntity::class, $result['entity']);
        $this->assertIsArray($result['fields']);
        $this->assertSame(1, $result['fields']['id']);
        $this->assertSame('Test', $result['fields']['name']);
    }

    public function testEventCanBeEncodedToJson(): void
    {
        $entity = new TestEntity(['id' => 1, 'name' => 'Test']);
        $sender = $this->createStub(EntityManagerInterface::class);
        $event = new EntityCreating($sender, $entity);

        $json = json_encode($event);
        $decoded = json_decode($json, true);

        $this->assertIsString($json);
        $this->assertSame(TestEntity::class, $decoded['entity']);
        $this->assertArrayHasKey('fields', $decoded);
        $this->assertSame(1, $decoded['fields']['id']);
    }
}
