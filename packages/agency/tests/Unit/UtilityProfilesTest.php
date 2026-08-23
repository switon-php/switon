<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Profiles;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\Tests\CatalogTestCase;

#[CoversClass(Profiles::class)]
class UtilityProfilesTest extends CatalogTestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->useProfilePaths(['@agency/agents']);
    }

    public function testTextProfileKeepsEvidenceBoundaries(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $prompt = $profiles->get('text')->prompt;

        $this->assertStringContainsString('Separate stated facts from inference', $prompt);
        $this->assertStringContainsString('do not imply that you inspected files', $prompt);
        $this->assertStringContainsString('If required evidence is absent', $prompt);
    }

    public function testTextProfileHandlesExplicitMermaidWithoutUnavailableDelegate(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $text = $profiles->get('text');

        // The mermaid guidance comes from the referenced segment; composition is charter territory.
        $this->assertContains('markdown-output', $text->segments);
        $this->assertStringNotContainsString('agent_diagram', $text->prompt);
    }

    public function testSharedShellAsToolIsFourToolWorkerInAgentsPackage(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $shell = $profiles->get('shell');

        $this->assertEqualsCanonicalizing(['read', 'write', 'edit', 'rm', 'bash'], $shell->tools);
        $this->assertSame('agent_shell', $shell->asTool);
        $this->assertFalse($shell->selectable);
        $this->assertSame(['parallel'], $shell->segments);
        $this->assertSame([], $shell->skills);
        $this->assertSame(100, $shell->maxSteps);
    }

    public function testInstructorUsesResponseOnlyForFinalResult(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $prompt = $profiles->get('instructor')->prompt;

        $this->assertSame([], $profiles->get('instructor')->tools);
        $this->assertStringContainsString('directly answers the assignment', $prompt);
        $this->assertStringContainsString('do not use that tool for progress updates', $prompt);
        $this->assertStringNotContainsString('`response`', $prompt);
    }

    public function testTimerReportsParsingAndSleepFailures(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $prompt = $profiles->get('timer')->prompt;

        $this->assertStringContainsString('duration cannot be parsed safely', $prompt);
        $this->assertStringContainsString('without implying a timer started or any other status changed', $prompt);
        $this->assertStringContainsString('If `sleep` fails or is interrupted', $prompt);
        $this->assertStringContainsString('do not imply that anything else changed', $prompt);
    }

    public function testUserFacingTextSpecialistsOwnRenderedOutputRules(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        foreach (['assist', 'web-researcher', 'file-researcher', 'text'] as $id) {
            // Output rules come from the referenced segments; composition is charter territory.
            $profile = $profiles->get($id);
            $this->assertContains('copyable', $profile->segments, $id);
            $this->assertContains('markdown-output', $profile->segments, $id);
        }
    }

}
