<?php

declare(strict_types=1);

namespace Switon\Skill\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use Switon\Core\Path;
use Switon\Skill\SkillsInterface;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;
use Switon\Tooling\ProcessInterface;

use function basename;
use function in_array;
use function pathinfo;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;
use const PHP_BINARY;

/**
 * Tool `skill_run`. Run a file under a skill's `scripts/` by id + script name.
 *
 * Declared by the `skills` segment; host can disable via `$enabled`
 * or restrict ids via `$allowedSkills`.
 */
class SkillRunTool extends AbstractTool
{
    #[Autowired] protected SkillsInterface $skills;
    #[Autowired] protected MakerInterface $maker;

    #[Autowired] protected bool $enabled = true;
    /**
     * When non-empty, only these skill ids may run; empty = all configured skills.
     *
     * @var list<string>
     */
    #[Autowired] protected array $allowedSkills = [];

    public function schema(string $name): ?ToolSchema
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Run a script under a configured skill\'s scripts/ directory. '
            . 'Pass skill id and script basename (no path separators). Optional argv after the script. '
            . 'The working directory is the agent workspace.',
            properties: [
                'id' => [
                    'type' => 'string',
                    'description' => 'Skill id from the configured skills catalog.',
                ],
                'script' => [
                    'type' => 'string',
                    'description' => 'Basename under that skill\'s scripts/ (e.g. validate.sh, extract.py).',
                ],
                'argv' => [
                    'type' => 'array',
                    'description' => 'Arguments after the script (no shell operators).',
                    'items' => ['type' => 'string'],
                ],
            ],
            required: ['id', 'script'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        if (!$this->enabled) {
            Exception::raise('Tool "{name}" is disabled.', ['name' => $call->name]);
        }

        $skillId = $arguments['id'];
        if ($this->allowedSkills !== [] && !in_array($skillId, $this->allowedSkills, true)) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'id']);
        }

        if (!$this->skills->has($skillId)) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'id']);
        }

        $script = $arguments['script'];
        if ($script !== basename($script) || !Path::isRelative($script)) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'script']);
        }

        $skill = $this->skills->get($skillId);
        $scriptPath = $this->resolveScriptPath($skill->path, $script);
        $argv = $arguments['argv'] ?? [];

        $process = $this->process($agent, $call);

        return $process->start(
            command: $this->commandFor($scriptPath, $argv),
            cwd: $agent->workspace(),
            timeout: $process->timeout(),
        )->output(
            delta: new ToolResultDelta($this, $call->id, $call->name, $agent, ''),
        );
    }

    /**
     * @return string Absolute path to the script file under `scripts/`.
     */
    protected function resolveScriptPath(string $skillRoot, string $script): string
    {
        $root = $this->filesystem->realpath($skillRoot);
        if ($root === null) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'id']);
        }

        $scriptsDir = $root . DIRECTORY_SEPARATOR . 'scripts';
        $scriptsReal = $this->filesystem->realpath($scriptsDir);
        if ($scriptsReal === null || !$this->filesystem->isDir($scriptsReal)) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'script']);
        }

        $resolved = Path::resolve($scriptsReal, $script);
        if ($resolved === null) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'script']);
        }
        if (!$this->filesystem->isFile($resolved)) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'script']);
        }

        return $resolved;
    }

    /**
     * @param list<string> $argv
     *
     * @return list<string>
     */
    protected function commandFor(string $scriptPath, array $argv): array
    {
        $ext = strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION));

        return match ($ext) {
            'php' => [PHP_BINARY, $scriptPath, ...$argv],
            'py' => ['python3', $scriptPath, ...$argv],
            'sh', 'bash' => ['bash', $scriptPath, ...$argv],
            'js', 'mjs' => ['node', $scriptPath, ...$argv],
            default => [$scriptPath, ...$argv],
        };
    }

    protected function process(AgentInterface $agent, ToolCall $call): ProcessInterface
    {
        return $this->maker->make(ProcessInterface::class, [
            'agent' => $agent,
            'call' => $call,
        ]);
    }
}
