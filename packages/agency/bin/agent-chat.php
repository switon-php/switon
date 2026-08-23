#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Plain console agent REPL using Claude Code LLM settings (~/.claude/settings.json).
 *
 * Usage:
 *   php packages/agency/bin/agent-chat.php
 *   php packages/agency/bin/agent-chat.php --agent assist --message "Hello" < /dev/null
 *   php packages/agency/bin/agent-chat.php --agent plan --thread plan-demo-001
 */

// Locate the autoloader before touching any package class (monorepo and vendor/ installs alike).
$root = require __DIR__ . '/_bootstrap.php';

use Switon\Agency\Command\AgentCommand;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

$options = getopt('', ['agent:', 'thread:', 'message:', 'show-prompt', 'raw-tools', 'tools:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<HELP
Usage: php packages/agency/bin/agent-chat.php [options]

Plain console REPL — output streams downward as the agent runs.

Options:
  --agent=NAME         catalog profile id (default: assist; e.g. plan, coding.explore) or agent service class
  --thread=ID          resume this id; omit or `new` to create a fresh thread
  --message=TEXT       first user message; with stdin at EOF (< /dev/null) the REPL exits after it
  --show-prompt        print the full system prompt (hidden by default)
  --raw-tools          print tool-call arguments as raw JSON
  --tools=LIST         comma-separated tool allowlist; overrides the profile grant
  --help               this help

LLM credentials: ~/.claude/settings.json (ANTHROPIC_AUTH_TOKEN, ANTHROPIC_MODEL, ANTHROPIC_BASE_URL)
Optional override: LLM_BASE_URL

HELP);
    exit(0);
}

exit(Runner::run(function (ContainerInterface $container) use ($options): int {
    /** @var AgentCommand $command */
    $command = $container->make(AgentCommand::class);

    return $command->chatAction(
        agent: (string) ($options['agent'] ?? 'assist'),
        threadId: (string) ($options['thread'] ?? ''),
        showPrompt: array_key_exists('show-prompt', $options),
        message: (string) ($options['message'] ?? ''),
        tools: (string) ($options['tools'] ?? ''),
        rawTools: array_key_exists('raw-tools', $options),
    );
}, $root));
