<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Tests\Fixtures\RecordingTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolResult;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Exception as ToolingException;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\ReadTool;

use function extension_loaded;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefilledrectangle;
use function imagepng;
use function imagestring;

/**
 * Live behavior checks for {@see ReadTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter ReadToolLiveTest
 */
#[CoversClass(ReadTool::class)]
class ReadToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected ReadTool $fileRead;

    #[Autowired] protected BashTool $bash;

    public function testReadImageReturnsMediaAndModelSeesLabel(): void
    {
        $this->assertLiveLlmBound();
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('gd extension required to draw PNG fixture');
        }

        $token = 'SWITON' . strtoupper(bin2hex(random_bytes(2)));
        $path = $this->writePngLabelFixture($token);
        $fileRead = $this->recording('read', $this->softRecordingInner($this->fileRead));

        $data = $this->instructor()->ask(
            $this->toolProfile(['read'], 6, 'minimax/M31F'),
            'Call read with path "' . $path . '". '
            . 'It is a PNG image with one short uppercase label on a white background. '
            . 'Report that exact label and nothing else. Do not invent content. Do not use bash. '
            . 'Only the read tool is available (no media tool). Do not fetch URLs.',
            $this->responseProperties(),
        );

        $media = null;
        foreach ($fileRead->calls as $record) {
            $result = $record['result'];
            if ($result instanceof ToolResult
                && $result->parts !== []
                && $result->parts[0] instanceof MediaPart
            ) {
                $media = $result;
                break;
            }
        }
        $this->assertInstanceOf(ToolResult::class, $media, 'expected MediaPart read; calls=' . json_encode($fileRead->calls));
        $this->assertSame('', $media->content);
        $this->assertSame($path, $media->parts[0]->ref);
        $this->assertSame('image/png', $media->parts[0]->mime);

        $answer = strtoupper((string) ($data['answer'] ?? ''));
        $this->assertStringContainsString($token, $answer, 'answer=' . ($data['answer'] ?? ''));
    }

    public function testOffsetWindowUsesFileRead(): void
    {
        $this->assertLiveLlmBound();

        $path = $this->writeLineFixture(120);
        $fileRead = $this->recording('read', $this->fileRead);
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['read', 'bash']),
            "Read only lines 80 through 100 (inclusive) of {$path}. "
            . 'Report the exact file text of line 80 only, without any L{n}: line-number prefix. Do not invent content.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $reads = $this->callsNamed($calls, 'read');
        $this->assertNotEmpty($reads, 'expected at least one read call; calls=' . json_encode($calls));

        $args = $reads[0]['arguments'];
        $this->assertSame($path, $args['path'] ?? null);
        $this->assertIsInt($args['offset'] ?? null);
        $this->assertGreaterThanOrEqual(75, $args['offset']);
        $this->assertLessThanOrEqual(85, $args['offset']);
        $this->assertIsInt($args['limit'] ?? null);
        $this->assertGreaterThanOrEqual(15, $args['limit']);
        $this->assertLessThanOrEqual(40, $args['limit']);

        $answer = (string) ($data['answer'] ?? '');
        $this->assertStringContainsString('LINE-80', $answer);
        $this->assertDoesNotMatchRegularExpression('/\bL80:\s*/', $answer, 'answer should strip L{n}: prefix; got=' . $answer);
    }

    public function testTailUsesNegativeOrNearEndOffset(): void
    {
        $this->assertLiveLlmBound();

        $path = $this->writeLineFixture(120);
        $fileRead = $this->recording('read', $this->fileRead);

        $data = $this->instructor()->ask(
            $this->toolProfile(['read']),
            "Read the last 5 lines of {$path}. "
            . 'Report the exact file text of the final line only, without any L{n}: line-number prefix. Do not invent content.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $reads = $this->callsNamed($calls, 'read');
        $this->assertNotEmpty($reads, 'expected read; calls=' . json_encode($calls));

        $args = $reads[0]['arguments'];
        $this->assertSame($path, $args['path'] ?? null);
        $offset = $args['offset'] ?? null;
        $this->assertTrue(
            (is_int($offset) && $offset < 0)
            || (is_int($offset) && $offset >= 110),
            'expected negative offset or near-end positive offset, got ' . json_encode($args),
        );

        $answer = (string) ($data['answer'] ?? '');
        $this->assertStringContainsString('LINE-120', $answer);
        $this->assertDoesNotMatchRegularExpression('/\bL120:\s*/', $answer, 'answer should strip L{n}: prefix; got=' . $answer);
    }

    protected function writePngLabelFixture(string $label): string
    {
        $dir = $this->registerFixtureDir();
        $path = $dir . '/label.png';

        $image = imagecreatetruecolor(480, 120);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 479, 119, $white);
        imagestring($image, 5, 40, 50, $label, $black);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * Like {@see ToolsLiveTestCase::recording()}, but tooling exceptions become `['error' => …]`
     * so a flaky follow-up read (wrong path) does not abort the whole instruct.
     */
    protected function softRecordingInner(ToolInterface $inner): ToolInterface
    {
        return new class ($inner) extends RecordingTool {
            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                try {
                    return parent::call($agent, $call, $arguments);
                } catch (ToolingException $e) {
                    $result = ['error' => $e->getMessage()];
                    $this->calls[] = ['seq' => ++self::$sequence, 'name' => $call->name, 'arguments' => $arguments, 'result' => $result];

                    return $result;
                }
            }
        };
    }
}
