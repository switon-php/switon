<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\Envelope;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\PinnedPart;
use Switon\Skill\Skill;
use Switon\Skill\SkillLinkExtension;
use Switon\Skill\Skills;
use Switon\Testing\TestCase;

#[CoversClass(SkillLinkExtension::class)]
class SkillLinkExtensionTest extends TestCase
{
    public function testNonUserAndBlankAsksAreUntouched(): void
    {
        $extension = $this->extension();
        $system = new Envelope(SystemMessage::of('be nice'), 'main');
        $extension->inbound($this->createStub(AgentInterface::class), $system);
        $this->assertInstanceOf(SystemMessage::class, $system->letter);

        $blank = new Envelope(UserMessage::of(''), 'main');
        $extension->inbound($this->createStub(AgentInterface::class), $blank);
        $this->assertSame([], $blank->letter->parts);
    }

    public function testForeignTextAndOverspecifiedIdsDoNotPin(): void
    {
        $letter = $this->inbound('see https://example.com and skill://demo and [Too deep](skill://coding.sample.extra)');

        $this->assertCount(1, $letter->parts);
        $this->assertSame('see https://example.com and skill://demo and [Too deep](skill://coding.sample.extra)', $letter->text());
    }

    public function testLinksPinBlocksInOrder(): void
    {
        $letter = $this->inbound('[Sample](skill://demo) then [Missing](skill://no-such)');

        $this->assertSame('[Sample](skill://demo) then [Missing](skill://no-such)', $letter->text());
        $this->assertCount(3, $letter->parts);
        $this->assertInstanceOf(PinnedPart::class, $letter->parts[1]);
        $this->assertSame('skill:demo', $letter->parts[1]->id);
        $this->assertStringContainsString('Do the thing.', $letter->parts[1]->text);
        $this->assertInstanceOf(PinnedPart::class, $letter->parts[2]);
        $this->assertStringContainsString('Unknown skill id: no-such', $letter->parts[2]->text);
    }

    public function testDottedIdPinsTheBlock(): void
    {
        $letter = $this->inbound('Use [Sample](skill://coding.sample) for this.');

        $this->assertSame('Use [Sample](skill://coding.sample) for this.', $letter->text());
        $this->assertCount(2, $letter->parts);
        $this->assertInstanceOf(PinnedPart::class, $letter->parts[1]);
        $this->assertSame('skill:coding.sample', $letter->parts[1]->id);
        $this->assertStringContainsString('Do the desk sample.', $letter->parts[1]->text);
    }

    protected function inbound(string $text): UserMessage
    {
        $envelope = new Envelope(UserMessage::of($text), 'main');
        $this->extension()->inbound($this->createStub(AgentInterface::class), $envelope);

        $this->assertInstanceOf(UserMessage::class, $envelope->letter);

        return $envelope->letter;
    }

    protected function extension(): SkillLinkExtension
    {
        $skills = new Skills();
        $skills->set(Skill::of([
            'id' => 'demo',
            'description' => 'Demo',
            'instructions' => 'Do the thing.',
            'path' => '/skills/demo',
        ]));
        $skills->set(Skill::of([
            'id' => 'coding.sample',
            'description' => 'Desk sample',
            'instructions' => 'Do the desk sample.',
            'path' => '/tmp/coding-sample',
        ]));

        return $this->make(SkillLinkExtension::class, ['skills' => $skills]);
    }
}
