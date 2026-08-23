<?php

declare(strict_types=1);

/**
 * Instruct live demo: number guessing via a `guess` tool, then structured response.
 *
 *   php packages/agency/tests/manual/live-instructor.php
 *   php packages/agency/tests/manual/live-instructor.php 42
 *
 * User text stays natural — exit rules live in @agency/agents/instructor.md.
 */

use Switon\Ai\Profile;
use Switon\Ai\InstructorInterface;
use Switon\Ai\ToolSchema;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Tooling\AbstractTool;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

class LiveInstructorGuessTool extends AbstractTool
{
    public int $attempts = 0;

    public function __construct(public readonly int $secret = 50)
    {
    }

    public function schema(string $name): ToolSchema
    {
        return new ToolSchema(
            $this,
            description: 'Guess an integer in [1, 100]. Returns higher, lower, or correct.',
            properties: [
                'n' => [
                    'type' => 'integer',
                    'description' => 'Your guess.',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
            required: ['n'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $n = (int) ($arguments['n'] ?? 0);
        $this->attempts++;
        if ($n < 1 || $n > 100) {
            return ['hint' => 'out_of_range', 'attempts' => $this->attempts];
        }
        if ($n < $this->secret) {
            return ['hint' => 'higher', 'attempts' => $this->attempts];
        }
        if ($n > $this->secret) {
            return ['hint' => 'lower', 'attempts' => $this->attempts];
        }

        return ['hint' => 'correct', 'attempts' => $this->attempts, 'n' => $n];
    }
}

$secret = isset($argv[1]) && ctype_digit($argv[1])
    ? max(1, min(100, (int) $argv[1]))
    : random_int(1, 100);

Runner::run(function (ContainerInterface $container) use ($secret): void {
$container->set(LiveInstructorGuessTool::class, ['secret' => $secret]);
if ($container->isResolved(ToolsInterface::class)) {
    $container->replace(ToolsInterface::class, [
        'class' => Tools::class,
        'tools' => [
            'guess' => LiveInstructorGuessTool::class,
        ],
    ]);
    $container->get(ToolsInterface::class)->reload();
} else {
    $container->replace(ToolsInterface::class, [
        'class' => Tools::class,
        'tools' => [
            'guess' => LiveInstructorGuessTool::class,
        ],
    ]);
}

liveStartConsole($container);

/** @var InstructorInterface $instructor */
$instructor = $container->make(InstructorInterface::class);
$profile = new Profile(
    id: 'instructor',
    prompt: '',
    tools: ['guess'],
    skills: [],
    model: 'main',
    maxSteps: 16,
);
$threadId = 'instruct-live';

$ask = 'I am thinking of an integer from 1 to 100. You may only use the guess tool. Adjust from the hints, then return the answer when you have it.';

$properties = [
    'number' => [
        'type' => 'integer',
        'description' => 'The secret integer you found.',
        'minimum' => 1,
        'maximum' => 100,
    ],
    'attempts' => [
        'type' => 'integer',
        'description' => 'How many guess tool calls you made.',
        'minimum' => 1,
    ],
];

echo "Thread: {$threadId}\n";
echo "Ask:    {$ask}\n";
echo "(Secret hidden; optional: php …/live-instructor.php <1-100> to pin it)\n\n";

try {
    $result = liveRunInCoroutine(
        function () use ($instructor, $profile, $ask, $properties): array {
            return $instructor->ask($profile, $ask, $properties);
        },
    );
    /** @var LiveInstructorGuessTool $guessTool */
    $guessTool = $container->get(LiveInstructorGuessTool::class);
    echo "Result:\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    echo 'Tool attempts (server): ' . $guessTool->attempts . "\n";
    $ok = (($result['number'] ?? null) === $secret);
    echo $ok ? "Match: yes (secret={$secret})\n\n" : "Match: NO (secret={$secret})\n\n";
} catch (Throwable $e) {
    echo 'FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n";
    echo "Secret was: {$secret}\n\n";
}

echo "OK\n";
});
