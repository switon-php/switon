<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\EnvironmentVariables;
use Switon\Agency\PromptRenderer;
use Switon\Ai\AgentInterface;
use Switon\Ai\Exception;
use Switon\Ai\PromptVariablesInterface;
use Switon\Agency\Tests\AgentContainerTestCase;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;

#[CoversClass(PromptRenderer::class)]
class PromptRendererTest extends AgentContainerTestCase
{
    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $prompt, array $data = []): string
    {
        $env = $this->container->get(EnvironmentVariables::class);
        $renderer = $this->container->make(PromptRenderer::class);
        $renderer->registerVariables($env);
        if ($data !== []) {
            $renderer->registerVariables($this->fixed($data));
        }

        return $renderer->render($this->createStub(AgentInterface::class), $prompt, [], $data);
    }

    /**
     * @param list<string> $prompts
     * @param array<string, mixed> $data
     */
    protected function renderFiles(array $prompts, array $data = [], string $prompt = ''): string
    {
        $filesystem = $this->container->get(FilesystemInterface::class);
        $parts = [];
        foreach ($prompts as $path) {
            $parts[] = $filesystem->read($path);
        }
        if ($prompt !== '') {
            $parts[] = $prompt;
        }

        return $this->render(implode("\n\n", $parts), $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function fixed(array $data): PromptVariablesInterface
    {
        return new class ($data) implements PromptVariablesInterface {
            /** @param array<string, mixed> $data */
            public function __construct(private array $data)
            {
            }

            public function variables(AgentInterface $agent): array
            {
                unset($agent);

                return $this->data;
            }
        };
    }

    protected function bindAlias(string $alias, string $path): void
    {
        $this->container->get(PathAliasInterface::class)->set($alias, $path);
    }

    public function testLaterVariableSourceOverridesTheSameKey(): void
    {
        $renderer = $this->container->make(PromptRenderer::class);
        $renderer->registerVariables($this->fixed([
            'app.name' => 'DefaultApp',
            'greeting' => 'Hi',
        ]));
        $renderer->registerVariables($this->fixed([
            'greeting' => 'Hey',
        ]));

        $this->assertSame(
            'Hey DefaultApp',
            $renderer->render($this->createStub(AgentInterface::class), '{{ greeting }} {{ app.name }}'),
        );
    }

    public function testVariablesAreReadOnEveryRender(): void
    {
        $renderer = $this->container->make(PromptRenderer::class);
        $renderer->registerVariables(new class implements PromptVariablesInterface {
            private int $calls = 0;

            public function variables(AgentInterface $agent): array
            {
                unset($agent);
                $this->calls++;

                return ['n' => (string) $this->calls];
            }
        });

        $agent = $this->createStub(AgentInterface::class);
        $this->assertSame('1', $renderer->render($agent, '{{ n }}'));
        $this->assertSame('2', $renderer->render($agent, '{{ n }}'));
    }

    public function testLookupPrefersExactKeyThenNested(): void
    {
        $this->assertSame('flat', $this->render('{{ app.name }}', [
            'app.name' => 'flat',
            'app' => ['name' => 'nested'],
        ]));
        $this->assertSame('nested', $this->render('{{ app.name }}', [
            'app' => ['name' => 'nested'],
        ]));
        $this->assertSame('mid-flat', $this->render('{{ app.os.name }}', [
            'app' => [
                'os.name' => 'mid-flat',
                'os' => ['name' => 'deep'],
            ],
        ]));
    }

    public function testSubstitutesDotPathsAndScalars(): void
    {
        $rendered = $this->render(
            'app={{app.name}} count={{count}} flag={{flag}}',
            [
                'app' => ['name' => 'Demo'],
                'count' => 3,
                'flag' => true,
            ],
        );

        $this->assertSame('app=Demo count=3 flag=1', $rendered);
    }

    public function testLeavesUnknownPlaceholdersUnchanged(): void
    {
        $rendered = $this->render(
            'app={{app.name}} missing={{missing}}',
            ['app' => ['name' => 'Demo']],
        );

        $this->assertSame('app=Demo missing={{missing}}', $rendered);
    }

    public function testJsonExamplesUnchanged(): void
    {
        $template = 'Example: {"name": "value", "items": [1, 2]}';

        $this->assertSame($template, $this->render($template));
    }

    public function testIfTruthyOnTopLevelVar(): void
    {
        $template = '{% if show %}visible{% endif %}';

        $this->assertSame('visible', $this->render($template, ['show' => true]));
        $this->assertSame('', $this->render($template, ['show' => false]));
        $this->assertSame('', $this->render($template));
    }

    public function testIfTruthyOnDotPath(): void
    {
        $template = '{% if user.active %}yes{% endif %}';

        $this->assertSame('yes', $this->render($template, ['user' => ['active' => 1]]));
        $this->assertSame('', $this->render($template, ['user' => ['active' => 0]]));
        $this->assertSame('', $this->render($template, ['user' => []]));
    }

    public function testForIteratesArrayFromData(): void
    {
        $template = '{% for item in items %}[{{ item }}]{% endfor %}';

        $this->assertSame('[a][b]', $this->render($template, ['items' => ['a', 'b']]));
        $this->assertSame('', $this->render($template, ['items' => []]));
        $this->assertSame('', $this->render($template, ['items' => 'not-array']));
    }

    public function testForUsesDotPathList(): void
    {
        $template = '{% for name in user.tags %}{{ name }} {% endfor %}';

        $this->assertSame('go php', $this->render($template, [
            'user' => ['tags' => ['go', 'php']],
        ]));
    }

    public function testNestedIfAndFor(): void
    {
        $template = '{% if enabled %}{% for item in items %}{{ item.name }}:{{ item.value }};{% endfor %}{% endif %}';

        $rendered = $this->render($template, [
            'enabled' => true,
            'items' => [
                ['name' => 'a', 'value' => 1],
                ['name' => 'b', 'value' => 2],
            ],
        ]);

        $this->assertSame('a:1;b:2;', $rendered);
    }

    public function testUnclosedIfThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unclosed {% if %} block.');

        $this->render('{% if show %}missing endif');
    }

    public function testUnknownBlockTagThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown template tag {% else %}.');

        $this->render('{% else %}');
    }

    public function testUnexpectedEndifThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unexpected {% endif %} without matching opener.');

        $this->render('{% endif %}');
    }

    public function testRenderLoadsAliasPath(): void
    {
        $dir = sys_get_temp_dir() . '/switon-prompt-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/hello.md', 'Hi {{ name }}');
        $this->bindAlias('@prompt.test', $dir);

        try {
            $this->assertSame('Hi Ada', $this->renderFiles(['@prompt.test/hello.md'], ['name' => 'Ada']));
            $this->assertSame(
                "Hi Ada\n\nHi Ada",
                $this->renderFiles(['@prompt.test/hello.md', '@prompt.test/hello.md'], ['name' => 'Ada']),
            );
        } finally {
            unlink($dir . '/hello.md');
            rmdir($dir);
        }
    }

    public function testMissingAliasFileThrows(): void
    {
        $dir = sys_get_temp_dir() . '/switon-prompt-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->bindAlias('@prompt.test', $dir);

        try {
            $this->expectException(FileNotFoundException::class);
            $this->expectExceptionMessage('not found or unreadable');
            $this->renderFiles(['@prompt.test/missing.md']);
        } finally {
            rmdir($dir);
        }
    }

    public function testIfInList(): void
    {
        $template = '{% if echo in intrinsics %}yes{% endif %}';

        $this->assertSame('yes', $this->render($template, ['intrinsics' => ['echo', 'todo_create']]));
        $this->assertSame('', $this->render($template, ['intrinsics' => ['todo_create']]));
        $this->assertSame('', $this->render($template, []));

        $profileTemplate = '{% if agent in composer.work_agents %}work{% endif %}';
        $this->assertSame('work', $this->render($profileTemplate, [
            'agent' => 'ask',
            'composer' => ['work_agents' => ['agent', 'ask', 'plan', 'debug']],
        ]));
        $this->assertSame('', $this->render($profileTemplate, [
            'agent' => 'chat',
            'composer' => ['work_agents' => ['agent', 'ask', 'plan', 'debug']],
        ]));
    }

    public function testSkippedIfBlocksDoNotLeaveExcessBlankLines(): void
    {
        $template = <<<'TPL'
| row |

{% if agent in composer.work_agents %}
work-only
{% endif %}

{% if agent in composer.work_agents %}
more work
{% endif %}

## Style
TPL;

        $this->assertSame(
            "| row |\n\n## Style",
            $this->render($template, [
                'agent' => 'chat',
                'composer' => ['work_agents' => ['agent', 'ask', 'plan', 'debug']],
            ]),
        );
        $this->assertSame(
            "| row |\n\nwork-only\n\nmore work\n\n## Style",
            $this->render($template, [
                'agent' => 'ask',
                'composer' => ['work_agents' => ['agent', 'ask', 'plan', 'debug']],
            ]),
        );
    }

    public function testPromptAppendedAfterFiles(): void
    {
        $dir = sys_get_temp_dir() . '/switon-prompt-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/hello.md', 'Hi {{ name }}');
        $this->bindAlias('@prompt.test', $dir);

        try {
            $this->assertSame(
                "Hi Ada\n\nExtra",
                $this->renderFiles(['@prompt.test/hello.md'], ['name' => 'Ada'], 'Extra'),
            );
        } finally {
            unlink($dir . '/hello.md');
            rmdir($dir);
        }
    }

    public function testSegmentsRenderBeforePrompt(): void
    {
        $renderer = $this->container->make(PromptRenderer::class);

        $agent = $this->createStub(AgentInterface::class);
        $this->assertSame("from-segment\n\nfrom-agent", $renderer->render($agent, 'from-agent', ['from-segment']));
        $this->assertSame('from-agent', $renderer->render($agent, 'from-agent'));
    }

    public function testStringIsBodyNotFile(): void
    {
        $this->assertSame('@agency/agents/segments/x.md', $this->render('@agency/agents/segments/x.md'));
    }

    public function testCommentsAreStripped(): void
    {
        $this->assertSame(
            'hello  world',
            $this->render('hello {# inline note #} world'),
        );
        $this->assertSame(
            "before\n\nafter",
            $this->render("before\n{# multi\nline #}\nafter"),
        );
        $this->assertSame(
            'visible',
            $this->render('{# hide {% if x %}{{ x }}{% endif %} #}visible', ['x' => 'nope']),
        );
    }

    public function testUnclosedCommentThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unclosed {# comment.');

        $this->render('hello {# unfinished');
    }

    public function testTableRendersMarkdownRows(): void
    {
        $template = <<<'TPL'
{% table t in rows %}

| Profile | Description | Max steps |
|----------|-------------|-----------|
| `{{ t.id }}` | {{ t.description }} | {{ t.max_steps }} |

{% endtable %}
TPL;

        $this->assertSame(
            "| Profile | Description | Max steps |\n|----------|-------------|-----------|\n| `lite` | Lite agent. | 12 |\n| `reader` | Reads. | 8 |",
            $this->render($template, [
                'rows' => [
                    ['id' => 'lite', 'description' => 'Lite agent.', 'max_steps' => 12],
                    ['id' => 'reader', 'description' => 'Reads.', 'max_steps' => 8],
                ],
            ]),
        );
        $this->assertSame('', $this->render($template, ['rows' => []]));
    }

    public function testRenderOmitsSkillsUntilDataProvidesThem(): void
    {
        $this->assertSame('', $this->render('{% if skills %}has-skills{% endif %}'));
        $this->assertSame('has-skills', $this->render('{% if skills %}has-skills{% endif %}', [
            'skills' => [['id' => 'app-demo', 'description' => 'Demo skill.']],
        ]));
    }

    public function testTableRendersSkillRows(): void
    {
        $template = <<<'TPL'
{% table s in skills %}

| Skill | Description |
|-------|-------------|
| `{{ s.id }}` | {{ s.description }} |

{% endtable %}
TPL;

        $this->assertSame(
            "| Skill | Description |\n|-------|-------------|\n| `app-demo` | Demo skill. |",
            $this->render($template, [
                'skills' => [
                    ['id' => 'app-demo', 'description' => 'Demo skill.'],
                ],
            ]),
        );
    }

    public function testTableSynthesizesSeparatorWhenOmitted(): void
    {
        $template = <<<'TPL'
{% table e in rows %}
| H |
| {{ e }} |
{% endtable %}
TPL;

        $this->assertSame("| H |\n|---|\n| a |", $this->render($template, ['rows' => ['a']]));
    }

    public function testTableRequiresHeaderAndRow(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            '{% table %} body needs a header and row line (optional Markdown separator between them).',
        );

        $this->render("{% table e in items %}\n| only |\n{% endtable %}", [
            'items' => [['x' => 1]],
        ]);
    }

    public function testIfEmptyConditionThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('{% if %} requires a condition.');

        $this->render('{% if %}body{% endif %}');
    }

    public function testForInvalidSyntaxThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid {% for %} syntax:');

        $this->render('{% for item %}{% endfor %}');
    }

    public function testTableInvalidSyntaxThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid {% table %} syntax:');

        $this->render('{% table e %}{% endtable %}');
    }

    public function testUnclosedForThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unclosed {% for %} block.');

        $this->render('{% for item in items %}body');
    }

    public function testUnclosedTableThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unclosed {% table %} block.');

        $this->render('{% table e in items %}| H |');
    }

    public function testUnexpectedEndforThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unexpected {% endfor %} without matching opener.');

        $this->render('{% endfor %}');
    }

    public function testUnexpectedEndtableThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unexpected {% endtable %} without matching opener.');

        $this->render('{% endtable %}');
    }

    public function testIfInListFallsBackFalseWhenNotArray(): void
    {
        $template = '{% if x in list %}yes{% endif %}';

        $this->assertSame('', $this->render($template, ['list' => 'not-array']));
        $this->assertSame('', $this->render($template, ['list' => null]));
        $this->assertSame('', $this->render($template, []));
    }

    public function testForBodySeesOuterScope(): void
    {
        $template = '{% for item in items %}{{ prefix }}-{{ item }};{% endfor %}';

        $this->assertSame('P-a;P-b;', $this->render($template, [
            'prefix' => 'P',
            'items' => ['a', 'b'],
        ]));
    }

    public function testPartialDotPathStaysLiteral(): void
    {
        $this->assertSame(
            'name=Ada missing={{ user.missing }}',
            $this->render('name={{ user.name }} missing={{ user.missing }}', [
                'user' => ['name' => 'Ada'],
            ]),
        );
    }

    public function testEmptyRenderReturnsEmptyString(): void
    {
        $this->assertSame('', $this->renderFiles([], []));
    }

    public function testEnvironmentBuiltinsAvailableWithoutData(): void
    {
        $rendered = $this->render('os={{ env.os }} arch={{ env.arch }} php={{ env.php }}');

        $this->assertSame('os=' . PHP_OS_FAMILY . ' arch=' . php_uname('m') . ' php=' . PHP_VERSION, $rendered);
    }

    public function testEnvironmentBuiltinsOverridableViaFlatData(): void
    {
        $rendered = $this->render('os={{ env.os }}', ['env.os' => 'Override']);

        $this->assertSame('os=Override', $rendered);
    }
}
