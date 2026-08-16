<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Path;
use Switon\Lint\LinterInterface;
use Switon\Tooling\AbstractTool;

use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Tool `lint`. One file through {@see LinterInterface}.
 *
 * Guidance: prefer the `lint` field on an edit or write result, and call this only when nothing was edited.
 */
class LintTool extends AbstractTool
{
    #[Autowired] protected LinterInterface $linter;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Lint one file (syntax by default; set deep=true for analyzers like PHPStan/ESLint/go vet/shellcheck). Prefer the `lint` field on edit/write/edits when present (syntax-only).',
            properties: [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute path.',
                ],
                'deep' => [
                    'type' => 'boolean',
                    'description' => 'When true, also run project analyzers if available (slower).',
                    'default' => false,
                ],
            ],
            required: ['path'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        // Do not trim args — use as given.
        $path = Path::absolute($arguments['path']);
        $deep = $arguments['deep'] ?? false;

        $result = $this->linter->lint($path, fn (): bool => $agent->isCancelled() || $call->cancelled, $deep);
        // Path is already on the call args — do not echo it in the result.
        unset($result['path']);
        $result['diagnostics'] = $this->spillDiagnostics($call->id, $agent, $result['diagnostics']);

        return $result;
    }

    /**
     * @param list<array{line?: int, severity?: string, message?: string}>|mixed $diagnostics
     */
    protected function spillDiagnostics(string $id, AgentInterface $agent, mixed $diagnostics): mixed
    {
        $json = json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return $diagnostics;
        }

        $out = $this->spillField($json, $id, $agent, 'diagnostics');
        if ($out !== $json) {
            return $out;
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
