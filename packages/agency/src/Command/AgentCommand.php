<?php

declare(strict_types=1);

namespace Switon\Agency\Command;

use ReflectionClass;
use RuntimeException;
use Switon\Agency\Agent;
use Switon\Agency\ChartersInterface;
use Switon\Agency\Observability\AgentConsoleInterface;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\InstructorInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Envelope;
use Switon\Ai\Profile;
use Switon\Ai\Thread;
use Switon\Console\Colors;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\Exception\JsonException;
use Switon\Core\Json;
use Switon\Core\Strings;
use Switon\Thread\Exception\ThreadNotFoundException;
use Switon\Thread\ThreadsInterface;
use Swoole\Coroutine;
use Swoole\Process;
use Throwable;
use function array_is_list;
use function class_exists;
use function getcwd;
use function is_array;
use function is_string;
use function is_a;
use function trim;
use function basename;

/** Plain console REPL, one-shot reply, and structured instruct for agent sessions. */
class AgentCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected AgentConsoleInterface $agentConsole;

    /** Set by the SIGINT handler; the REPL loop turns it into {@see AgentInterface::cancel()}. */
    protected bool $interrupted = false;
    #[Autowired] protected ThreadsInterface $threads;
    #[Autowired] protected ProfilesInterface $profiles;
    #[Autowired] protected ChartersInterface $charters;

    /**
     * @param string $agent Catalog profile id (e.g. `agent`, `plan`, `coding.explore`) or AgentInterface class name
     * @param string $threadId Existing id to resume; empty or `new` creates a session
     * @param bool $showPrompt Print the full system prompt
     * @param string $message Optional first user message before the REPL starts
     * @param string $tools Comma-separated tool allowlist; empty keeps the profile's own grant
     * @param bool $rawTools Print tool-call arguments as raw JSON
     */
    public function chatAction(
        string $agent = 'assist',
        string $threadId = '',
        bool   $showPrompt = false,
        string $message = '',
        string $tools = '',
        bool   $rawTools = false,
    ): int
    {
        $resolved = $this->resolveProfile($agent);
        if ($resolved === null) {
            $this->console->writeLn($this->unknownAgentMessage($agent));

            return ConsoleInterface::FAILURE;
        }
        [$agentClass, $params] = $resolved;

        try {
            return $this->inCoroutine(fn(): int => $this->runChatSession(
                $agentClass,
                $params,
                trim($threadId),
                $showPrompt,
                trim($message),
                preg_split('/[\s,]+/', $tools, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                $rawTools,
            ));
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }
    }

    /**
     * One turn: stdout carries only the final reply; `thread → id` and errors go to stderr (non-zero exit on error).
     *
     * @param string $agent Catalog profile id (e.g. `agent`, `coding.review`) or AgentInterface class name;
     *                      ignored when resuming
     * @param string $message User message (required)
     * @param string $threadId Existing id to continue; empty or `new` starts a new thread
     * @param string $tools    Comma-separated tool allowlist; empty keeps the profile's own grant
     */
    public function runAction(
        string $agent = 'assist',
        string $message = '',
        string $threadId = '',
        string $tools = '',
    ): int {
        $message = trim($message);
        if ($message === '') {
            $this->writeErrLn('--message is required.');

            return ConsoleInterface::FAILURE;
        }

        $resolved = $this->resolveProfile($agent);
        if ($resolved === null) {
            $this->writeErrLn($this->unknownAgentMessage($agent));

            return ConsoleInterface::FAILURE;
        }
        [$agentClass, $params] = $resolved;
        $toolOverride = preg_split('/[\s,]+/', $tools, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $threadId = trim($threadId);
        $openedId = '';
        try {
            $reply = $this->inCoroutine(function () use ($agentClass, $params, $message, $threadId, $toolOverride, &$openedId): string {
                $agent = $threadId === '' || $threadId === 'new'
                    ? $this->openNew((string)($params['profileId'] ?? ''), $agentClass, $toolOverride)
                    : $this->openSaved('@threads/' . $threadId);
                $openedId = $agent->thread()->id;
                try {
                    $turn = $agent->run(Envelope::of($message, $agent->profile()->model));
                } finally {
                    $agent->shutdown();
                }

                // `run()` returns null when the delivery left no turn (for example a discarded turn).
                $output = $turn === null ? null : $turn->lastCompletion()->output;
                if (!$output instanceof AssistantMessage) {
                    $wire = $output?->wire() ?? [];
                    throw new RuntimeException(trim(
                        ($wire[0]['text'] ?? null) ?? 'Run ended without a reply.',
                    ));
                }

                return trim($output->text() ?? '');
            });
        } catch (Throwable $e) {
            $this->writeErrLn($e->getMessage());
            $this->writeRunThreadHint($openedId);

            return ConsoleInterface::FAILURE;
        }

        $this->console->writeLn($reply);
        $this->writeRunThreadHint($openedId);

        return ConsoleInterface::SUCCESS;
    }

    protected function writeRunThreadHint(string $threadId): void
    {
        if ($threadId !== '') {
            $this->writeErrLn('thread → ' . $threadId);
        }
    }

    /** Stderr for `agent:run` and `agent:instruct`, keeping stdout limited to the result. */
    protected function writeErrLn(string $line): void
    {
        fwrite(STDERR, $line . "\n");
    }

    /**
     * One structured turn: stdout is the unwrapped `data` JSON; errors go to stderr.
     *
     * @param string $agent Catalog profile id (e.g. `agent`, `coding.review`) or AgentInterface class name
     * @param string $message User message (required)
     * @param string $schema JSON object of business fields under response `data`; empty means no fields
     * @param string $tools Comma-separated tool allowlist; empty keeps the profile's own grant
     */
    public function instructAction(
        string $agent = 'assist',
        string $message = '',
        string $schema = '',
        string $tools = '',
    ): int {
        $message = trim($message);
        if ($message === '') {
            $this->writeErrLn('--message is required.');

            return ConsoleInterface::FAILURE;
        }

        try {
            $properties = $this->instructProperties($schema);
        } catch (RuntimeException $e) {
            $this->writeErrLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        $resolved = $this->resolveProfile($agent);
        if ($resolved === null) {
            $this->writeErrLn($this->unknownAgentMessage($agent));

            return ConsoleInterface::FAILURE;
        }
        [, $params] = $resolved;
        $toolOverride = preg_split('/[\s,]+/', $tools, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        try {
            $data = $this->inCoroutine(function () use ($params, $message, $properties, $toolOverride): array {
                $profileId = (string)($params['profileId'] ?? '');
                $profile = $this->instructProfile($profileId !== '' ? $profileId : 'host', $toolOverride);

                return $this->container->make(InstructorInterface::class)->ask($profile, $message, $properties);
            });
        } catch (Throwable $e) {
            $this->writeErrLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        $this->console->writeLn(Json::stringify($data, JSON_PRETTY_PRINT));

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Business fields for {@see InstructorInterface::ask()}.
     *
     * `{}` and an omitted schema are both an empty field set. A JSON array is rejected.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function instructProperties(string $schema): array
    {
        $schema = trim($schema);
        if ($schema === '') {
            return [];
        }

        try {
            $decoded = Json::parse($schema);
        } catch (JsonException) {
            throw new RuntimeException('--schema must be a JSON object of field definitions.');
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('--schema must be a JSON object of field definitions.');
        }

        foreach ($decoded as $name => $property) {
            if (!is_string($name) || !is_array($property) || array_is_list($property)) {
                throw new RuntimeException('--schema must be a JSON object of field definitions.');
            }
        }

        /** @var array<string, array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * Catalog profile for one instruct call.
     *
     * Cloned so a tool override and the temporary terminal tool do not rewrite the shared catalog row.
     *
     * @param list<string> $tools
     */
    protected function instructProfile(string $profileId, array $tools): Profile
    {
        $profile = clone $this->profiles->get($profileId);
        if ($tools !== []) {
            $profile->tools = $tools;
        }

        return $profile;
    }

    /**
     * Run $body inside a coroutine (reuses the current one when present); rethrows its error.
     *
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    protected function inCoroutine(callable $body): mixed
    {
        if (Coroutine::getCid() > 0) {
            return $body();
        }

        $result = null;
        $error = null;
        $runner = static function () use ($body, &$result, &$error): void {
            try {
                $result = $body();
            } catch (Throwable $e) {
                $error = $e;
            }
        };

        \Swoole\Coroutine\run($runner);
        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    /**
     * @param class-string<Agent> $agentClass
     * @param list<string>        $tools     Override the profile tool grant; empty keeps the profile's own
     */
    protected function openNew(string $profileId, string $agentClass, array $tools = []): Agent
    {
        $profile = $this->profiles->get($profileId !== '' ? $profileId : 'host');
        if ($tools !== []) {
            $profile->tools = $tools;
        }
        $threadId = Strings::uuid();
        $thread = Thread::of($threadId, '@threads/' . $threadId);
        $this->threads->create($thread);

        /** @var Agent $agent */
        $agent = $this->container->make($agentClass, [
            'thread' => $thread,
            'profile' => $profile,
            'workspace' => getcwd() ?: '',
        ]);
        $agent->thread()->state = ['profileId' => $profile->id];
        $this->threads->saveState($agent->thread());
        $agent->start();

        return $agent;
    }

    protected function openSaved(string $datadir): Agent
    {
        $thread = Thread::of(basename($datadir), $datadir);
        $this->threads->load($thread);
        /** @var Agent $agent */
        $agent = $this->container->make(Agent::class, [
            'thread' => $thread,
            'workspace' => (string)($thread->state['workspace'] ?? ''),
            'profile' => $this->profiles->get($thread->state['profileId']),
        ]);
        $agent->start();

        return $agent;
    }

    protected function unknownAgentMessage(string $agent): string
    {
        return 'Unknown agent "' . $agent . '". Use a catalog profile id (e.g. agent, plan, coding.explore) or an AgentInterface class name.';
    }

    /**
     * @param class-string<Agent> $agentClass
     * @param array<string, mixed> $params
     * @param list<string>         $tools    Override the profile tool grant; empty keeps the profile's own
     */
    protected function runChatSession(
        string $agentClass,
        array  $params,
        string $requestedId,
        bool   $showPrompt,
        string $message,
        array  $tools = [],
        bool   $rawTools = false,
    ): int
    {
        try {
            if ($requestedId === 'new' || $requestedId === '') {
                $agent = $this->openNew((string)($params['profileId'] ?? ''), $agentClass, $tools);
                $resumed = false;
            } else {
                $agent = $this->openSaved('@threads/' . $requestedId);
                $resumed = $this->threadHasHistory($agent->thread()->id);
            }
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        $threadId = $agent->thread()->id;

        $this->agentConsole->configure($showPrompt, $rawTools);
        $this->agentConsole->start();

        return $this->chatRepl($agent, $threadId, $message, $resumed);
    }

    /**
     * Print one catalog profile as a single prompt, with charter tools as the leading section.
     *
     * Guidance: always renders the root-session depth. A delegated worker's prompt only exists on
     * an agent that was really spawned, so there is no faithful way to preview it here.
     *
     * @param string $profileId Catalog profile id
     * @param list<string> $segments Extra live segment ids appended after the profile
     * @param string $tools Comma-separated tool allowlist; empty keeps the profile's own grant
     */
    public function promptAction(
        string $profileId,
        array $segments = [],
        string $tools = '',
    ): int {
        try {
            $charter = $this->charters->get(
                $this->memoryAgent(
                    $profileId,
                    $segments,
                    preg_split('/[\s,]+/', $tools, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                ),
            );
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        $lines = ['## Tools', ''];
        if ($charter->tools === []) {
            $lines[] = '(none)';
        } else {
            foreach ($charter->tools as $name => $schema) {
                $lines[] = '- ' . $name;
                $description = trim((string)($schema->definition['description'] ?? ''));
                if ($description !== '') {
                    $lines[] = '  ' . str_replace("\n", "\n  ", $description);
                }
            }
        }
        $lines[] = '';
        $lines[] = $charter->prompt;

        $this->console->writeLn(implode("\n", $lines));

        return ConsoleInterface::SUCCESS;
    }

    protected function chatRepl(
        AgentInterface $agent,
        string         $threadId,
        string         $initial,
        bool           $resumed,
    ): int
    {
        $this->writeHeader($agent, $resumed);
        $this->console->writeLn('Type after > and press Enter. exit / quit to leave. Ctrl+C cancels a running reply.');
        $this->console->writeLn('');

        $this->installInterruptHandler();

        if ($initial !== '') {
            try {
                $this->run($agent, $initial);
            } catch (Throwable $e) {
                $this->console->writeLn('✗ ' . $e->getMessage());

                return ConsoleInterface::FAILURE;
            }
        }

        while (true) {
            $line = $this->readReplLine();
            if ($line === null) {
                break;
            }

            try {
                $this->run($agent, $line);
            } catch (Throwable $e) {
                $this->console->writeLn('✗ ' . $e->getMessage());
            }
        }

        $this->writeSessionHint($threadId);

        return ConsoleInterface::SUCCESS;
    }

    protected function writeHeader(AgentInterface $agent, bool $resumed): void
    {
        $label = $agent->parent() === null
            ? (new ReflectionClass($agent))->getShortName()
            : $agent->profile()->id;
        $suffix = $resumed ? ' · resumed' : '';
        $this->console->writeLn($agent->threadId() . ' · ' . $label . $suffix);
        $this->console->writeLn('');
    }

    /**
     * Read one REPL line; null ends the session (EOF or exit / quit).
     *
     * Blank input is ignored (the prompt is redrawn) so a stray Enter cannot end a session.
     */
    protected function readReplLine(): ?string
    {
        while (true) {
            if ($this->isInteractiveInput()) {
                $this->writeReplPrompt();
            }

            $line = trim($this->console->read());

            if ($this->isInteractiveInput()) {
                $this->clearReplInputLine();
            }

            if ($line === '') {
                // EOF shows up as an empty read on a non-interactive stream; a real blank line is ignored.
                if (!$this->isInteractiveInput()) {
                    return null;
                }

                continue;
            }

            if (in_array(strtolower($line), ['exit', 'quit'], true)) {
                return null;
            }

            return $line;
        }
    }

    protected function writeReplPrompt(): void
    {
        $prompt = '> ';
        if (!$this->console->isSupportColor()) {
            $this->console->write($prompt);

            return;
        }

        $this->console->write($this->console->colorize($prompt, Colors::AT_BOLD));
    }

    protected function clearReplInputLine(): void
    {
        // Cursor sits on the line after Enter; wipe the submitted input line above.
        $this->console->write("\033[1A\033[2K\r");
    }

    protected function isInteractiveInput(): bool
    {
        return function_exists('posix_isatty') && @posix_isatty(STDIN);
    }

    protected function run(AgentInterface $agent, string $input): void
    {
        $this->interrupted = false;
        $watcher = Coroutine::create(function () use ($agent): void {
            // Poll the SIGINT flag; Agent::cancel() blocks, so it must run on a coroutine, not a handler.
            while (true) {
                Coroutine::sleep(0.05);
                if (!$this->interrupted) {
                    continue;
                }

                $this->console->writeLn('');
                $this->console->writeLn('✗ interrupted');
                $agent->cancel();

                return;
            }
        });

        try {
            $agent->run(Envelope::of($input, $agent->profile()->model));
        } finally {
            Coroutine::cancel($watcher);
            $this->interrupted = false;
        }
    }

    protected function writeSessionHint(string $threadId): void
    {
        $this->console->writeLn('');
        $this->console->writeLn('thread → ' . $threadId . '  (resume: --thread=' . $threadId . ')');
    }

    /**
     * Cancel the in-flight reply on Ctrl+C without leaving the REPL.
     *
     * `Swoole\Process::signal` is the coroutine-safe registrar; the handler only flips a flag
     * because {@see Agent::cancel()} blocks until the worker is quiescent.
     */
    protected function installInterruptHandler(): void
    {
        Process::signal(SIGINT, function (): void {
            $this->interrupted = true;
        });
    }

    protected function threadHasHistory(string $threadId): bool
    {
        $threads = $this->container->get(ThreadsInterface::class);

        try {
            $thread = Thread::of($threadId, '@threads/' . $threadId);
            $threads->load($thread);

            return $thread->turns !== [];

        } catch (ThreadNotFoundException) {
            return false;
        }
    }

    /**
     * @return null|array{0: class-string<Agent>, 1: array<string, mixed>}
     */
    protected function resolveProfile(string $agent): ?array
    {
        $agent = trim($agent);
        if ($agent === '') {
            return null;
        }

        // Catalog id: any markdown-defined profile (e.g. agent, ask, coding.explore).
        if ($this->profiles->has($agent)) {
            return [Agent::class, ['profileId' => $agent]];
        }

        if (!class_exists($agent) || !is_a($agent, AgentInterface::class, true)) {
            return null;
        }

        /** @var class-string<Agent> $agent */
        return [$agent, []];
    }

    /**
     * Root-session agent for prompt rendering — no thread is started and no child is spawned.
     *
     * Guidance: `$parent` only exists on a really spawned child, so this agent is always a root.
     *
     * @param list<string> $segments
     * @param list<string> $tools    Override the profile tool grant; empty keeps the profile's own
     */
    protected function memoryAgent(string $profileId, array $segments, array $tools = []): Agent
    {
        $profile = $this->profiles->get($profileId);
        if ($segments !== []) {
            $profile = clone $profile;
            $profile->segments = [...$profile->segments, ...$segments];
        }
        if ($tools !== []) {
            $profile = clone $profile;
            $profile->tools = $tools;
        }

        /** @var Agent $agent */
        $agent = $this->container->make(Agent::class, [
            'thread' => Thread::of($profile->id),
            'profile' => $profile,
            'workspace' => '',
        ]);

        return $agent;
    }
}
