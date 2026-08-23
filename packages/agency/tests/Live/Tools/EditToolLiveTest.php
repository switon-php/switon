<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\EditTool;
use Switon\Tooling\Tool\ReadTool;

/**
 * Live behavior checks for {@see EditTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter EditToolLiveTest
 */
#[CoversClass(EditTool::class)]
class EditToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected ReadTool $fileRead;

    #[Autowired] protected EditTool $filePatch;

    public function testReadThenPatchWithoutLinePrefixInOldString(): void
    {
        $this->assertLiveLlmBound();

        $path = $this->writeLineFixture(30);
        $fileRead = $this->recording('read', $this->fileRead);
        $filePatch = $this->recording('edit', $this->filePatch);

        $this->instructor()->ask(
            $this->toolProfile(['read', 'edit'], 10),
            "In {$path}, change the exact text of line 10 from LINE-10 to LINE-TEN. "
            . 'Use read then edit. Do not invent content.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $patches = $this->callsNamed($calls, 'edit');
        $this->assertNotEmpty($patches, 'expected edit; calls=' . json_encode($calls));

        $args = $patches[0]['arguments'];
        $this->assertSame($path, $args['path'] ?? null);

        $old = (string) ($args['old'] ?? '');
        $new = (string) ($args['new'] ?? '');
        $this->assertStringContainsString('LINE-10', $old);
        $this->assertDoesNotMatchRegularExpression('/\bL\d+:\s*/', $old, 'old must not include L{n}:; got=' . json_encode($old));
        $this->assertDoesNotMatchRegularExpression('/\bL\d+:\s*/', $new, 'new must not include L{n}:; got=' . json_encode($new));
        $this->assertStringContainsString('LINE-TEN', $new);

        $this->assertStringContainsString('LINE-TEN', (string) file_get_contents($path));
        $this->assertStringNotContainsString('LINE-10', (string) file_get_contents($path));
    }
}
