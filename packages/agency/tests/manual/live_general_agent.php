<?php

declare(strict_types=1);

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\ProfilesInterface;
use Switon\Core\PathAliasInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$host = $argv[1] ?? 'd132';

Runner::run(function (ContainerInterface $container) use ($host): void {
liveBindTools($container, ['bash']);
liveRegisterAgentPackage($container);
liveStartConsole($container);
$general = $container->get(ProfilesInterface::class)->get('assist');
$main = $container->get(RecordingAgentManager::class)->mint([
    'profile' => new \Switon\Ai\Profile(
        id: 'assist',
        prompt: <<<'PROMPT'
You are a practical assistant. Use `bash` when you need live data.
For remote hosts: ssh -o BatchMode=yes HOST 'command'.
One user question → one `bash` call when possible; do not batch unrelated checks.
Answer concisely in the user's language. No destructive commands.
PROMPT,
        tools: ['bash'],
        skills: [],
        model: $general->model,
        maxSteps: 15,
    ),
]);

$questions = [
    "SSH to {$host}: how long has the server been up?",
    "Check current load on {$host}.",
    "How many Docker containers are running on {$host}?",
];

foreach ($questions as $question) {
    $main->deliver(Envelope::of($question, liveModel($container, $main->profile()->model)));
}

$runtime = $container->get(PathAliasInterface::class)->get('@runtime');
echo "thread={$main->threadId()} runtime={$runtime}\n";
});
