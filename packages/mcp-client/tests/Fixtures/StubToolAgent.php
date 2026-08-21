<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Fixtures;

use RuntimeException;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Envelope;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Profile;
use Switon\Ai\StreamDelta;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;

/**
 * Agent for direct tool dispatch in tests: thread datadir, workspace, open turn.
 *
 * Guidance: PHPUnit's `createStub(AgentInterface::class)` returns an empty {@see Thread}
 * (no datadir, no turns), which makes every spill and dispatch-turn lookup fail. This fixture
 * carries the three faces tools actually read so tests exercise the real code path instead of
 * the "no host" branch.
 */
class StubToolAgent implements AgentInterface
{
    protected Charter $charter;

    public function __construct(
        protected Thread $thread,
        protected ?string $workspace = null,
    ) {
        $this->charter = Charter::of();

        if ($thread->turns === []) {
            $step = Completion::of(UserMessage::of(''));
            $step->step = 1;
            $thread->turns[] = Turn::of('turn-test', [$step], TurnStatus::Running, 'main');
        }
    }

    /** Fresh thread dir (with `tool-result/`) plus one open turn. */
    public static function in(?string $datadir = null, ?string $workspace = null): self
    {
        $dir = $datadir ?? sys_get_temp_dir() . '/switon-agency-tool-test-' . bin2hex(random_bytes(4));
        if (!is_dir($dir . '/tool-result')) {
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
        return new Profile(id: 'host');
    }

    public function charter(): Charter
    {
        return $this->charter;
    }

    public function switchProfile(Profile $profile): void
    {
    }

    public function spawn(Profile $profile, ?string $leaf = null): static
    {
        throw new RuntimeException('StubToolAgent cannot spawn.');
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
        throw new RuntimeException('StubToolAgent has no inbox.');
    }

    public function run(Envelope $envelope): ?Turn
    {
        throw new RuntimeException('StubToolAgent has no inbox.');
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
