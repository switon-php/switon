<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Profiles;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\Tests\CatalogTestCase;

#[CoversClass(Profiles::class)]
class DiagramProfileTest extends CatalogTestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->useProfilePaths(['@agency/agents']);
    }

    public function testDiagramAlwaysReturnsExactlyOneFencedBlock(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        $diagram = $profiles->get('diagram');
        $prompt = $diagram->prompt;

        $this->assertNotContains('response', $diagram->tools);
        $this->assertContains('read', $diagram->tools);
        $this->assertContains('edit', $diagram->tools);
        $this->assertNotContains('write', $diagram->tools);
        $this->assertStringContainsString('exactly one ```mermaid fenced block and nothing else', $prompt);
        $this->assertStringContainsString('`read` that file, then `edit` only the diagram', $prompt);
        $this->assertStringContainsString('No `<->` message', $prompt);
        $this->assertStringContainsString('Flowchart bidirectional edges are `<-->`', $prompt);
        $this->assertStringContainsString('Treat supplied entities, directions, conditions, relationships, and explicitly excluded relationships as a contract', $prompt);
        $this->assertStringContainsString('do not invent services, events, storage roles, actions, or failure behavior', $prompt);
        $this->assertStringContainsString('check the diagram against that ledger one-for-one', $prompt);
        $this->assertStringContainsString('Do not draw an excluded edge', $prompt);
        $this->assertStringContainsString('Emit each relationship once', $prompt);
        $this->assertStringContainsString('no comments, styling, icons, emoji, `rect` backgrounds, or decorative nodes', $prompt);
        $this->assertStringContainsString('drawn despite an explicit exclusion', $prompt);
        $this->assertStringContainsString('a fence-only assignment still ends as exactly one fenced block', $prompt);
        $this->assertStringContainsString('keep the single best self-contained subset', $prompt);
        $this->assertStringContainsString('syntax-discipline self-check, not renderer execution', $prompt);
        $this->assertStringContainsString('unless an available tool actually verified it', $prompt);
        $this->assertStringNotContainsString('one well-formed diagram', $diagram->description);
        $this->assertStringNotContainsString('append one plain-text line', $prompt);
        $this->assertStringNotContainsString('suggest splitting', $prompt);
    }
}
