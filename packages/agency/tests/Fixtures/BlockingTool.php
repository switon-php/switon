<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Tooling\AbstractTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Swoole\Coroutine\Channel;
use RuntimeException;

/**
 * Test-only tool: blocks an active run until the test releases the keyed gate.
 */
class BlockingTool extends AbstractTool
{
    /** @var array<string, Channel> */
    protected static array $entered = [];

    /** @var array<string, Channel> */
    protected static array $release = [];

    public static function arm(string $key): void
    {
        self::$entered[$key] = new Channel(1);
        self::$release[$key] = new Channel(1);
    }

    public static function enteredChannel(string $key): ?Channel
    {
        $channel = self::$entered[$key] ?? null;

        return $channel instanceof Channel ? $channel : null;
    }

    public static function waitUntilBlocked(string $key, float $timeout = 1.0): void
    {
        $channel = self::enteredChannel($key);
        if (!$channel instanceof Channel || $channel->pop($timeout) !== true) {
            throw new RuntimeException('Blocking tool did not enter for key "' . $key . '".');
        }
    }

    public static function release(string $key): void
    {
        (self::$release[$key] ?? null)?->push(true);
    }

    public static function reset(string $key): void
    {
        unset(self::$entered[$key], self::$release[$key]);
    }

    /** Release every armed gate (test teardown — avoids Swoole deadlock warnings). */
    public static function drainAll(): void
    {
        foreach (array_keys(self::$release) as $key) {
            (self::$release[$key] ?? null)?->push(true);
        }
        self::$entered = [];
        self::$release = [];
    }

    public function schema(string $name): ToolSchema
    {
        return new ToolSchema(
            $this,
            description: 'Test-only blocking tool.',
            properties: [
                'key' => [
                    'type' => 'string',
                    'description' => 'Blocking gate key',
                ],
            ],
            required: ['key'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $key = is_string($arguments['key'] ?? null) ? $arguments['key'] : '';
        if ($key === '') {
            return ['status' => 'released'];
        }

        $entered = self::$entered[$key] ?? null;
        $release = self::$release[$key] ?? null;

        try {
            $entered?->push(true);
            $release?->pop(1.0);
        } finally {
            self::reset($key);
        }

        return ['status' => 'released'];
    }

}
