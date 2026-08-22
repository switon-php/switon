<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Skill\Exception;
use Switon\Skill\Skill;
use Switon\Skill\Skills;
use Switon\Testing\TestCase;

use function rmdir;
use function scandir;
use function unlink;

#[CoversClass(Skills::class)]
class SkillsCatalogTest extends TestCase
{
    protected string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }

        parent::tearDown();
    }

    public function testGetHasAllAndBlock(): void
    {
        $skills = new Skills();
        $skills->set(Skill::of([
            'id' => 'demo',
            'description' => 'Demo',
            'instructions' => 'Do the thing.',
            'path' => '/skills/demo',
        ]));

        $this->assertTrue($skills->has('demo'));
        $this->assertFalse($skills->has('missing'));
        $this->assertSame('demo', $skills->get('demo')->id);
        $this->assertSame(['demo' => $skills->get('demo')], $skills->all());
        $this->assertSame(
            "[skill:demo]\n\nSkill path: /skills/demo\n\nDo the thing.",
            $skills->block('demo'),
        );
        $this->assertSame("[skill:missing]\n\nUnknown skill id: missing", $skills->block('missing'));

        $this->expectException(Exception::class);
        $skills->get('missing');
    }

    public function testLoadReadsFrontmatterAndBasenameId(): void
    {
        $this->root = $this->tempRoot();
        $this->writeSkill($this->root . '/named', "id: named\ndescription: Named\n", 'Named body.');
        $this->writeSkill($this->root . '/basename-id/', "description: From the directory\n", 'Basename body.');
        $this->writeSkill($this->root . '/bare', "id: bare\n", 'Bare body.');
        mkdir($this->root . '/plain', 0777, true);
        file_put_contents($this->root . '/plain/SKILL.md', "# No frontmatter\n");

        $skills = $this->make(Skills::class);
        $named = $skills->load($this->root . '/named/');
        $fromDir = $skills->load($this->root . '/basename-id/');
        $bare = $skills->load($this->root . '/bare');

        $this->assertNotNull($named);
        $this->assertSame('named', $named->id);
        $this->assertSame('Named', $named->description);
        $this->assertSame('Named body.', $named->instructions);
        $this->assertSame($this->root . '/named', $named->path);
        $this->assertNotNull($fromDir);
        $this->assertSame('basename-id', $fromDir->id);
        $this->assertSame('', $bare?->description);
        $this->assertNull($skills->load($this->root . '/plain'));
    }

    public function testReloadMergesAndKeepsRuntimeRows(): void
    {
        $this->root = $this->tempRoot();
        $first = $this->root . '/first';
        $second = $this->root . '/second';
        $this->writeSkill($first . '/demo', "id: demo\ndescription: First\n", 'First body.');
        $this->writeSkill($first . '/only-slash', "id: only-slash\ndescription: Slash\n", 'Slash body.');
        $this->writeSkill($second . '/demo', "id: demo\ndescription: Second\n", 'Second body.');
        $this->writeSkill($second . '/extra', "id: extra\ndescription: Extra\n", 'Extra body.');
        mkdir($second . '/plain', 0777, true);
        file_put_contents($second . '/plain/SKILL.md', "# No frontmatter\n");

        $skills = $this->make(Skills::class, ['paths' => [$first . '/', $second]]);
        $skills->set(Skill::of(['id' => 'runtime', 'description' => 'Kept', 'instructions' => 'Stay.']));
        $skills->reload();

        $this->assertSame('Second body.', $skills->get('demo')->instructions);
        $this->assertTrue($skills->has('extra'));
        $this->assertSame('Stay.', $skills->get('runtime')->instructions);
        $this->assertFalse($skills->has('plain'));
        $this->assertSame('Slash body.', $skills->get('only-slash')->instructions);
    }

    public function testListMatchesIdOrDescription(): void
    {
        $skills = new Skills();
        $skills->set(Skill::of(['id' => 'beta', 'description' => 'Writes PDF.']));
        $skills->set(Skill::of(['id' => 'alpha', 'description' => 'Alpha.']));

        $this->assertSame([
            ['id' => 'alpha', 'description' => 'Alpha.'],
            ['id' => 'beta', 'description' => 'Writes PDF.'],
        ], $skills->list());
        $this->assertSame([
            ['id' => 'beta', 'description' => 'Writes PDF.'],
        ], $skills->list(['pdf']));
        $this->assertSame([
            ['id' => 'alpha', 'description' => 'Alpha.'],
        ], $skills->list(['ALPHA']));
        $this->assertSame([], $skills->list(['missing']));
    }

    /**
     * A hand-written SKILL.md is user input: a YAML number or array id must not reach the string
     * constructor, and a blank id must not register a row nothing can address.
     */
    public function testLoadFallsBackToDirectoryNameForUnusableId(): void
    {
        $this->root = $this->tempRoot();
        $this->writeSkill($this->root . '/numeric', "id: 42\ndescription: Numeric\n", 'Numeric body.');
        $this->writeSkill($this->root . '/blank', "id: ''\ndescription: Blank\n", 'Blank body.');
        $this->writeSkill($this->root . '/list-id', "id: [a, b]\ndescription: List\n", 'List body.');

        $skills = $this->make(Skills::class);

        $this->assertSame('numeric', $skills->load($this->root . '/numeric')?->id);
        $this->assertSame('blank', $skills->load($this->root . '/blank')?->id);
        $this->assertSame('list-id', $skills->load($this->root . '/list-id')?->id);
    }

    /** Non-string descriptions are dropped to an empty string instead of raising a TypeError. */
    public function testLoadCoercesNonStringDescription(): void
    {
        $this->root = $this->tempRoot();
        $this->writeSkill($this->root . '/demo', "id: demo\ndescription: [a, b]\n", 'Demo body.');

        $skills = $this->make(Skills::class);

        $skill = $skills->load($this->root . '/demo');
        $this->assertNotNull($skill);
        $this->assertSame('', $skill->description);
        $this->assertSame('Demo body.', $skill->instructions);
    }

    protected function tempRoot(): string
    {
        $root = sys_get_temp_dir() . '/switon-skill-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        return $root;
    }

    protected function writeSkill(string $dir, string $frontmatter, string $body): void
    {
        mkdir($dir, 0777, true);
        file_put_contents(rtrim($dir, '/') . '/SKILL.md', "---\n{$frontmatter}---\n{$body}\n");
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
