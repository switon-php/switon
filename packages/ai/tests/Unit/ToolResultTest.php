<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Ai\Exception\ToolAbortedException;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolTiming;
use Switon\Core\Json;

#[CoversClass(ToolResult::class)]
class ToolResultTest extends TestCase
{
    public function testOfCreatesToolResult(): void
    {
        $call = new ToolCall('call-1', 'echo');
        $result = ToolResult::of($call, 'payload');

        $this->assertSame('payload', $result->content);
        $this->assertSame('call-1', $result->id);
        $this->assertSame('echo', $result->name);
        $this->assertFalse($result->isError);
    }

    public function testStructuredContentStaysMixed(): void
    {
        $result = new ToolResult(['status' => 'done'], 'call-1', 'todo_step');

        $this->assertSame(['status' => 'done'], $result->content);
        $this->assertSame('{"status":"done"}', Json::text($result->content));
    }

    public function testJsonTextUsesStringifyDefaults(): void
    {
        $result = new ToolResult(['path' => '/tmp/a'], 'call-1', 'bash');

        $this->assertSame('{"path":"/tmp/a"}', Json::text($result->content));
    }

    public function testFailureStoresClassMessageAndIsError(): void
    {
        $result = ToolResult::of(
            new ToolCall('call-1', 'pay'),
            ToolAbortedException::of('timeout'),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            ['class' => ToolAbortedException::class, 'message' => 'timeout'],
            $result->wire(),
        );
        $this->assertSame(
            ['class' => ToolAbortedException::class, 'message' => 'timeout'],
            $result->jsonSerialize()['content'],
        );

        $decoded = ToolResult::decode(Json::parse(Json::stringify($result)));

        $this->assertTrue($decoded->isError);
        $this->assertSame(
            ['class' => ToolAbortedException::class, 'message' => 'timeout'],
            $decoded->content,
        );
        $this->assertSame($decoded->content, $decoded->wire());
    }

    public function testNativeExceptionStoresClassAndMessage(): void
    {
        $result = ToolResult::of(new ToolCall('call-1', 'echo'), new RuntimeException('native down'));

        $decoded = ToolResult::decode(Json::parse(Json::stringify($result)));

        $this->assertTrue($decoded->isError);
        $this->assertSame(
            ['class' => RuntimeException::class, 'message' => 'native down'],
            $decoded->content,
        );
    }

    public function testLegacySourceKeyIsIgnored(): void
    {
        $result = ToolResult::of(new ToolCall('call-1', 'echo'), ['error' => 'old']);
        $data = $result->jsonSerialize();
        $data['source'] = 'abort';

        $decoded = ToolResult::decode($data);

        $this->assertSame(['error' => 'old'], $decoded->content);
    }

    public function testDecodeRoundTripsFields(): void
    {
        $result = ToolResult::of(
            new ToolCall('call-1', 'echo'),
            'ok',
            timing: new ToolTiming(receive: 1.25, execute: 0.03),
        );

        $decoded = ToolResult::decode(Json::parse(Json::stringify($result)));

        $this->assertSame('ok', $decoded->content);
        $this->assertSame('call-1', $decoded->id);
        $this->assertSame('echo', $decoded->name);
        $this->assertSame(1.25, $decoded->timing?->receive);
        $this->assertSame(0.03, $decoded->timing?->execute);
    }

    public function testAttributesRoundTripAndStayOffWire(): void
    {
        $call = new ToolCall('call-1', 'agent_file_research');
        $result = ToolResult::of(
            $call,
            'answer text',
            attributes: [
                'agent_as_tool' => true,
                'thread_id' => 't10001',
                'agent' => 'file-researcher',
            ],
        );

        $this->assertSame('answer text', $result->content);
        $this->assertSame('t10001', $result->attributes['thread_id'] ?? null);

        $decoded = ToolResult::decode($result->jsonSerialize());
        $this->assertSame('answer text', $decoded->content);
        $this->assertTrue($decoded->attributes['agent_as_tool'] ?? false);
        $this->assertSame('t10001', $decoded->attributes['thread_id'] ?? null);

        // `attributes` is local/UI metadata: storage keeps it, no message wire carries it.
        $this->assertSame($result->jsonSerialize(), $decoded->jsonSerialize());
    }

    public function testJsonSerializeKeepsEmptyFields(): void
    {
        $result = new ToolResult('ok', 'call-1', 'echo');

        $encoded = $result->jsonSerialize();
        $this->assertSame('recall', array_key_last($encoded));
        $this->assertSame([], $encoded['parts']);
        $this->assertSame([], $encoded['attributes']);
        $this->assertNull($encoded['recall']);
    }

    public function testRecallRoundTrips(): void
    {
        $result = new ToolResult('preview', 'call-1', 'bash');
        $result->recall = 'tool-result/call-1.txt';

        $decoded = ToolResult::decode($result->jsonSerialize());

        $this->assertSame('tool-result/call-1.txt', $decoded->recall);
        $this->assertSame('preview', $decoded->content);
    }

    public function testPartsRoundTripKeepsTextContent(): void
    {
        $ref = 'tool-asset/call_1-1.png';
        $result = new ToolResult('shot ok', 'call_1', 'browser_take_screenshot', parts: [
            MediaPart::of($ref, mime: 'image/png'),
        ]);

        $this->assertSame('shot ok', $result->content);

        $decoded = ToolResult::decode(Json::parse(Json::stringify($result)));
        $this->assertCount(1, $decoded->parts);
        $this->assertInstanceOf(MediaPart::class, $decoded->parts[0]);
        $this->assertSame($ref, $decoded->parts[0]->ref);
        $this->assertSame('image/png', $decoded->parts[0]->mime);
        $this->assertSame('shot ok', $decoded->content);
    }

    public function testEmptyAttributesRoundTrip(): void
    {
        $result = ToolResult::of(new ToolCall('call-1', 'echo'), 'ok');

        $this->assertSame([], $result->attributes);

        $decoded = ToolResult::decode($result->jsonSerialize());

        $this->assertSame([], $decoded->attributes);
    }

    public function testOfPreservesTimingValues(): void
    {
        $timing = new ToolTiming(receive: 0.1, execute: 0.2);
        $call = new ToolCall('call-1', 'echo');
        $result = ToolResult::of($call, 'ok', timing: $timing);

        $this->assertSame(0.1, $result->timing?->receive);
        $this->assertSame(0.2, $result->timing?->execute);
    }

    public function testDecodeRestoresTimingFromArray(): void
    {
        $result = ToolResult::of(
            new ToolCall('call-1', 'echo'),
            'ok',
            timing: new ToolTiming(receive: 0.3, execute: 0.4),
        );

        $decoded = ToolResult::decode(Json::parse(Json::stringify($result)));

        $this->assertSame(0.3, $decoded->timing?->receive);
        $this->assertSame(0.4, $decoded->timing?->execute);
    }

    public function testCloneIsolatesTimingAndParts(): void
    {
        $result = ToolResult::of(
            new ToolCall('call-1', 'shot'),
            'ok',
            timing: new ToolTiming(0.1, 0.2),
            parts: [MediaPart::of('a.png', mime: 'image/png')],
        );
        $copy = clone $result;
        $copy->timing->execute = 9.0;
        $copy->parts[0]->ref = 'b.png';

        $this->assertSame(0.2, $result->timing?->execute);
        $this->assertSame('a.png', $result->parts[0]->ref);
    }
}
