<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use Switon\Core\Attribute\Autowired;
use Switon\Lint\ProcessInterface;

/**
 * Ruby: syntax-only via `ruby -c`. Wired for `rb`.
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class RubyLintDriver implements LintDriverInterface
{
    #[Autowired] protected ProcessInterface $process;

    #[Autowired] protected string $rubyBinary = 'ruby';

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        unset($deep);
        $syntax = $this->rubyCheck($path, $shouldAbort);

        return [
            'path' => $path,
            'tools' => [['name' => 'ruby -c', 'ok' => $syntax === []]],
            'diagnostics' => $syntax,
        ];
    }

    /**
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function rubyCheck(string $path, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$this->rubyBinary, '-c', $path],
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

        $message = $blob !== '' ? $blob : 'ruby -c failed';
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return [[
            'line' => $line,
            'severity' => 'error',
            'message' => $message,
        ]];
    }
}
