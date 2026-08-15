<?php

declare(strict_types=1);

/**
 * Shared bootstrap for scripts under tests/manual/.
 *
 * Requires ~/.switon/llm.yml (see resources/llm.yml.example).
 */

use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Ai\TurnStatus;
use Switon\Core\App;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Llm\AbstractLlm;
use Switon\Llm\Anthropic;
use Switon\Llm\Event\CompletionDelta;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Llm\Exception\HttpTransportException;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProvidersInterface;
use Switon\Testing\Container;

$packageRoot = dirname(__DIR__, 2);
$packagesDir = dirname($packageRoot);
if (basename($packagesDir) === 'packages' && is_file(dirname($packagesDir) . '/vendor/autoload.php')) {
    require dirname($packagesDir) . '/vendor/autoload.php';
    require dirname($packagesDir) . '/tests/bootstrap.php';
} elseif (is_file($packageRoot . '/vendor/autoload.php')) {
    require $packageRoot . '/vendor/autoload.php';
} else {
    throw new RuntimeException(
        'Composer autoloader not found. Run "composer install" in the repository root or the package root.',
    );
}

function liveLlmContainer(): Container
{
    $container = new Container();
    App::setContainer($container);
    $container->enableEventDispatching();

    return $container;
}

function liveLlmProviders(Container $container, int $timeout = 120): LlmProvidersInterface
{
    $discovery = $container->get(LlmDiscoveryInterface::class);
    if ($discovery instanceof LlmDiscovery) {
        $discovery->timeout = $timeout;
        $discovery->discover();
    }

    return $container->get(LlmProvidersInterface::class);
}

function liveLlmResolve(Container $container, string $model = '', int $timeout = 120): LlmInterface
{
    return liveLlmProviders($container, $timeout)->get($model);
}

/**
 * Prefer vendor/default, else first catalog model under that vendor prefix.
 */
function liveLlmResolveVendor(Container $container, string $vendor, int $timeout = 120): LlmInterface
{
    $providers = liveLlmProviders($container, $timeout);
    if ($providers->has($vendor . '/default')) {
        return $providers->get($vendor . '/default');
    }

    foreach ($providers->all() as $name => $llm) {
        if ($name === $llm->model()->id && str_starts_with($name, $vendor . '/')) {
            return $llm;
        }
    }

    throw new RuntimeException(
        "No \"{$vendor}/*\" model in ~/.switon/llm.yml (see resources/llm.yml.example).",
    );
}

/**
 * @return array{model: string, baseUrl: string, class: string}
 */
function liveLlmDescribe(LlmInterface $llm): array
{
    return [
        'model' => $llm->model()->model,
        'baseUrl' => $llm instanceof AbstractLlm ? $llm->baseUrl() : '',
        'class' => $llm::class,
    ];
}

function liveLlmEchoProviderSummary(string $label, LlmInterface $llm, bool $stream): void
{
    $config = liveLlmDescribe($llm);
    $mode = $stream ? 'stream=true' : 'stream=false';
    $wirePath = $llm instanceof Anthropic
        ? rtrim($config['baseUrl'], '/') . '/messages'
        : rtrim($config['baseUrl'], '/') . '/chat/completions';

    echo "{$label} ({$mode}, no tools)\n";
    echo "  class:   {$config['class']}\n";
    echo "  model:   {$config['model']}\n";
    echo "  baseUrl: {$config['baseUrl']}\n";
    echo "  wire:    POST {$wirePath}\n";
}

/**
 * Parse live-chat.php argv: --stream/-s, --vendor=, --model=, or positional openai|anthropic|main.
 *
 * @return array{stream: bool, vendor: ?string, model: string}
 */
function liveLlmParseChatArgv(array $argv): array
{
    $stream = false;
    $vendor = null;
    $model = '';

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, <<<'HELP'
Usage: php tests/manual/live-chat.php [options] [openai|anthropic|main]

  --stream, -s              stream assistant tokens
  --vendor=openai|anthropic resolve via vendor catalog
  --model=NAME              catalog name (default: first client)
  openai|anthropic|main     positional shortcut

HELP);
            exit(0);
        }
        if ($arg === '--stream' || $arg === '-s') {
            $stream = true;
            continue;
        }
        if (str_starts_with($arg, '--vendor=')) {
            $vendor = substr($arg, strlen('--vendor='));
            continue;
        }
        if (str_starts_with($arg, '--model=')) {
            $model = substr($arg, strlen('--model='));
            continue;
        }
        if (in_array($arg, ['openai', 'anthropic', 'main'], true)) {
            if ($arg === 'main') {
                $model = 'main';
                $vendor = null;
            } else {
                $vendor = $arg;
            }
            continue;
        }
    }

    return [
        'stream' => $stream,
        'vendor' => $vendor,
        'model' => $model,
    ];
}

function liveLlmColorEnabled(): bool
{
    if (!stream_isatty(STDOUT)) {
        return false;
    }

    $noColor = getenv('NO_COLOR');

    return !is_string($noColor) || $noColor === '';
}

function liveLlmStyle(string $text, string $role): string
{
    if (!liveLlmColorEnabled()) {
        return $text;
    }

    $code = match ($role) {
        'user' => '36',
        'assistant' => '35',
        'meta' => '90',
        'error' => '31',
        default => '0',
    };

    return "\033[{$code}m{$text}\033[0m";
}

/** @return object{leadingTrimmed: bool} */
function liveLlmNewStreamState(): object
{
    $state = new stdClass();
    $state->leadingTrimmed = false;

    return $state;
}

function liveLlmTrimAssistantContent(string $content): string
{
    return ltrim($content, "\r\n \t");
}

function liveLlmRegisterStreamPrinter(Container $container, object $state): void
{
    $container->get(ListenerProviderInterface::class)->on(
        null,
        static function (CompletionDelta $event) use ($state): void {
            $delta = $event->streamDelta->content;
            if ($delta === '') {
                return;
            }

            if (!$state->leadingTrimmed) {
                $delta = liveLlmTrimAssistantContent($delta);
                $state->leadingTrimmed = true;
            }

            if ($delta !== '') {
                echo $delta;
            }
        },
    );
}

function liveLlmReadLine(): ?string
{
    $prompt = liveLlmStyle('You> ', 'user');

    if (function_exists('readline') && stream_isatty(STDIN)) {
        $line = readline($prompt);
        if ($line !== false && trim($line) !== '') {
            readline_add_history($line);
        }

        return $line === false ? null : $line;
    }

    echo $prompt;
    $line = fgets(STDIN);

    return $line === false ? null : rtrim($line, "\r\n");
}

function liveLlmShouldExit(?string $line): bool
{
    if ($line === null) {
        return true;
    }

    return in_array(strtolower(trim($line)), ['exit', 'quit', '/quit', ':q'], true);
}

function liveLlmFormatError(Throwable $e): string
{
    if ($e instanceof HttpStatusException) {
        $body = trim($e->getResponseBody());
        if (strlen($body) > 400) {
            $body = substr($body, 0, 400) . '...';
        }

        $detail = "HTTP {$e->getStatusCode()}";
        if ($body !== '') {
            $detail .= ": {$body}";
        }

        return $detail;
    }

    if ($e instanceof HttpTransportException) {
        $context = $e->getContext();
        $errno = $context['errno'] ?? null;
        $error = $context['error'] ?? null;
        if (is_int($errno) || (is_string($error) && $error !== '')) {
            return trim("transport errno={$errno} {$error}");
        }
    }

    return $e->getMessage();
}

function liveLlmInteractiveChat(Container $container, LlmInterface $chat, bool $stream): void
{
    $streamState = liveLlmNewStreamState();
    if ($stream) {
        liveLlmRegisterStreamPrinter($container, $streamState);
    }

    $thread = new Thread('test');
    $assistantPrompt = liveLlmStyle('Assistant> ', 'assistant');

    while (true) {
        $line = liveLlmReadLine();
        if (liveLlmShouldExit($line)) {
            break;
        }

        $message = trim($line ?? '');
        if ($message === '') {
            continue;
        }

        try {
            $slot = Completion::of(UserMessage::of($message));
            $thread->turns[] = \Switon\Ai\Turn::of(
                't',
                completions: [$slot],
                status: TurnStatus::Running,
                model: $chat->model()->id,
            );
            if ($stream) {
                $streamState->leadingTrimmed = false;
                echo $assistantPrompt;
                $completion = $chat->stream($thread, Charter::of());
                echo "\n";
            } else {
                $completion = $chat->complete($thread, Charter::of());
                $content = liveLlmTrimAssistantContent($completion->output?->content ?? '');
                echo $assistantPrompt . $content . "\n";
            }

            if ($completion->usage !== null) {
                echo liveLlmStyle(
                    '  [tokens: ' . ($completion->usage->input ?? '?') . '+' . ($completion->usage->output ?? '?')
                    . ", finish={$completion->finishReason}]\n",
                    'meta',
                );
            }

            echo "\n";
        } catch (Throwable $e) {
            echo liveLlmStyle('Error: ' . liveLlmFormatError($e) . "\n\n", 'error');
        }
    }

    echo liveLlmStyle("Bye.\n", 'meta');
}
