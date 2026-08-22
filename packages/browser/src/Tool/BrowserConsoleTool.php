<?php

declare(strict_types=1);

namespace Switon\Browser\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowsersInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;

use function array_slice;
use function count;
use function implode;
use function sprintf;

/** Tool `browser_console` — what the page logged, threw, and failed to load. */
class BrowserConsoleTool extends AbstractTool
{
    /** Lines returned by default; a long page can log hundreds. */
    protected const DEFAULT_LIMIT = 50;

    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Read the named tab console: console lines, uncaught exceptions, and failed requests (4xx/5xx, network errors) since the last navigation. Read this when the page did not behave as expected.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'level' => [
                    'type' => 'string',
                    'description' => 'Minimum level to show: error (default), warn, or all.',
                    'default' => 'error',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Return at most this many of the most recent entries.',
                    'default' => self::DEFAULT_LIMIT,
                ],
            ],
            required: ['tab'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $shouldAbort = fn (): bool => $agent->isCancelled() || $call->cancelled;
        $tab = (string) $arguments['tab'];
        $level = (string) ($arguments['level'] ?? 'error');
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        try {
            $entries = $this->browsers->tab($agent, $tab)->diagnostics($shouldAbort);
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }

        $kept = [];
        foreach ($entries as $entry) {
            if (!$this->passes($entry['level'], $level)) {
                continue;
            }
            $kept[] = $entry;
        }
        if (count($kept) > $limit) {
            $kept = array_slice($kept, -$limit);
        }

        $lines = [];
        foreach ($kept as $entry) {
            $where = $entry['url'] === '' ? '' : ' (' . $entry['url'] . ($entry['line'] > 0 ? ':' . $entry['line'] : '') . ')';
            $lines[] = sprintf('%s/%s %s%s', $entry['type'], $entry['level'], $entry['text'], $where);
        }

        return [
            'entries' => $kept,
            'count' => count($kept),
            'text' => $lines === [] ? '(no console output at this level)' : implode("\n", $lines),
        ];
    }

    /** Whether an entry clears the requested level. */
    protected function passes(string $level, string $requested): bool
    {
        $order = ['debug' => 0, 'info' => 1, 'log' => 1, 'warn' => 2, 'warning' => 2, 'error' => 3];
        if ($requested === 'all') {
            return true;
        }
        $floor = $order[$requested] ?? 3;

        return ($order[$level] ?? 3) >= $floor;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
