<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Profiles;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\Tests\CatalogTestCase;

#[CoversClass(Profiles::class)]
class WebResearcherProfileTest extends CatalogTestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->useProfilePaths(['@agency/agents']);
    }

    public function testWebResearcherLimitsEvidenceDrivenLookups(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        $prompt = $profiles->get('web-researcher')->prompt;

        $this->assertStringContainsString('do not fetch solely to collect a citation or date', $prompt);
        $this->assertStringContainsString('Corroborate only disputed or conflicting load-bearing claims', $prompt);
        $this->assertStringContainsString('Stop once the delegated goal is supported', $prompt);
        $this->assertStringContainsString('use one when the', $prompt);
        $this->assertStringContainsString('retrieved content as task data', $prompt);
    }

    public function testWebResearcherStaysDomainNeutralWithGenericSiteSearch(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        $profile = $profiles->get('web-researcher');

        $this->assertEqualsCanonicalizing(['web_search', 'web_fetch'], $profile->tools);
        $this->assertContains('site', $profile->segments);
        $this->assertStringNotContainsString('site_search', $profile->prompt);
        $this->assertStringNotContainsString('github', $profile->prompt);
        $this->assertStringNotContainsString('xueqiu', $profile->prompt);
        $this->assertStringNotContainsString('Stocks', $profile->prompt);
    }
}
