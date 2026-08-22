<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Fixtures;

use RuntimeException;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Envelope;
use Switon\Ai\Profile;
use Switon\Ai\StreamDelta;
use Switon\Ai\Thread;
use Switon\Ai\Turn;

/**
 * Minimal agent for tool tests: thread datadir + workspace.
 *
 * Guidance: only the faces tools read are real; the delegation/lifecycle half raises so a test
 * that accidentally drives an agent fails loudly instead of silently doing nothing.
 */
class StubAgent implements AgentInterface
{
    public function __construct(
        protected Thread $thread,
        protected ?string $workspace = null,
    ) {
    }

    /** Fresh thread dir (with `tool-result/`) plus any workspace override. */
    public static function in(?string $datadir = null, ?string $workspace = null): self
    {
        $dir = $datadir ?? sys_get_temp_dir() . '/switon-tool-host-' . bin2hex(random_bytes(4));
        if ($dir !== '' && !is_dir($dir . '/tool-result')) {
            mkdir($dir . '/tool-result', 0777, true);
        }

        return new self(Thread::of('test-thread', $dir), $workspace ?? sys_get_temp_dir());
    }

    public function threadId(): string
    {
        return $this->thread->id;
    }


    public function depth(): int
    {
        return 0;
    }

    public function parent(): ?AgentInterface
    {
        return null;
    }

    public function root(): AgentInterface
    {
        return $this;
    }

    public function workspace(): string
    {
        return $this->workspace ?? '';
    }

    public function thread(): Thread
    {
        return $this->thread;
    }

    public function profile(): Profile
    {
        return new Profile();
    }

    public function charter(): Charter
    {
        return Charter::of();
    }

    public function switchProfile(Profile $profile): void
    {
    }

    public function spawn(Profile $profile, ?string $leaf = null): static
    {
        throw new RuntimeException('StubAgent cannot spawn.');
    }

    public function start(): void
    {
    }

    public function shutdown(): void
    {
    }


    public function isIdle(): bool
    {
        return true;
    }

    public function deliver(Envelope $envelope): void
    {
        throw new RuntimeException('StubAgent has no inbox.');
    }

    public function run(Envelope $envelope): ?Turn
    {
        throw new RuntimeException('StubAgent has no inbox.');
    }

    public function cancel(): void
    {
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function onDelta(StreamDelta $delta): void
    {
    }
}
