<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Ai\Profile;

#[CoversClass(Profile::class)]
class ProfileTest extends TestCase
{
    public function testDecodeMapsPromptAndTools(): void
    {
        $definition = Profile::decode([
            'id' => 'x',
            'prompt' => 'Hello.',
            'tools' => ['bash', 'read'],
        ]);

        $this->assertSame('Hello.', $definition->prompt);
        $this->assertSame(['bash', 'read'], $definition->tools);
    }

    public function testAsToolDefaultsEmpty(): void
    {
        $definition = Profile::decode(['id' => 'x', 'description' => 'y']);

        $this->assertSame('', $definition->asTool);
    }

    public function testAsToolParsesString(): void
    {
        $definition = Profile::decode(['id' => 'file-researcher', 'as_tool' => 'agent_file_research']);
        $this->assertSame('agent_file_research', $definition->asTool);
    }

    public function testSelectableDefaultsTrueForHosts(): void
    {
        $definition = Profile::decode(['id' => 'x', 'description' => 'y']);

        $this->assertTrue($definition->selectable);
    }

    public function testOrderDefaultsZeroAndParsesInt(): void
    {
        $this->assertSame(0, Profile::decode(['id' => 'x'])->order);
        $this->assertSame(40, Profile::decode(['id' => 'x', 'order' => 40])->order);
        $this->assertSame(-5, Profile::decode(['id' => 'x', 'order' => -5])->order);
    }

    public function testSelectableParsesBool(): void
    {
        $this->assertFalse(Profile::decode(['id' => 'x', 'selectable' => false])->selectable);
        $this->assertTrue(Profile::decode(['id' => 'x', 'selectable' => true])->selectable);
    }

    public function testNativeCtorSelectableMatchesOf(): void
    {
        $this->assertTrue((new Profile())->selectable);
        $this->assertTrue((new Profile(asTool: 'agent_x'))->selectable);
        $this->assertTrue((new Profile(asTool: 'agent_x', selectable: true))->selectable);
        $this->assertFalse((new Profile(selectable: false))->selectable);
    }
}
