<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use Switon\Core\Attribute\Autowired;
use Switon\Lint\ProcessInterface;

/**
 * Lua: syntax-only via `luac -p`. Wired for `lua`.
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class LuaLintDriver implements LintDriverInterface
{
    #[Autowired] protected ProcessInterface $process;

    #[Autowired] protected string $luacBinary = 'luac';

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        unset($deep);
        $syntax = $this->luacCheck($path, $shouldAbort);

        return [
            'path' => $path,
            'tools' => [['name' => 'luac -p', 'ok' => $syntax === []]],
            'diagnostics' => $syntax,
        ];
    }

    /**
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function luacCheck(string $path, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$this->luacBinary, '-p', $path],
            $shouldAbort,
            null
        );

        if (($result['exit'] ?? 1) === 0) {
            return [];
        }

        $blob = trim(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? ''));
        $line = 1;
        if (preg_match('/:(\d+):\s*/', $blob, $m) === 1) {
            $line = max(1, (int) $m[1]);
        }

        $message = $blob !== '' ? $blob : 'luac -p failed';
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return [[
            'line' => $line,
            'severity' => 'error',
            'message' => $message,
        ]];
    }
}
