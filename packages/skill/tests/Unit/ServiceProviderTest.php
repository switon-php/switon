<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Skill\ServiceProvider;
use Switon\Skill\SkillsInterface;
use Switon\Testing\TestCase;

#[CoversClass(ServiceProvider::class)]
class ServiceProviderTest extends TestCase
{
    public function testBootReloadsTheCatalog(): void
    {
        $skills = $this->createMock(SkillsInterface::class);
        $skills->expects($this->once())->method('reload');

        $provider = $this->make(ServiceProvider::class, [
            'skills' => $skills,
        ]);
        $provider->boot();
    }

    public function testRegisterDoesNotReload(): void
    {
        $skills = $this->createMock(SkillsInterface::class);
        $skills->expects($this->never())->method('reload');

        $provider = $this->make(ServiceProvider::class, [
            'skills' => $skills,
        ]);
        $provider->register($this->container);
    }
}
