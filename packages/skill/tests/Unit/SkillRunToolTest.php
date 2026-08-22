<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Skill\Skill;
use Switon\Skill\Skills;
use Switon\Skill\Tool\SkillRunTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;
use Switon\Tooling\ProcessInterface;

use function file_put_contents;
use function mkdir;
use function rmdir;
use function scandir;
use function unlink;

use const PHP_BINARY;

#[CoversClass(SkillRunTool::class)]
class SkillRunToolTest extends TestCase
{
    protected string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }

        parent::tearDown();
    }

    public function testSchemaIsCachedAndDisabledToolRejects(): void
    {
        $tool = $this->harness();
        $this->assertSame($tool->schema('skill_run'), $tool->schema('skill_run'));

        $disabled = $this->harness(enabled: false);
        $this->assertNull($disabled->schema('skill_run'));

        $this->expectException(Exception::class);
        $disabled->call($this->agent(), new ToolCall(id: 'off', name: 'skill_run'), ['id' => 'demo', 'script' => 'hello.php']);
    }

    public function testRunsPhpScript(): void
    {
        $dir = $this->skillDir();
        file_put_contents($dir . '/scripts/hello.php', "<?php\nfwrite(STDOUT, 'hello:' . (\$argv[1] ?? '') . \"\\n\");\n");
        $tool = $this->make(SkillRunTool::class, ['skills' => $this->catalog($dir)]);

        $result = $tool->call(
            $this->agent(),
            new ToolCall(id: 'run', name: 'skill_run'),
            ['id' => 'demo', 'script' => 'hello.php', 'argv' => ['world']],
        );

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('hello:world', $result['stdout']);
    }

    #[DataProvider('interpreters')]
    public function testCommandPicksTheInterpreter(string $script, ?string $program): void
    {
        $dir = $this->skillDir();
        file_put_contents($dir . '/scripts/' . $script, "echo ok\n");
        $tool = $this->harness($this->catalog($dir));

        $tool->call($this->agent(), new ToolCall(id: 'cmd', name: 'skill_run'), ['id' => 'demo', 'script' => $script]);

        $scriptPath = realpath($dir . '/scripts/' . $script);
        $this->assertNotFalse($scriptPath);
        $this->assertSame($program === null ? [$scriptPath] : [$program, $scriptPath], $tool->command);
        $this->assertSame(sys_get_temp_dir(), $tool->cwd);
    }

    public function testMissingArgvIsAnEmptyList(): void
    {
        $dir = $this->skillDir();
        file_put_contents($dir . '/scripts/hello.php', "<?php\n");
        $tool = $this->harness($this->catalog($dir));

        $tool->call($this->agent(), new ToolCall(id: 'argv', name: 'skill_run'), ['id' => 'demo', 'script' => 'hello.php']);

        $this->assertSame([PHP_BINARY, realpath($dir . '/scripts/hello.php')], $tool->command);
    }

    public function testRejectsIdAndScriptBeforeStarting(): void
    {
        $dir = $this->skillDir();
        file_put_contents($dir . '/scripts/hello.php', "<?php\n");
        $limited = $this->harness($this->catalog($dir), allowedSkills: ['other']);

        try {
            $limited->call($this->agent(), new ToolCall(name: 'skill_run'), ['id' => 'demo', 'script' => 'hello.php']);
            $this->fail('whitelist should reject');
        } catch (ToolInvalidArgumentException) {
        }

        $tool = $this->harness($this->catalog($dir));
        foreach (['missing', 'demo'] as $id) {
            $script = $id === 'demo' ? '../SKILL.md' : 'hello.php';
            try {
                $tool->call($this->agent(), new ToolCall(name: 'skill_run'), ['id' => $id, 'script' => $script]);
                $this->fail($id . ' should reject');
            } catch (ToolInvalidArgumentException) {
            }
        }

        $this->assertSame([], $tool->command);
    }

    public function testRejectsMissingRootScriptsOrFile(): void
    {
        $gone = $this->harness($this->catalog($this->tempRoot() . '/missing-root'));
        $this->expectException(ToolInvalidArgumentException::class);
        $gone->call($this->agent(), new ToolCall(name: 'skill_run'), ['id' => 'demo', 'script' => 'hello.php']);
    }

    public function testRejectsSkillWithoutScriptsDirectory(): void
    {
        $dir = $this->skillDir(scripts: false);
        $tool = $this->harness($this->catalog($dir));

        $this->expectException(ToolInvalidArgumentException::class);
        $tool->call($this->agent(), new ToolCall(name: 'skill_run'), ['id' => 'demo', 'script' => 'hello.php']);
    }

    public function testRejectsMissingScriptAndDirectoryScript(): void
    {
        $dir = $this->skillDir();
        mkdir($dir . '/scripts/nested');
        $tool = $this->harness($this->catalog($dir));

        try {
            $tool->call($this->agent(), new ToolCall(name: 'skill_run'), ['id' => 'demo', 'script' => 'missing.php']);
            $this->fail('missing script should reject');
        } catch (ToolInvalidArgumentException) {
        }

        $this->expectException(ToolInvalidArgumentException::class);
        $tool->call($this->agent(), new ToolCall(name: 'skill_run'), ['id' => 'demo', 'script' => 'nested']);
    }

    /** @return list<array{string, string|null}> */
    public static function interpreters(): array
    {
        return [
            ['hello.py', 'python3'],
            ['hello.sh', 'bash'],
            ['hello.bash', 'bash'],
            ['hello.js', 'node'],
            ['hello.mjs', 'node'],
            ['hello.txt', null],
        ];
    }

    protected function agent(): AgentInterface
    {
        $agent = $this->createStub(AgentInterface::class);
        $agent->method('workspace')->willReturn(sys_get_temp_dir());
        $agent->method('thread')->willReturn(Thread::of('skill-run'));
        $agent->method('isCancelled')->willReturn(false);

        return $agent;
    }

    protected function catalog(string $dir): Skills
    {
        $skills = new Skills();
        $skills->set(Skill::of([
            'id' => 'demo',
            'description' => 'Demo',
            'instructions' => 'Run.',
            'path' => $dir,
        ]));

        return $skills;
    }

    protected function harness(?Skills $skills = null, bool $enabled = true, array $allowedSkills = []): SkillRunToolHarness
    {
        return $this->make(SkillRunToolHarness::class, [
            'skills' => $skills ?? new Skills(),
            'enabled' => $enabled,
            'allowedSkills' => $allowedSkills,
        ]);
    }

    protected function skillDir(bool $scripts = true): string
    {
        $dir = $this->tempRoot() . '/demo';
        mkdir($scripts ? $dir . '/scripts' : $dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\nid: demo\n---\nRun.\n");

        return $dir;
    }

    protected function tempRoot(): string
    {
        if ($this->root === '') {
            $this->root = sys_get_temp_dir() . '/switon-skill-run-' . bin2hex(random_bytes(4));
            mkdir($this->root, 0777, true);
        }

        return $this->root;
    }

    protected function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

class SkillRunToolHarness extends SkillRunTool
{
    /** @var list<string> */
    public array $command = [];

    public string $cwd = '';

    protected function process(AgentInterface $agent, ToolCall $call): ProcessInterface
    {
        return new class ($this) implements ProcessInterface {
            public function __construct(protected SkillRunToolHarness $harness)
            {
            }

            public function start(array $command, string $cwd, int $timeout): static
            {
                $this->harness->command = $command;
                $this->harness->cwd = $cwd;

                return $this;
            }

            public function output(ToolResultDelta $delta, ?string $pattern = null, ?callable $halt = null): array
            {
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }

            public function lines(ToolResultDelta $delta, int $maxLines): array
            {
                return ['exit' => 0, 'lines' => []];
            }

            public function timeout(): int
            {
                return 1;
            }

            public function ceilingBytes(): int
            {
                return 1;
            }

            public function wait(float $seconds): void
            {
            }
        };
    }
}
