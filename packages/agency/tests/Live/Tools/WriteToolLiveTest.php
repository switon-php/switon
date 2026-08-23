<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Tool\WriteTool;

/**
 * Live behavior checks for {@see WriteTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter WriteToolLiveTest
 */
#[CoversClass(WriteTool::class)]
class WriteToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected WriteTool $fileWrite;

    public function testCreatesNewFileWithExactContents(): void
    {
        $this->assertLiveLlmBound();

        $dir = $this->registerFixtureDir();
        $path = $dir . '/created.txt';
        $fileWrite = $this->recording('write', $this->fileWrite);
        $marker = 'SWITON_WRITE_' . strtoupper(bin2hex(random_bytes(3)));

        $this->instructor()->ask(
            $this->toolProfile(['write'], 6),
            "Create the file {$path} with exactly one line of text: {$marker}. "
            . 'Use write. Do not invent a different path.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $writes = $this->callsNamed($calls, 'write');
        $this->assertNotEmpty($writes, 'expected write; calls=' . json_encode($calls));

        $args = $writes[0]['arguments'];
        $this->assertSame($path, $args['path'] ?? null);
        $this->assertStringContainsString($marker, (string) ($args['content'] ?? ''));
        $this->assertFileExists($path);
        $this->assertStringContainsString($marker, (string) file_get_contents($path));
    }
}
