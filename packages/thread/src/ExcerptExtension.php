<?php

declare(strict_types=1);

namespace Switon\Thread;

use Switon\Ai\AbstractExtension;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Signal;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Strings;
use Switon\Llm\LlmProvidersInterface;

use function array_push;
use function array_slice;
use function count;
use function intdiv;
use function trim;

/**
 * Shortens the stored working context when the model window fills.
 *
 * Guidance: registered through `extra.switon.agent.extensions`; a fold rewrites the stored conversation and leaves the transcript.
 */
class ExcerptExtension extends AbstractExtension
{
    #[Autowired] protected ThreadsInterface $threads;
    #[Autowired] protected LlmProvidersInterface $llmProviders;

    /** Prompt share of the model window that folds older turns (0–1). */
    #[Autowired] protected float $line = 0.8;
    /** Excerpt line length in code points, also the token estimate for one catalog line. */
    #[Autowired] protected int $chars = 64;
    /** Newest turns already on the thread left whole. Older turns fold into the head. */
    #[Autowired] protected int $keep = 1;
    /** Fraction of the model window the catalog may occupy (0–1). */
    #[Autowired] protected float $catalog = 1 / 3;

    public function beforeTurn(AgentInterface $agent, Turn $turn): void
    {
        $thread = $agent->thread();
        if ($thread->turns === [] || !$thread->lastTurn()->isSettled()) {
            return;
        }
        $usage = $thread->lastCompletion()->usage;
        $window = $this->window($turn->model);
        if ($usage === null || $window < 1 || $usage->input < $window * $this->line) {
            return;
        }
        $this->fold($agent);
    }

    public function beforeModel(AgentInterface $agent, Charter $charter): void
    {
        $thread = $agent->thread();
        $usage = $thread->lastCompletion()->usage;
        $window = $this->window($thread->lastTurn()->model);
        if ($usage === null || $window < 1 || $usage->input < $window * 0.95) {
            return;
        }
        if ($this->fold($agent) || $this->dropCatalog($agent, $window)) {
            return;
        }
        $changed = false;
        foreach ($thread->turns as $i => $turn) {
            if (!$turn->isSettled() || count($turn->completions) < 2) {
                continue;
            }
            $input = $turn->completions[0]->input;
            $output = $turn->completions[count($turn->completions) - 1]->output;
            if (!$input instanceof UserMessage || !$output instanceof AssistantMessage) {
                continue;
            }
            $thread->turns[$i] = Turn::of(
                $turn->turnId,
                [Completion::of($input, $output)],
                $turn->status,
                $turn->model,
            );
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        $thread->lastCompletion()->usage = null;
        $this->threads->replaceConversation($thread);
        if (!$thread->lastTurn()->isSettled()) {
            $this->threads->saveTurn($thread);
        }
        $agent->charter()->invalidate();
    }

    /**
     * Same fold as {@see self::beforeTurn()} once the last turn has settled, without the usage check.
     *
     * @return bool `true` when the working context changed
     */
    public function force(AgentInterface $agent): bool
    {
        $thread = $agent->thread();
        if ($thread->turns === [] || !$thread->lastTurn()->isSettled()) {
            return false;
        }

        return $this->fold($agent);
    }

    /**
     * Fold turns before the kept tail into one {@see ExcerptMessage}. Each folded turn contributes
     * the readable text of its first input and last output, cut to {@see $chars}. {@see PinnedPart}
     * bodies from the previous head and the folded turns are carried onto the new head: the same
     * id is removed and appended. Persists and invalidates the charter when the thread changes.
     *
     * {@see $keep} is how many newest turns stay whole. An open tip stays as well, so it is
     * not one of those turns. A leading fold that is not an {@see ExcerptMessage} is left untouched.
     */
    protected function fold(AgentInterface $agent): bool
    {
        $thread = $agent->thread();
        if ($thread->turns === []) {
            return false;
        }

        $turns = $thread->turns;
        $items = [];
        $pinned = [];
        $dropped = false;
        $input = $turns[0]->completions[0]->input ?? null;
        if ($input instanceof ExcerptMessage) {
            $items = $input->items;
            $dropped = $input->dropped;
            foreach ($input->parts as $part) {
                if ($part instanceof PinnedPart) {
                    unset($pinned[$part->id]);
                    $pinned[$part->id] = $part;
                }
            }
            array_shift($turns);
        } elseif ($input instanceof FoldedMessage) {
            return false;
        }

        $keep = $this->keep;
        if (!$thread->lastTurn()->isSettled()) {
            $keep++;
        }
        if (count($turns) <= $keep) {
            return false;
        }

        $foldUntil = count($turns) - $keep;
        for ($i = 0; $i < $foldUntil; $i++) {
            $completions = $turns[$i]->completions;
            if ($completions === []) {
                continue;
            }
            foreach ($completions as $completion) {
                $message = $completion->input;
                $parts = [];
                if ($message instanceof UserMessage) {
                    $parts = $message->parts;
                } elseif ($message instanceof ToolMessage) {
                    foreach ($message->results as $result) {
                        array_push($parts, ...$result->parts);
                    }
                }
                foreach ($parts as $part) {
                    if ($part instanceof PinnedPart) {
                        unset($pinned[$part->id]);
                        $pinned[$part->id] = $part;
                    }
                }
            }
            foreach ([$completions[0]->input, $completions[count($completions) - 1]->output] as $message) {
                if ($message === null) {
                    continue;
                }
                $text = Strings::cut(trim($message->text() ?? ''), $this->chars, '…');
                if ($text !== '') {
                    $items[] = [
                        'role' => $message instanceof Signal
                            ? 'signal'
                            : ($message->role === AssistantMessage::Role ? 'asst' : 'user'),
                        'text' => $text,
                    ];
                }
            }
        }

        $thread->turns = [
            Turn::of(
                Strings::uuid(),
                completions: [Completion::of(new ExcerptMessage($items, $dropped, array_values($pinned)))],
                status: TurnStatus::Completed,
            ),
            ...array_slice($turns, $foldUntil),
        ];
        $thread->lastCompletion()->usage = null;
        $this->threads->replaceConversation($thread);
        if (!$thread->lastTurn()->isSettled()) {
            $this->threads->saveTurn($thread);
        }
        $agent->charter()->invalidate();

        return true;
    }

    /** Context window for a delivery name. An empty name resolves as `main`. */
    protected function window(string $name): int
    {
        return $this->llmProviders->get($name)->window();
    }

    /**
     * Drop the oldest third of the catalog once it fills {@see $catalog} of the window.
     *
     * Called from {@see self::beforeModel()} after {@see self::fold()} changes nothing, so the
     * catalog keeps growing through the ordinary fold line and is cut only at 0.95.
     */
    protected function dropCatalog(AgentInterface $agent, int $window): bool
    {
        $thread = $agent->thread();
        $turn = $thread->turns[0] ?? null;
        $head = $turn?->completions[0]->input ?? null;
        $limit = (int) ($window * $this->catalog / $this->chars);
        if (!$turn instanceof Turn || !$head instanceof ExcerptMessage || count($head->items) < $limit) {
            return false;
        }

        $thread->turns[0] = Turn::of(
            $turn->turnId,
            [Completion::of(new ExcerptMessage(array_slice($head->items, intdiv(count($head->items), 3)), true, $head->parts))],
            $turn->status,
            $turn->model,
            $turn->effort,
        );
        $thread->lastCompletion()->usage = null;
        $this->threads->replaceConversation($thread);
        if (!$thread->lastTurn()->isSettled()) {
            $this->threads->saveTurn($thread);
        }
        $agent->charter()->invalidate();

        return true;
    }
}
