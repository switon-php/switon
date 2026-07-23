<?php

declare(strict_types=1);

namespace Switon\Orm\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface as SwitonEventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Orm\EntityHydratorInterface;
use Switon\Orm\EntityMetadataInterface;
use Switon\Orm\Relation\HasManyToManyRelation;
use Switon\Orm\Relation\JunctionManyRelation;
use Switon\Orm\Relation\MorphToRelation;
use Switon\Orm\Tests\Fixtures\TestPermission;
use Switon\Orm\Tests\Fixtures\TestRole;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;

use function sys_get_temp_dir;

/**
 * Base test case for ORM tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected DiContainer $container;
    protected InjectorInterface $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new DiContainer();

        $diProvider = new DiServiceProvider();
        $diProvider->register($this->container);
        $diProvider->boot();

        App::setContainer($this->container);

        $pathAlias = new PathAlias();
        $pathAlias->set('@runtime', sys_get_temp_dir());
        $this->container->set(PathAliasInterface::class, $pathAlias);
        $this->container->set(PathAlias::class, $pathAlias);
        $this->container->set(ContextManagerInterface::class, InMemoryContextManager::class);
        $this->container->set(LoggerInterface::class, new MockLogger());

        $this->container->set(RealEventDispatcher::class, RealEventDispatcher::class);
        $this->container->set(SwitonEventDispatcherInterface::class, function (): TestEventDispatcher {
            $dispatcher = new TestEventDispatcher($this->container->get(RealEventDispatcher::class));
            $dispatcher->setDispatchEvents(false);

            return $dispatcher;
        });
        $this->container->set(PsrEventDispatcherInterface::class, SwitonEventDispatcherInterface::class);
        $this->container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

        $this->injector = $this->container->get(InjectorInterface::class);
        $this->setUpContainer();
        $this->injector->inject($this);
    }

    protected function setUpContainer(): void
    {
        $stubIdentity = $this->createStub(\Switon\Principal\IdentityInterface::class);
        $stubIdentity->method('isGuest')->willReturn(true);
        $stubIdentity->method('getId')->willReturn(0);
        $stubIdentity->method('getName')->willReturn('');
        $stubIdentity->method('getRoles')->willReturn([]);
        $this->container->set(\Switon\Principal\IdentityInterface::class, $stubIdentity);
    }

    protected function make(string $name, array $parameters = []): mixed
    {
        return $this->container->make($name, $parameters);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $relationClass
     *
     * @return T
     */
    protected function createRelation(string $relationClass, array $parameters = []): object
    {
        return $this->make($relationClass, $parameters);
    }

    /**
     * @param array<string, int> $orderBy
     */
    /**
     * @param array<int|string, class-string<\Switon\Orm\Entity>> $morphs
     */
    protected function makeMorphToRelation(
        string $tableField,
        string $idField,
        EntityMetadataInterface $entityMetadata,
        array $morphs = [],
        string $class = MorphToRelation::class,
    ): MorphToRelation {
        /** @var MorphToRelation $relation */
        $relation = $this->make($class, [
            'tableField' => $tableField,
            'idField' => $idField,
            'entityMetadata' => $entityMetadata,
            'morphs' => $morphs,
        ]);

        return $relation;
    }

    protected function makeJunctionManyRelation(
        EntityMetadataInterface $entityMetadata,
        string $junctionEntityClass,
        string $relatedEntityClass,
        array $orderBy = [],
    ): JunctionManyRelation {
        /** @var JunctionManyRelation $relation */
        $relation = $this->make(JunctionManyRelation::class, [
            'orderBy' => $orderBy,
            'entityMetadata' => $entityMetadata,
        ]);
        $relation->bind($junctionEntityClass, $relatedEntityClass);

        return $relation;
    }

    /**
     * Stub referenced keys used by {@see JunctionManyRelation::inferFromRelations()}.
     */
    /**
     * @param array<string, int|string> $orderBy
     */
    protected function makeHasManyToManyRelation(
        string $junctionEntityClass,
        EntityMetadataInterface $entityMetadata,
        string $selfEntityClass,
        string $relatedEntityClass = '',
        array $orderBy = [],
    ): HasManyToManyRelation {
        $foreignEntity = $relatedEntityClass !== '' ? $relatedEntityClass : null;

        /** @var HasManyToManyRelation $relation */
        $relation = $this->make(HasManyToManyRelation::class, [
            'junctionEntity' => $junctionEntityClass,
            'foreignEntity' => $foreignEntity,
            'orderBy' => $orderBy,
            'entityMetadata' => $entityMetadata,
        ]);
        $relation->bind($selfEntityClass, $relatedEntityClass);

        return $relation;
    }

    protected function stubJunctionBelongsToInference(EntityMetadataInterface $entityMetadata): void
    {
        if (!$entityMetadata instanceof \PHPUnit\Framework\MockObject\MockObject) {
            return;
        }

        $entityMetadata->method('getReferencedKey')->willReturnMap([
            [TestPermission::class, 'permission_id'],
            [TestRole::class, 'role_id'],
        ]);
    }
}
