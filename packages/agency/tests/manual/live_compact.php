<?php

declare(strict_types=1);

/**
 * Live: fold older rungs, keep the newest one whole.
 *
 *   php packages/agency/tests/manual/live_compact.php
 */

use Psr\Container\ContainerInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Cli\Runner;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Envelope;
use Switon\Ai\Profile;
use Switon\Ai\StreamDelta;
use Switon\Thread\ExcerptExtension;
use Switon\Thread\ExcerptMessage;

require __DIR__ . '/live_bootstrap.php';

$ok = Runner::run(static function (ContainerInterface $container): bool {
    /** @var ExcerptExtension $excerpts */
    $excerpts = $container->get(ExcerptExtension::class);

    $threadId = 'live-compact-' . bin2hex(random_bytes(4));
    $long = str_repeat('detail ', 40);
    $thread = new Thread($threadId, [
        Turn::of('t0', [
            Completion::of(UserMessage::of('First topic: inventory. ' . $long), new AssistantMessage('Warehouse A had 120 units.')),
        ], TurnStatus::Completed),
        Turn::of('t1', [
            Completion::of(UserMessage::of('Second topic: shipping. ' . $long), new AssistantMessage('Shipment #441 delayed.')),
        ], TurnStatus::Completed),
        Turn::of('t2', [
            Completion::of(UserMessage::of('Third topic: open task.'), new AssistantMessage('Reconcile before Friday.')),
        ], TurnStatus::Completed),
        Turn::of('t3', [
            Completion::of(UserMessage::of('Recent: status?'), new AssistantMessage('Ready.')),
        ], TurnStatus::Completed),
    ]);

    echo "=== live fold ===\n";
    echo "session: {$threadId}\n";

    $before = $thread->turns[0]->completions[0]->input->wire()[0]['text'];
    echo 'before t0_len=' . strlen($before) . "\n";

    $did = $excerpts->force(new LiveCompactAgent($thread));
    $head = $thread->turns[0]->completions[0]->input;
    $last = $thread->turns[array_key_last($thread->turns)];
    $after = $head instanceof ExcerptMessage ? ($head->wire()[0]['text'] ?? '') : '';
    echo 'after  head_len=' . strlen($after) . ' did=' . ($did ? 'yes' : 'no') . "\n";

    $ok = expect(true, $did, 'fold rewrote');
    $ok = expect($ok, $head instanceof ExcerptMessage, 'head is excerpts');
    $ok = expect($ok, strlen($after) < strlen($before) || str_contains($after, '…'), 'oldest turn excerpted');
    $ok = expect($ok, $last->completions[0]->input->wire()[0]['text'] === 'Recent: status?', 'newest intact');

    return $ok;
});

echo 'RESULT: ' . ($ok ? 'PASS' : 'FAIL') . "\n";
exit($ok ? 0 : 1);

function expect(bool $ok, bool $pass, string $label): bool
{
    echo ($pass ? 'PASS' : 'FAIL') . " {$label}\n";

    return $ok && $pass;
}

/** In-memory host so {@see ExcerptExtension::force()} can fold without a running inbox. */
class LiveCompactAgent implements AgentInterface
{
    public function __construct(private Thread $thread)
    {
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
        return '';
    }

    public function thread(): Thread
    {
        return $this->thread;
    }

    public function profile(): Profile
    {
        return new Profile('live-compact');
    }

    public function charter(): Charter
    {
        return Charter::of();
    }

    public function spawn(Profile $profile, ?string $leaf = null): static
    {
        return $this;
    }

    public function switchProfile(Profile $profile): void
    {
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
    }

    public function run(Envelope $envelope): ?Turn
    {
        return null;
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
