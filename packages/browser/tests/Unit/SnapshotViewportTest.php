<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Browser\SnapshotBuilder;
use Switon\Testing\TestCase;

use function range;
use function sprintf;
use function str_repeat;
use function substr_count;

/**
 * Snapshot ordering: what a person can see comes first.
 *
 * A long page has more elements than a snapshot can carry. Ordering by viewport keeps the useful
 * half and marks the rest, instead of cutting the list at an arbitrary line.
 */
#[CoversClass(SnapshotBuilder::class)]
class SnapshotViewportTest extends TestCase
{
    #[Test]
    public function buildMarksElementsOutsideTheViewport(): void
    {
        $built = SnapshotBuilder::build(
            $this->tree([1 => 'Save', 2 => 'Delete']),
            [1 => ['x' => 0.0, 'y' => 10.0, 'w' => 80.0, 'h' => 20.0], 2 => ['x' => 0.0, 'y' => 5000.0, 'w' => 80.0, 'h' => 20.0]],
            600,
        );

        $this->assertStringContainsString('button "Save"', $built['text']);
        $this->assertStringNotContainsString('(offscreen) button "Save"', $built['text']);
        $this->assertStringContainsString('(offscreen) button "Delete"', $built['text']);
        $this->assertSame(1, $built['offscreen']);
    }

    #[Test]
    public function buildPutsVisibleElementsBeforeOffscreenOnes(): void
    {
        // Document order is A, B, C; B is off screen. Visible ones must come first.
        $tree = $this->tree([1 => 'A', 2 => 'B', 3 => 'C']);
        $layout = [
            1 => ['x' => 0.0, 'y' => 10.0, 'w' => 50.0, 'h' => 20.0],
            2 => ['x' => 0.0, 'y' => 3000.0, 'w' => 50.0, 'h' => 20.0],
            3 => ['x' => 0.0, 'y' => 40.0, 'w' => 50.0, 'h' => 20.0],
        ];

        $built = SnapshotBuilder::build($tree, $layout, 600);
        $lines = explode("\n", $built['text']);
        $positions = [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'button "A"')) {
                $positions['A'] = $i;
            }
            if (str_contains($line, 'button "B"')) {
                $positions['B'] = $i;
            }
            if (str_contains($line, 'button "C"')) {
                $positions['C'] = $i;
            }
        }

        $this->assertLessThan($positions['B'], $positions['A'], 'a visible element must precede an offscreen one');
        $this->assertLessThan($positions['B'], $positions['C'], 'a visible element must precede an offscreen one');
    }

    #[Test]
    public function buildKeepsDocumentOrderWhenNoGeometryIsGiven(): void
    {
        $built = SnapshotBuilder::build($this->tree([1 => 'A', 2 => 'B']));

        $this->assertStringNotContainsString('(offscreen)', $built['text']);
        $this->assertSame(0, $built['offscreen']);
        $this->assertStringContainsString('button "A"', $built['text']);
    }

    #[Test]
    public function buildCountsElementsDroppedByTheLineBudget(): void
    {
        $tree = $this->tree([1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D']);
        $built = SnapshotBuilder::build($tree, [], 0, 3);

        $this->assertStringContainsString('beyond the line budget', $built['text']);
        $this->assertStringContainsString('more beyond', $built['text']);
    }

    #[Test]
    public function buildTreatsAPartlyVisibleElementAsVisible(): void
    {
        // Top edge above the viewport boundary but the box still overlaps it: a person sees part.
        $built = SnapshotBuilder::build(
            $this->tree([1 => 'Straddle']),
            [1 => ['x' => 0.0, 'y' => -10.0, 'w' => 80.0, 'h' => 40.0]],
            600,
        );

        $this->assertStringNotContainsString('(offscreen)', $built['text']);
        $this->assertSame(0, $built['offscreen']);
    }

    #[Test]
    public function buildOmitsOffscreenTextAndKeepsOffscreenControls(): void
    {
        $built = SnapshotBuilder::build([
            'nodes' => [
                ['role' => ['value' => 'RootWebArea'], 'name' => ['value' => 'Test page']],
                [
                    'role' => ['value' => 'StaticText'],
                    'name' => ['value' => 'On screen'],
                    'backendDOMNodeId' => 1,
                ],
                [
                    'role' => ['value' => 'StaticText'],
                    'name' => ['value' => 'Old reply'],
                    'backendDOMNodeId' => 2,
                ],
                [
                    'role' => ['value' => 'button'],
                    'name' => ['value' => 'Archive'],
                    'backendDOMNodeId' => 3,
                ],
            ],
        ], [
            1 => ['x' => 0.0, 'y' => 10.0, 'w' => 80.0, 'h' => 20.0],
            2 => ['x' => 0.0, 'y' => 4000.0, 'w' => 80.0, 'h' => 20.0],
            3 => ['x' => 0.0, 'y' => 4200.0, 'w' => 80.0, 'h' => 20.0],
        ], 600);

        $this->assertStringContainsString('text "On screen"', $built['text']);
        $this->assertStringNotContainsString('Old reply', $built['text']);
        $this->assertStringContainsString('(offscreen) button "Archive"', $built['text']);
        $this->assertSame(2, $built['offscreen']);
    }

    #[Test]
    public function buildFoldsProsePastTheTextBudgetButKeepsEveryControl(): void
    {
        $nodes = [
            ['role' => ['value' => 'RootWebArea'], 'name' => ['value' => 'Test page']],
        ];
        // Enough paragraphs to overshoot the text budget.
        foreach (range(1, 40) as $id) {
            $nodes[] = [
                'role' => ['value' => 'paragraph'],
                'name' => ['value' => str_repeat('Long reference sentence. ', 10)],
                'backendDOMNodeId' => $id,
            ];
        }
        $nodes[] = [
            'role' => ['value' => 'button'],
            'name' => ['value' => 'Submit'],
            'backendDOMNodeId' => 999,
        ];

        $built = SnapshotBuilder::build(['nodes' => $nodes]);

        $this->assertStringContainsString('more text lines', $built['text']);
        $this->assertStringContainsString('button "Submit"', $built['text'], 'a control is never folded away');
        $paragraphLines = substr_count($built['text'], "\nparagraph ");
        $this->assertLessThan(40, $paragraphLines, 'prose past the budget is folded');
    }

    /**
     * @param array<int, string> $names backendNodeId => accessible name
     *
     * @return array<string, mixed>
     */
    private function tree(array $names): array
    {
        $nodes = [
            ['role' => ['value' => 'RootWebArea'], 'name' => ['value' => 'Test page']],
        ];
        foreach ($names as $backendId => $name) {
            $nodes[] = [
                'role' => ['value' => 'button'],
                'name' => ['value' => $name],
                'backendDOMNodeId' => $backendId,
            ];
        }

        return ['nodes' => $nodes];
    }
}
