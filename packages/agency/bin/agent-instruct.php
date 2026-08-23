#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One structured instruct: stdout is the unwrapped data JSON.
 *
 * Usage:
 *   php packages/agency/bin/agent-instruct.php --message "Name the package" --schema '{"name":{"type":"string"}}'
 *   php packages/agency/bin/agent-instruct.php --agent file-researcher --tools read,grep --message "..."
 */

// Locate the autoloader before touching any package class (monorepo and vendor/ installs alike).
$root = require __DIR__ . '/_bootstrap.php';

use Switon\Agency\Command\AgentCommand;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

$options = getopt('', ['agent:', 'message:', 'schema:', 'tools:', 'help']);
if (isset($options['help']) || !isset($options['message'])) {
    fwrite(STDOUT, <<<HELP
Usage: php packages/agency/bin/agent-instruct.php --message "..." [options]

One structured turn. stdout is the unwrapped data JSON; errors go to stderr.

Options:
  --message=TEXT       User message (required)
  --agent=NAME         catalog profile id (default: assist; e.g. plan, coding.explore) or agent service class
  --schema=JSON        business fields under response data, e.g. {"name":{"type":"string"}}; omit for no fields
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

    return $command->instructAction(
        agent: (string) ($options['agent'] ?? 'assist'),
        message: (string) $options['message'],
        schema: (string) ($options['schema'] ?? ''),
        tools: (string) ($options['tools'] ?? ''),
    );
}, $root));
