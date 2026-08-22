<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Browser\SnapshotBuilder;

class SnapshotBuilderTest extends TestCase
{
    #[Test]
    public function buildMapsInteractiveNodesToRefs(): void
    {
        $built = SnapshotBuilder::build([
            'nodes' => [
                [
                    'nodeId' => '1',
                    'ignored' => false,
                    'role' => ['value' => 'RootWebArea'],
                    'name' => ['value' => 'Example'],
                    'childIds' => ['2'],
                ],
                [
                    'nodeId' => '2',
                    'ignored' => false,
                    'role' => ['value' => 'link'],
                    'name' => ['value' => 'More information'],
                    'backendDOMNodeId' => 42,
                    'childIds' => [],
                ],
                [
                    'nodeId' => '3',
                    'ignored' => false,
                    'role' => ['value' => 'button'],
                    'name' => ['value' => 'Go'],
                    'backendDOMNodeId' => 99,
                    'childIds' => [],
                ],
            ],
        ]);

        $this->assertSame(42, $built['refs']['e1']);
        $this->assertSame(99, $built['refs']['e2']);
        $this->assertStringContainsString('title "Example"', $built['text']);
        $this->assertStringContainsString('[e1] link "More information"', $built['text']);
        $this->assertStringContainsString('[e2] button "Go"', $built['text']);
    }

    #[Test]
    public function buildShowsParagraphWithoutARef(): void
    {
        $built = SnapshotBuilder::build([
            'nodes' => [
                [
                    'nodeId' => '1',
                    'role' => ['value' => 'paragraph'],
                    'name' => ['value' => 'Hello'],
                    'backendDOMNodeId' => 1,
                ],
            ],
        ]);

        $this->assertSame([], $built['refs']);
        $this->assertSame('paragraph "Hello"', $built['text']);
    }

    #[Test]
    public function buildReadsStaticTextOutsideControls(): void
    {
        $built = SnapshotBuilder::build([
            'nodes' => [
                [
                    'nodeId' => 'b',
                    'role' => ['value' => 'button'],
                    'name' => ['value' => 'Go'],
                    'backendDOMNodeId' => 2,
                    'childIds' => ['s'],
                ],
                [
                    'nodeId' => 's',
                    'role' => ['value' => 'StaticText'],
                    'name' => ['value' => 'Go'],
                    'backendDOMNodeId' => 3,
                ],
                [
                    'nodeId' => 'p',
                    'role' => ['value' => 'paragraph'],
                    'name' => ['value' => ''],
                    'backendDOMNodeId' => 5,
                    'childIds' => ['t'],
                ],
                [
                    'nodeId' => 't',
                    'role' => ['value' => 'StaticText'],
                    'name' => ['value' => '我在，工作区仍为空。'],
                    'backendDOMNodeId' => 4,
                ],
            ],
        ]);

        $this->assertSame(['e1' => 2], $built['refs']);
        $this->assertStringContainsString('[e1] button "Go"', $built['text']);
        $this->assertStringNotContainsString('text "Go"', $built['text']);
        $this->assertStringContainsString('text "我在，工作区仍为空。"', $built['text']);
    }

    #[Test]
    public function buildReturnsAnEmptyPageWhenNothingIsReadable(): void
    {
        $built = SnapshotBuilder::build([
            'nodes' => [
                ['nodeId' => '1', 'role' => ['value' => 'RootWebArea']],
                ['not-a-node'],
                ['nodeId' => '', 'role' => ['value' => 'button']],
                [
                    'nodeId' => '2',
                    'role' => ['value' => 'button'],
                    'name' => ['value' => 'Ghost'],
                    'childIds' => 'not-a-list',
                ],
            ],
        ]);

        $this->assertSame('(empty page)', $built['text']);
        $this->assertSame([], $built['refs']);
    }

    #[Test]
    public function buildKeepsADistinctValueAndDropsOwnedCopies(): void
    {
        $built = SnapshotBuilder::build([
            'nodes' => [
                [
                    'nodeId' => 'b',
                    'role' => ['value' => 'button'],
                    'name' => ['value' => 'Same'],
                    'backendDOMNodeId' => 4,
                    'childIds' => ['t'],
                ],
                [
                    'nodeId' => 't',
                    'role' => ['value' => 'StaticText'],
                    'name' => ['value' => 'Same'],
                    'backendDOMNodeId' => 6,
                ],
                [
                    'nodeId' => 'i',
                    'role' => ['value' => 'textbox'],
                    'name' => ['value' => 'Email'],
                    'value' => ['value' => 'ada@example.com'],
                    'backendDOMNodeId' => 5,
                ],
            ],
        ]);

        $this->assertStringContainsString('textbox "Email" value="ada@example.com"', $built['text']);
        $this->assertStringNotContainsString('text "Same"', $built['text']);
    }

    #[Test]
    public function buildTreatsANodeWithoutABoxAsVisibleAndShortensUtf8(): void
    {
        $long = str_repeat('字', 50);
        $built = SnapshotBuilder::build(
            [
                'nodes' => [
                    [
                        'nodeId' => '1',
                        'role' => 'button',
                        'name' => $long,
                        'backendDOMNodeId' => 3,
                    ],
                    [
                        'nodeId' => '2',
                        'role' => ['value' => 7],
                        'name' => ['value' => null],
                    ],
                ],
            ],
            [9 => ['x' => 0.0, 'y' => 0.0, 'w' => 10.0, 'h' => 10.0]],
            600,
        );

        $this->assertStringContainsString('[e1] button "', $built['text']);
        $this->assertStringContainsString('…', $built['text']);
    }

    #[Test]
    public function buildSaysWhenTheTreeIsMissing(): void
    {
        $this->assertSame('(empty accessibility tree)', SnapshotBuilder::build([])['text']);
    }

    #[Test]
    public function buildSkipsIgnoredBrokenAndOwnedNodes(): void
    {
        $built = SnapshotBuilder::build(
            [
                'nodes' => [
                    'not-a-node',
                    [
                        'nodeId' => 'orphan',
                        'role' => ['value' => 'StaticText'],
                        'name' => ['value' => 'Orphan'],
                        'parentId' => 'missing',
                    ],
                    [
                        'nodeId' => 'h',
                        'role' => ['value' => 'heading'],
                        'name' => ['value' => 'Dup'],
                        'childIds' => ['st'],
                    ],
                    [
                        'nodeId' => 'st',
                        'role' => ['value' => 'StaticText'],
                        'name' => ['value' => 'Dup'],
                    ],
                    [
                        'nodeId' => 'ign',
                        'ignored' => true,
                        'role' => ['value' => 'button'],
                        'name' => ['value' => 'Nope'],
                        'backendDOMNodeId' => 1,
                    ],
                    [
                        'nodeId' => 'p',
                        'role' => ['value' => 'paragraph'],
                        'name' => ['value' => 'Visible anyway'],
                        'backendDOMNodeId' => '3',
                    ],
                ],
            ],
            [3 => ['x' => 0.0, 'y' => 0.0, 'w' => 10.0, 'h' => 10.0]],
            600,
        );

        $this->assertStringContainsString('text "Orphan"', $built['text']);
        $this->assertStringNotContainsString('text "Dup"', $built['text']);
        $this->assertStringNotContainsString('Nope', $built['text']);
        $this->assertStringContainsString('paragraph "Visible anyway"', $built['text']);
    }
}
