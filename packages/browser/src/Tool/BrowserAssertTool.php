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

use function sprintf;
use function trim;

/** Tool `browser_assert` — did the last step actually work? */
class BrowserAssertTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Check a snapshot ref, or a CSS selector, on the named tab against a condition and report whether it held: visible, hidden, text, text_contains, value, enabled, checked. Retries for a few seconds, so a page that updates after a click still passes. Returns ok plus what was actually found.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Snapshot ref (e12), or a CSS selector such as #result for text the snapshot lists without a ref.',
                    'minLength' => 1,
                ],
                'kind' => [
                    'type' => 'string',
                    'description' => 'visible | hidden | text | text_contains | value | enabled | checked',
                    'default' => 'visible',
                ],
                'expected' => [
                    'type' => 'string',
                    'description' => 'Expected text or value. Used by text, text_contains, and value.',
                    'default' => '',
                ],
                'timeout' => [
                    'type' => 'number',
                    'description' => 'Seconds to keep retrying before reporting a failure.',
                    'default' => 5,
                ],
            ],
            required: ['tab', 'ref'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $shouldAbort = fn (): bool => $agent->isCancelled() || $call->cancelled;
        $tab = (string) $arguments['tab'];
        $target = (string) $arguments['ref'];
        $kind = trim((string) ($arguments['kind'] ?? 'visible'));
        $expected = (string) ($arguments['expected'] ?? '');
        $timeout = (float) ($arguments['timeout'] ?? 5.0);

        try {
            $result = $this->browsers->tab($agent, $tab)->assert($target, $kind, $expected, $timeout, $shouldAbort);
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }

        return $result + [
            'text' => $result['ok']
                ? sprintf('%s: %s', $kind, $result['actual'])
                : sprintf(
                    '%s failed after %dms: expected "%s", found "%s"',
                    $kind,
                    $result['waitedMs'],
                    $expected,
                    $result['actual'],
                ),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
