<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use Switon\Core\Attribute\Autowired;
use Switon\Lint\ProcessInterface;

/**
 * Python: syntax-only via `ast.parse` (no bytecode, no deep analyzers). Wired for `py`.
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class PythonLintDriver implements LintDriverInterface
{
    protected const PARSE_SCRIPT = 'import ast,sys; p=sys.argv[1]; ast.parse(open(p,encoding="utf-8").read(), filename=p)';

    #[Autowired] protected ProcessInterface $process;

    #[Autowired] protected string $pythonBinary = 'python3';

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        unset($deep); // Syntax-only; analyzers stay out of the default/agent path.

        $syntax = $this->astParse($path, $shouldAbort);

        return [
            'path' => $path,
            'tools' => [['name' => 'python', 'ok' => $syntax === []]],
            'diagnostics' => $syntax,
        ];
    }

    /**
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function astParse(string $path, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$this->pythonBinary, '-c', self::PARSE_SCRIPT, $path],
            $shouldAbort,
            null
        );

        if (($result['exit'] ?? 1) === 0) {
            return [];
        }

        $blob = trim(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? ''));
        $line = 1;
        if (preg_match('/\bline (\d+)\b/', $blob, $m) === 1) {
            $line = max(1, (int) $m[1]);
        }

        $message = $blob;
        if (preg_match('/^(SyntaxError|IndentationError|TabError):\s*(.+)$/m', $blob, $m) === 1) {
            $message = $m[1] . ': ' . trim($m[2]);
        } elseif ($blob === '') {
            $message = 'python syntax check failed';
        }
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return [[
            'line' => $line,
            'severity' => 'error',
            'message' => $message,
        ]];
    }
}
