<?php

declare(strict_types=1);

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\ToolCall;
use Switon\Agency\Agent;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

Runner::run(function (ContainerInterface $container): void {
liveRegisterAgentPackage($container);
liveStartConsole($container);

$agent = $container->get(RecordingAgentManager::class)->mint([
    'profile' => new \Switon\Ai\Profile(
        id: 'shell-smoke',
        prompt: '',
        tools: ['git', 'echo'],
        skills: [],
        model: 'main',
        maxSteps: 10,
    ),
]);

/** @var ToolsInterface $tools */
$tools = $container->make(Tools::class);

$exposed = $tools->pick(['git', 'echo']);
echo "all keys: " . json_encode(array_keys($exposed), JSON_UNESCAPED_UNICODE) . "\n";
echo "git schema same as echo: " . ($exposed['git']->definition['description'] === $exposed['echo']->definition['description'] ? 'yes' : 'no') . "\n";

foreach (['echo', 'git'] as $cmd) {
    $schema = $tools->get($cmd);
    $call = new ToolCall(name: $cmd, arguments: [
        'argv' => $cmd === 'echo' ? ['hello'] : ['--version'],
    ]);
    $result = $tools->call($agent, $call, $schema);
    $payload = json_decode($result->content, true, flags: JSON_THROW_ON_ERROR);
    echo "{$cmd} exit={$payload['exit']} stdout=" . json_encode(trim((string) $payload['stdout']), JSON_UNESCAPED_UNICODE) . "\n";
}
});
