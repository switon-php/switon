<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolkitInterface;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use Switon\Core\Path;

use function explode;
use function getenv;
use function is_executable;
use function is_file;
use function preg_match;

use const PHP_OS_FAMILY;

/**
 * Catch-all toolkit. An unregistered id runs the program of that name, argv only.
 *
 * Guidance: a name already on {@see Tools} is not claimed; an id containing `_` is claimed only from `$commands`, as the whole id.
 */
class ExecToolkit implements ToolkitInterface
{
    #[Autowired] protected MakerInterface $maker;

    /**
     * Allowlist of exact tool ids; each entry is also argv[0].
     *
     * @var list<string>
     */
    #[Autowired] protected array $commands = [];

    /**
     * @var array<string, list<string>>
     */
    protected array $executables = [];

    /** Shared argv schema for this instance. `$handler` is this toolkit. */
    protected ?ToolSchema $schema = null;

    /**
     * Claim `$name` from PATH or the `$commands` allowlist. `null` = not this toolkit.
     */
    public function schema(string $name): ?ToolSchema
    {
        if ($this->executables === []) {
            foreach ($this->commands as $name) {
                $this->executables[$name] = [$name];
            }
        }

        if (!isset($this->executables[$name])) {
            if (preg_match('/^[a-zA-Z\d-]+$/', $name) !== 1) {
                return null;
            }

            $file = PHP_OS_FAMILY === 'Windows' ? $name . '.exe' : $name;
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
                if (!Path::isAbsolute($dir)) {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $file;
                $found = PHP_OS_FAMILY === 'Windows' ? is_file($full) : (is_file($full) && is_executable($full));
                if ($found) {
                    $this->executables[$name] = [$full];
                    break;
                }
            }

            if (!isset($this->executables[$name])) {
                return null;
            }
        }

        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Run the program named by this tool id. Unlike `bash`, `argv` only — no shell line, pipes, or operators.',
            properties: [
                'argv' => [
                    'type' => 'array',
                    'description' => 'Arguments after the executable (subcommand, flags, operands).',
                    'items' => ['type' => 'string'],
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => 'Absolute directory. Defaults to workspace, else the system temp directory.',
                ],
            ],
            required: ['argv'],
        );
    }

    /**
     * @param array{argv: list<string>, cwd?: string} $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $name = $call->name;
        $argv = [...$this->executables[$name], ...$arguments['argv']];
        $cwd = $arguments['cwd'] ?? $agent->workspace();
        $process = $this->process($agent, $call);

        return $process->start($argv, $cwd, $process->timeout())
            ->output(new ToolResultDelta($this, $call->id, $name, $agent, ''));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return false;
    }

    public function reload(): void
    {
    }

    protected function process(AgentInterface $agent, ToolCall $call): ProcessInterface
    {
        return $this->maker->make(ProcessInterface::class, [
            'agent' => $agent,
            'call' => $call,
        ]);
    }
}
