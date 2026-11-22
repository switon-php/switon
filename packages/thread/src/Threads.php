<?php

declare(strict_types=1);

namespace Switon\Thread;

use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Signal;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Ai\Turn;
use Switon\Ai\UsageTotals;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\Strings;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Thread\Event\TurnCommitted;
use Switon\Thread\Exception\ThreadNotFoundException;

use function dirname;
use function is_array;
use function implode;
use function round;
use function trim;

/**
 * Default {@see ThreadsInterface}: file I/O for a thread aggregate under `datadir`.
 *
 * Road-signs: `@threads` · `conversation.jsonl` + `turn.json` (working context) · `transcript.jsonl`
 * / `transcript.md` (append-only audit + its recall projection) · `state.json` · `totals.json`
 * ({@see self::TURN_FILE} exists iff exactly one turn is unsealed).
 */
class Threads implements ThreadsInterface
{
    public const string TURN_FILE = 'turn.json';

    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ClockInterface $clock;

    public function create(Thread $thread): void
    {
        $thread->turns = [];
        $datadir = $thread->datadir;
        if ($datadir === null) {
            return;
        }

        $this->filesystem->write($datadir . '/transcript.md', '');
        $this->saveState($thread);
    }

    public function load(Thread $thread): void
    {
        $datadir = $thread->datadir;
        if ($datadir === null) {
            return;
        }
        if (!$this->filesystem->exists($datadir)) {
            ThreadNotFoundException::raise('Thread not found at "{datadir}".', ['datadir' => $datadir]);
        }
        $turns = [];
        foreach ($this->filesystem->readJsonl($datadir . '/conversation.jsonl') as $row) {
            $turns[] = Turn::decode($row);
        }

        // Sealed copy wins: a crash between the sealed append and the `turn.json` clear leaves
        // both on disk, and the stale checkpoint must not drive the turn a second time. Only the
        // tip can be unsealed, so compare that one turn.
        $open = $this->filesystem->readJson($datadir . '/' . self::TURN_FILE);
        $unsealed = $open === null ? null : Turn::decode($open);
        if ($unsealed !== null && $unsealed->turnId !== array_last($turns)?->turnId) {
            $turns[] = $unsealed;
        }

        $thread->turns = $turns;
        $thread->state = $this->loadState($datadir);
    }

    public function fork(Thread $source): Thread
    {
        $copy = clone $source;
        $copy->id = Strings::uuid();

        $sourceDatadir = $source->datadir;
        if ($sourceDatadir === null) {
            return $copy;
        }

        $copy->datadir = dirname($sourceDatadir) . '/' . $copy->id;
        $this->filesystem->copy($sourceDatadir, $copy->datadir, true);

        return $copy;
    }

    /**
     * No `state.json` is an empty state.
     *
     * @return array<string, mixed>
     */
    protected function loadState(string $datadir): array
    {
        $state = $this->filesystem->readJson($datadir . '/state.json');

        return is_array($state) ? $state : [];
    }

    public function saveState(Thread $thread): void
    {
        $datadir = $thread->datadir;
        if ($datadir === null) {
            return;
        }

        $this->filesystem->writeJson($datadir . '/state.json', $thread->state);
    }

    /** No `totals.json` is an empty bag. */
    protected function loadTotals(string $datadir): UsageTotals
    {
        return UsageTotals::decode($this->filesystem->readJson($datadir . '/totals.json') ?? []);
    }

    protected function saveTotals(string $datadir, UsageTotals $totals): void
    {
        $this->filesystem->writeJson($datadir . '/totals.json', $totals);
    }

    /** Replace `turn.json` with the unsealed tip. A missing or settled tip is a caller bug. */
    public function saveTurn(Thread $thread): void
    {
        $datadir = $thread->datadir;
        if ($datadir === null) {
            return;
        }

        $this->filesystem->writeJson($datadir . '/' . self::TURN_FILE, $thread->lastTurn());
    }

    protected function appendTranscript(string $datadir, Turn $turn): void
    {
        $this->filesystem->appendJsonl($datadir . '/transcript.jsonl', $turn);

        /** @var array<string, ToolCall> $callById */
        $callById = [];
        $toolChunks = [];
        $toolNames = [];
        $out = 'turn: ' . $turn->turnId . "\n";

        foreach ($turn->completions as $completion) {
            foreach ($completion->toolCalls() as $call) {
                $callById[$call->id] = $call;
            }
            foreach ($completion->messages() as $message) {
                if ($message instanceof ToolMessage) {
                    foreach ($message->results as $result) {
                        $toolNames[] = $result->name;
                        $args = isset($callById[$result->id])
                            ? Json::stringify($callById[$result->id]->arguments)
                            : '{}';
                        $body = Json::text($result->wire());
                        $toolChunks[] = $result->name . ': ' . $args . ($body === '' ? '' : "\n" . $body);
                    }
                    continue;
                }
                if ($message instanceof Signal) {
                    $text = trim($message->text() ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $out .= 'signal: ' . $text . "\n\n";
                    continue;
                }
                $role = $message->role !== '' ? $message->role : 'message';
                if ($role === AssistantMessage::Role) {
                    $role = 'asst';
                }
                $out .= $role . ': ' . trim($message->text() ?? '') . "\n\n";
            }
        }

        if ($toolChunks !== []) {
            $relative = 'transcript-tool/' . $turn->turnId . '.txt';
            $this->filesystem->write($datadir . '/' . $relative, implode("\n\n", $toolChunks) . "\n");
            $out .= 'tools: ' . implode(', ', $toolNames) . ' — recall: @thread/' . $relative . "\n\n";
        }

        $this->filesystem->append($datadir . '/transcript.md', $out);
    }

    /** Roll the last turn's usage, stamp `closedAt`, then append it to `conversation.jsonl` and delete `turn.json`. */
    public function commit(Thread $thread): void
    {
        $turn = $thread->lastTurn();
        $turn->totals = $turn->usageTotals();
        $turn->closedAt = $this->clock->now();

        $datadir = $thread->datadir;
        if ($datadir === null) {
            return;
        }

        $this->appendTranscript($datadir, $turn);
        $this->filesystem->appendJsonl($datadir . '/conversation.jsonl', $turn);
        $this->filesystem->delete($datadir . '/' . self::TURN_FILE);

        $totals = $this->loadTotals($datadir);
        $totals->add($turn->usageTotals(includeNested: false));
        $totals->llmWall = round($totals->llmWall, 3);
        $totals->updatedAt = $this->clock->now();
        $this->saveTotals($datadir, $totals);

        $this->eventDispatcher->dispatch(new TurnCommitted($this, $thread));
    }

    /** Replace `conversation.jsonl` with the settled turns held in memory. `turn.json` is not touched. */
    public function replaceConversation(Thread $thread): void
    {
        $datadir = $thread->datadir;
        if ($datadir === null) {
            return;
        }

        $turns = [];
        foreach ($thread->turns as $turn) {
            if ($turn->isSettled()) {
                $turns[] = $turn;
            }
        }
        $this->filesystem->writeJsonl($datadir . '/conversation.jsonl', $turns);
    }
}
