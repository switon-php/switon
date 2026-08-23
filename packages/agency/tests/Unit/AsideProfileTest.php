<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Profiles;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\Tests\CatalogTestCase;

#[CoversClass(Profiles::class)]
class AsideProfileTest extends CatalogTestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->useProfilePaths(['@agency/agents']);
    }

    public function testBuiltinAsideIsNonSelectableChatOnlyProfile(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        $this->assertTrue($profiles->has('aside'));

        $aside = $profiles->get('aside');
        $this->assertFalse($aside->selectable);
        $this->assertSame('', $aside->asTool);
        $this->assertSame([], $aside->tools);
        $this->assertStringContainsString('turn-anchored clarification', $aside->prompt);
        $this->assertStringContainsString('chat-only clarification', $aside->prompt);
        $this->assertStringContainsString('**session**', $aside->prompt);
        $this->assertStringContainsString('**fragment**', $aside->prompt);
    }
}
