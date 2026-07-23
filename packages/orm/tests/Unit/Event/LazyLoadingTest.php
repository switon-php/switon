<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Unit\Event;

use Switon\Orm\Entity;
use Switon\Orm\Event\LazyLoading;
use Switon\Orm\RelationManagerInterface;
use PHPUnit\Framework\TestCase;

class LazyLoadingTest extends TestCase
{
    public function testEventSetsExpectedProperties(): void
    {
        $entity = new Entity();
        $event = new LazyLoading($this->createStub(RelationManagerInterface::class), $entity, 'posts');

        $this->assertSame($entity, $event->entity);
        $this->assertSame('posts', $event->relationName);
    }
}
