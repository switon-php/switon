<?php

declare(strict_types=1);

/** Interactive LLM chat REPL (no tools). Run `php tests/manual/live-chat.php --help`. */

require __DIR__ . '/bootstrap.php';

$opts = liveLlmParseChatArgv($argv);
$container = liveLlmContainer();

if ($opts['vendor'] !== null) {
    $chat = liveLlmResolveVendor($container, $opts['vendor']);
    $label = $opts['vendor'];
} else {
    $chat = liveLlmResolve($container, $opts['model']);
    $label = $opts['model'];
}

liveLlmEchoProviderSummary($label, $chat, $opts['stream']);
echo "  exit:    quit / exit / Ctrl+D\n\n";
liveLlmInteractiveChat($container, $chat, $opts['stream']);
