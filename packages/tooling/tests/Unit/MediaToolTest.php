<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolResult;
use Switon\Tooling\Exception;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\MediaTool;

#[CoversClass(MediaTool::class)]
class MediaToolTest extends ToolTestCase
{
    public function testCallEmitsMediaPartFromPathExtension(): void
    {
        $result = $this->callTool($this->make(MediaTool::class), 'media', [
            'prompt' => 'Describe the image.',
            'ref' => 'tool-asset/shot.png',
        ]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('Describe the image.', $result->content);
        $this->assertCount(1, $result->parts);
        $this->assertInstanceOf(MediaPart::class, $result->parts[0]);
        $this->assertSame('tool-asset/shot.png', $result->parts[0]->ref);
        $this->assertSame('image/png', $result->parts[0]->mime);
        $this->assertSame([], $result->parts[0]->extra);
    }

    public function testCallWritesPromptToToolResultContent(): void
    {
        $result = $this->callTool($this->make(MediaTool::class), 'media', [
            'prompt' => 'What color is the butterfly?',
            'ref' => 'tool-asset/shot.png',
        ]);

        $this->assertSame('What color is the butterfly?', $result->content);
        $this->assertSame('tool-asset/shot.png', $result->parts[0]->ref);
    }

    public function testCallTakesOnlyExtraBagNotTopLevelVendorKeys(): void
    {
        $result = $this->callTool($this->make(MediaTool::class), 'media', [
            'prompt' => 'Summarize the clip.',
            'ref' => 'tool-asset/clip.bin',
            'mime' => 'video/mp4',
            'fps' => 2,
            'extra' => ['fps' => 5, 'detail' => 'high'],
        ]);

        $this->assertInstanceOf(MediaPart::class, $result->parts[0]);
        $this->assertSame('video/mp4', $result->parts[0]->mime);
        $this->assertSame(['fps' => 5, 'detail' => 'high'], $result->parts[0]->extra);
    }

    public function testCallIgnoresNonObjectExtra(): void
    {
        $result = $this->callTool($this->make(MediaTool::class), 'media', [
            'prompt' => 'Describe.',
            'ref' => 'tool-asset/shot.png',
            'extra' => 'look for a butterfly',
        ]);

        $this->assertSame([], $result->parts[0]->extra);
    }

    public function testCallAcceptsRemoteUrl(): void
    {
        $result = $this->callTool($this->make(MediaTool::class), 'media', [
            'prompt' => 'Describe the image.',
            'ref' => 'https://cdn.example.com/a.png',
        ]);

        $this->assertSame('https://cdn.example.com/a.png', $result->parts[0]->ref);
        $this->assertSame('image/png', $result->parts[0]->mime);
    }

    public function testCallRequiresMimeWhenExtensionUnknown(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown media type');

        $this->callTool($this->make(MediaTool::class), 'media', [
            'prompt' => 'Describe the file.',
            'ref' => 'tool-asset/clip.bin',
        ]);
    }

    public function testDroppableIsTrue(): void
    {
        $this->assertTrue($this->make(MediaTool::class)->isReadonly('media', [
            'prompt' => 'Describe.',
            'ref' => 'a.png',
        ]));
    }

    public function testSchemaOmitsExtraAllowsAdditionalProperties(): void
    {
        $schema = $this->make(MediaTool::class)->schema('media');

        $this->assertSame(['prompt', 'ref'], $schema->definition['inputSchema']['required']);
        $this->assertSame(['prompt', 'ref', 'mime'], array_keys($schema->definition['inputSchema']['properties']));
        $this->assertArrayNotHasKey('additionalProperties', $schema->definition['inputSchema']);
        $this->assertArrayNotHasKey('extra', $schema->jsonSchema()['properties']);
    }
}
