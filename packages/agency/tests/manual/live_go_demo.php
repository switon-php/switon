<?php

declare(strict_types=1);

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Core\PathAliasInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$workDir = $argv[1] ?? sys_get_temp_dir() . '/switon-agent-go-demo-' . date('Ymd-His');

Runner::run(function (ContainerInterface $container) use ($workDir): void {
liveRegisterAgentPackage($container);
liveStartConsole($container);
$main = $container->get(RecordingAgentManager::class)->mint([
    'profile' => new \Switon\Ai\Profile(
        id: 'go-demo',
        prompt: <<<'PROMPT'
You are a practical assistant. Use tools to inspect and change the local environment when needed.
- bash: shell commands (bash -lc)
- read, write, ls: local file operations
- grep: search file contents with ripgrep (rg)
Run only the commands needed, read tool output, then answer concisely in the user's language.
PROMPT,
        tools: ['bash', 'read', 'sleep'],
        skills: [],
        model: 'main',
        maxSteps: 20,
    ),
]);

$question = "Write a Go hello world in {$workDir}, run it, and report the output.";

echo "Work dir: {$workDir}\n\n";

$main->deliver(Envelope::of($question, liveModel($container, $main->profile()->model)));

$runtime = $container->get(PathAliasInterface::class)->get('@runtime');
$sessionDir = $runtime . '/threads/' . $main->threadId();
echo "Session dir: {$sessionDir}\n";
echo "  conversation.jsonl\n";
echo "  history.md\n\nOK\n";
});
