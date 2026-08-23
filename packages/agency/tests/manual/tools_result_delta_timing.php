<?php

declare(strict_types=1);

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\Profile;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\Completion;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\UserMessage;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Eventing\EventDispatcherInterface;
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
    $tools = $container->get(ToolsInterface::class);
    $containerDispatcher = $container->get(EventDispatcherInterface::class);

    $toolsDispatcherProp = new ReflectionProperty($tools, 'eventDispatcher');
    $toolsDispatcher = $toolsDispatcherProp->getValue($tools);

    $bash = $container->get(Switon\Tooling\Tool\BashTool::class);
    $bashDispatcherProp = new ReflectionProperty($bash, 'eventDispatcher');
    $bashDispatcher = $bashDispatcherProp->getValue($bash);

    echo 'container===tools: ' . ($containerDispatcher === $toolsDispatcher ? 'yes' : 'no') . PHP_EOL;
    echo 'container===bash: ' . ($containerDispatcher === $bashDispatcher ? 'yes' : 'no') . PHP_EOL;
    echo 'tools===bash: ' . ($toolsDispatcher === $bashDispatcher ? 'yes' : 'no') . PHP_EOL;

    $call = new ToolCall(name: 'bash', id: 'call-test', arguments: [
        'command' => 'for i in $(seq 1 5); do echo tick $i; sleep 1; done',
    ]);
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

    $before = count($events);

    $t0 = microtime(true);
    $tools->call($agent, $call, $tools->get('bash'));
    $t1 = microtime(true);

    $deltas = count($events) - $before;

    echo "deltas=$deltas total=" . round($t1 - $t0, 2) . 's' . PHP_EOL;
});
