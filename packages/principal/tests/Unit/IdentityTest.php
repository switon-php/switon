<?php

declare(strict_types=1);

namespace Switon\Principal\Tests\Unit;

use Switon\Principal\Exception\NotAuthenticatedException;
use Switon\Principal\Identity;
use Switon\Principal\IdentityInterface;
use Switon\Principal\Tests\TestCase;

class IdentityTest extends TestCase
{
    public function testIdentityStartsAsGuest(): void
    {
        $identity = $this->container->make(Identity::class);

        $this->assertTrue($identity->isGuest());
        $this->assertSame([], $identity->getRoles());
    }

    public function testIdentitySetPreservesProvidedNameAndNormalizesRoles(): void
    {
        $identity = $this->container->make(Identity::class);

        $identity->set(7, ' mark ', ['admin', ' editor ', '', 'reviewer']);

        $this->assertFalse($identity->isGuest());
        $this->assertSame(7, $identity->getId());
        $this->assertSame(' mark ', $identity->getName());
        $this->assertSame(['admin', 'editor', 'reviewer'], $identity->getRoles());
    }

    public function testIdentityClearReturnsToGuestState(): void
    {
        $identity = $this->container->get(IdentityInterface::class);
        $identity->set(7, 'mark', ['admin']);
        $identity->clear();

        $this->assertTrue($identity->isGuest());
        $this->assertSame([], $identity->getRoles());
    }

    public function testIdentityClearMakesNameUnavailableAgain(): void
    {
        $identity = $this->container->make(Identity::class);
        $identity->set(7, 'mark', ['admin']);
        $identity->clear();

        $this->expectException(NotAuthenticatedException::class);
        $identity->getName();
    }

    public function testIdentityThrowsWhenGuestFieldsAreAccessed(): void
    {
        $identity = $this->container->make(Identity::class);

        $this->expectException(NotAuthenticatedException::class);
        $identity->getId();
    }

    public function testIdentityAllowsEmptyStringName(): void
    {
        $identity = $this->container->make(Identity::class);

        $identity->set(7, '');

        $this->assertSame('', $identity->getName());
    }

    public function testIdentityAllowsWhitespaceOnlyName(): void
    {
        $identity = $this->container->make(Identity::class);

        $identity->set(7, '   ');

        $this->assertSame('   ', $identity->getName());
    }

    public function testIdentityGetNameFailsForGuest(): void
    {
        $identity = $this->container->make(Identity::class);

        $this->expectException(NotAuthenticatedException::class);
        $this->expectExceptionMessage('user name');
        $identity->getName();
    }

    public function testIdentityAllowsEmptyStringId(): void
    {
        $identity = $this->container->make(Identity::class);

        $identity->set('', 'alice');

        $this->assertSame('', $identity->getId());
        $this->assertSame('alice', $identity->getName());
    }

    public function testIdentitySetKeepsDuplicateRolesAfterNormalization(): void
    {
        $identity = $this->container->make(Identity::class);

        $identity->set(1, 'bob', ['admin', ' admin ', 'editor', 'editor']);

        $this->assertSame(['admin', 'admin', 'editor', 'editor'], $identity->getRoles());
    }

    public function testIdentityInterfaceAndConcreteCanHoldIsolatedStates(): void
    {
        $fromInterface = $this->container->get(IdentityInterface::class);
        $fromConcrete = $this->container->make(Identity::class);

        $fromInterface->set(9, 'alice', ['admin']);

        $this->assertTrue($fromConcrete->isGuest());

        $fromConcrete->set(10, 'bob', ['editor']);

        $this->assertSame(9, $fromInterface->getId());
        $this->assertSame(['admin'], $fromInterface->getRoles());
        $this->assertSame(10, $fromConcrete->getId());
        $this->assertSame(['editor'], $fromConcrete->getRoles());
    }

    public function testIdentityAcceptsStringZeroIdAsAuthenticatedUser(): void
    {
        $identity = $this->container->make(Identity::class);
        $identity->set('0', 'zero-user', ['member']);

        $this->assertFalse($identity->isGuest());
        $this->assertSame('0', $identity->getId());
        $this->assertSame('zero-user', $identity->getName());
    }
}
