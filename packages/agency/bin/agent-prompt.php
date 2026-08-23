#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Print one catalog profile's rendered system prompt and charter tools.
 *
 * Usage:
 *   php packages/agency/bin/agent-prompt.php --profile=file-researcher
 *   php packages/agency/bin/agent-prompt.php --profile=coding.agent --segment=app.extra
 */

// Locate the autoloader before touching any package class (monorepo and vendor/ installs alike).
$root = require __DIR__ . '/_bootstrap.php';

use Switon\Agency\Command\AgentCommand;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

if (function_exists('xdebug_set_mode')) {
    xdebug_set_mode(XDEBUG_MODE_OFF);
}

$options = getopt('', ['profile:', 'segment:', 'tools:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<HELP
Usage: php packages/agency/bin/agent-prompt.php [options]

Options:
  --profile=ID    catalog profile id (required)
  --segment=ID    extra live segment id; repeat to append more than one
  --tools=LIST    comma-separated tool allowlist; overrides the profile grant
  --help          this help

HELP);
    exit(0);
}

$profileId = (string) ($options['profile'] ?? '');
$segments = $options['segment'] ?? [];
if (is_string($segments)) {
    $segments = [$segments];
}

exit(Runner::run(function (ContainerInterface $container) use ($profileId, $segments, $options): int {
    /** @var AgentCommand $command */
    $command = $container->make(AgentCommand::class);

    return $command->promptAction(
        $profileId,
        $segments,
        (string) ($options['tools'] ?? ''),
    );
}, $root));
