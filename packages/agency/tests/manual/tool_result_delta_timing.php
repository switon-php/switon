<?php

declare(strict_types=1);

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\Profile;
use Switon\Tooling\Tool\BashTool;
use Switon\Ai\ToolCall;
use Switon\Ai\Completion;
use Switon\Ai\Message\UserMessage;
use Switon\Core\PathAliasInterface;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Eventing\ListenerProviderInterface;

require __DIR__ . '/live_bootstrap.php';

Runner::run(function (ContainerInterface $container): void {
    liveRegisterAgentPackage($container);
    $events = [];
    $container->get(ListenerProviderInterface::class)->on(null, static function (object $event) use (&$events): void {
        if ($event instanceof ToolResultDelta) {
            $events[] = $event;
        }
    });
    $bash = $container->make(BashTool::class);
    $before = count($events);
    $call = new ToolCall(name: 'bash', id: 'call-test');
    $completion = Completion::of(UserMessage::of('run'));
    $agent = $container->get(RecordingAgentManager::class)->mint([
        'profile' => new Profile(
            id: 't',
            prompt: '',
            tools: ['bash'],
            skills: [],
            model: 'main',
            maxSteps: 10,
        ),
    ]);
    $args = [
        'command' => 'for i in $(seq 1 5); do echo tick $i; sleep 1; done',
    ];
    $datadir = $container->get(PathAliasInterface::class)->resolve($agent->thread()->datadir);
    $host = $agent;

    $t0 = microtime(true);
    $result = $bash->call($host, $call, $args);
    $t1 = microtime(true);
    $events = array_slice($events, $before);

    foreach ($events as $index => $event) {
        echo sprintf('delta %d chunk=%s', $index, json_encode($event->chunk, JSON_UNESCAPED_UNICODE)) . PHP_EOL;
    }

    echo 'deltas=' . count($events) . ' total=' . round($t1 - $t0, 2) . 's' . PHP_EOL;
    echo 'stdout=' . json_encode($result['stdout'] ?? '', JSON_UNESCAPED_UNICODE) . PHP_EOL;
});
