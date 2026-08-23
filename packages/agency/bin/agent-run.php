#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-shot agent run: stdout carries only the final reply, so it pipes and scripts cleanly.
 *
 * Usage:
 *   php packages/agency/bin/agent-run.php --message "Summarize README.md"
 *   php packages/agency/bin/agent-run.php --agent plan --message "..." --thread demo-001
 *   php packages/agency/bin/agent-run.php --message "..." > reply.txt
 */

// Locate the autoloader before touching any package class (monorepo and vendor/ installs alike).
$root = require __DIR__ . '/_bootstrap.php';

use Switon\Agency\Command\AgentCommand;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

$options = getopt('', ['agent:', 'thread:', 'message:', 'tools:', 'help']);
if (isset($options['help']) || !isset($options['message'])) {
    fwrite(STDOUT, <<<HELP
Usage: php packages/agency/bin/agent-run.php --message "..." [options]

One-shot run. stdout carries only the final reply; `thread → id` goes to stderr.

Options:
  --message=TEXT       User message (required)
  --agent=NAME         catalog profile id (default: assist; e.g. plan, coding.explore) or agent service class
  --thread=ID          resume this id; omit or `new` to create a fresh thread
  --tools=LIST         comma-separated tool allowlist; overrides the profile grant
  --help               this help

LLM credentials: ~/.claude/settings.json (ANTHROPIC_AUTH_TOKEN, ANTHROPIC_MODEL, ANTHROPIC_BASE_URL)
Optional override: LLM_BASE_URL

HELP);
    exit(isset($options['help']) ? 0 : 1);
}

exit(Runner::run(static function (ContainerInterface $container) use ($options): int {
    /** @var AgentCommand $command */
    $command = $container->make(AgentCommand::class);

    return $command->runAction(
        agent: (string) ($options['agent'] ?? 'assist'),
        message: (string) $options['message'],
        threadId: (string) ($options['thread'] ?? ''),
        tools: (string) ($options['tools'] ?? ''),
    );
}, $root));
