<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval;

use Switon\Core\Strings;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\Json;
use Switon\Core\Runtime;
use Switon\Core\PathAliasInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Message;
use Switon\Ai\Envelope;
use Switon\Ai\Message\UserMessage;
use Switon\Agency\Agent;
use Switon\Ai\Profile;
use Switon\Ai\Thread;

require_once dirname(__DIR__) . '/manual/live_bootstrap.php';

/** Live eval: multi-turn 1–100 guessing game. */
class GuessingGameEvalRunner
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    /**
     * @param array{id: string, max_runs: int} $scenario
     */
    public function run(array $scenario): ScenarioResult
    {
        $agent = $this->container->get(RecordingAgentManager::class)->mint([
            'profile' => Profile::decode([
                'id' => 'host',
                'prompt' => <<<'PROMPT'
You play a 1–100 guessing game. Each run reply with exactly one integer from 1 to 100, then briefly explain your reasoning.
PROMPT,
                'model' => 'main',
                'max_steps' => 15,
            ]),
        ]);
        liveTrackOpenAgent($agent);

        $threadId = $agent->threadId();
        $maxRuns = max(2, $scenario['max_runs']);
        $guesses = [];

        $userMessage = '我心里想了一个 1 到 100 的整数。请猜一个数，并说明你的推理。';
        $rejectMessage = '不对。请再猜一个 1-100 的整数，并说明你为什么选它。';

        try {
            $container = $this->container;
        $play = function () use (
                $agent,
                $container,
                $maxRuns,
                &$userMessage,
                $rejectMessage,
                &$guesses,
            ): void {
                for ($run = 1; $run <= $maxRuns; $run++) {
                    $agent->run(Envelope::of($userMessage, liveModel($container, $agent->profile()->model)));
                    $reply = $this->lastAssistantText($this->sessionMessages($agent->threadId()));
                    $guesses[] = $this->extractGuess($reply);

                    if ($run === $maxRuns) {
                        break;
                    }

                    $userMessage = $rejectMessage;
                }
            };

            if (\Swoole\Coroutine::getCid() > 0) {
                $play();
            } elseif (extension_loaded('swoole')) {
                $prevEnabled = Runtime::isCoroutineEnabled();
                Runtime::setCoroutineEnabled(true);
                try {
                    liveRunInCoroutine($play);
                } finally {
                    Runtime::setCoroutineEnabled($prevEnabled);
                }
            } else {
                $play();
            }
        } finally {
            liveKillAgentTree($agent);
        }

        $messages = $this->sessionMessages($threadId);
        $runtime = $this->container->get(PathAliasInterface::class)->get('@runtime');
        $sessionDir = "{$runtime}/threads/{$threadId}";
        $transcriptCount = count($this->loadTranscriptCompletions($threadId, "{$sessionDir}/transcript.jsonl"));

        $assistantReplies = 0;
        $userMessages = 0;
        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $userMessages++;
            }
            if ($message instanceof AssistantMessage && trim($message->content ?? '') !== '') {
                $assistantReplies++;
            }
        }

        $validGuesses = count(array_filter($guesses, static fn (?int $n): bool => $n !== null));

        $metrics = [
            'runs' => $maxRuns,
            'user_messages' => $userMessages,
            'assistant_replies' => $assistantReplies,
            'valid_guesses' => $validGuesses,
            'guesses' => $guesses,
            'transcript_completions' => $transcriptCount,
            'thread_id' => $threadId,
        ];

        $failures = [];
        if ($userMessages < $maxRuns) {
            $failures[] = "expected {$maxRuns} user messages, got {$userMessages}";
        }
        if ($assistantReplies < $maxRuns) {
            $failures[] = "expected {$maxRuns} assistant replies, got {$assistantReplies}";
        }
        if ($validGuesses < $maxRuns) {
            $failures[] = "expected a 1-100 guess each agent run, valid={$validGuesses}";
        }
        if ($transcriptCount < 1) {
            $failures[] = 'expected transcript completions archived';
        }

        $lastReply = $this->lastAssistantText($messages);

        return $failures === []
            ? ScenarioResult::pass($scenario['id'], $lastReply, $metrics)
            : ScenarioResult::fail($scenario['id'], $failures, $lastReply, $metrics);
    }

    /** @return list<Message> */
    protected function sessionMessages(string $threadId): array
    {
        return liveWireMessages(
            $this->loadTranscriptThread($threadId)->completions(),
        );
    }

    /** Load a thread leaf so callers can read `completions()` off the instance. */
    protected function loadTranscriptThread(string $threadId): Thread
    {
        $thread = Thread::of($threadId, '@threads/' . $threadId);
        $this->container->get(ThreadsInterface::class)->load($thread);

        return $thread;
    }

    /** @param list<Message> $messages */
    protected function lastAssistantText(array $messages): string
    {
        $reply = '';
        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                $text = trim($message->content ?? '');
                if ($text !== '') {
                    $reply = $text;
                }
            }
        }

        return $reply;
    }

    protected function extractGuess(string $reply): ?int
    {
        if ($reply === '') {
            return null;
        }

        $patterns = [
            '/\*\*(\d{1,3})\*\*/u',
            '/猜[：:\s]*(\d{1,3})/u',
            '/Guessed\s+(\d{1,3})/i',
            '/(?:^|\n)\s*(\d{1,3})\s*(?:[，。.\s]|$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $reply, $matches) !== 1) {
                continue;
            }

            $n = (int) $matches[1];
            if ($n >= 1 && $n <= 100) {
                return $n;
            }
        }

        return null;
    }

    /** @return list<Completion> */
    protected function loadTranscriptCompletions(string $threadId, string $transcriptPath): array
    {
        $completions = $this->loadTranscriptThread($threadId)->completions();
        if ($completions !== []) {
            return $completions;
        }

        if (!is_file($transcriptPath)) {
            return [];
        }

        $raw = file_get_contents($transcriptPath) ?: '';
        $lines = array_values(array_filter(
            Strings::rawLines($raw),
            static fn (string $line): bool => trim($line) !== '',
        ));
        if ($lines === []) {
            return [];
        }

        return array_map(
            Completion::decode(...),
            array_map(Json::parse(...), $lines),
        );
    }
}
