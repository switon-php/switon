<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Thread;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Agency\Thread\TaskFinishSignal;

#[CoversClass(TaskFinishSignal::class)]
class TaskFinishSignalTest extends TestCase
{
    public function testFinishWireUsesSignalName(): void
    {
        $fields = [
            'task_id' => 'task-1',
            'thread_id' => 'worker',
            'status' => 'completed',
            'timeSpent' => 1.5,
        ];
        $message = new TaskFinishSignal($fields, 'done');

        $this->assertStringContainsString('[signal:task_finish.completed]', $message->wire()[0]['text']);
        $this->assertStringContainsString('status: completed', $message->wire()[0]['text']);
    }

    public function testHeadlinePrefersTaskBriefWhenCompleted(): void
    {
        $fields = [
            'task_id' => 'task-1',
            'thread_id' => 't10001',
            'status' => 'completed',
            'task' => 'implement auth for api endpoints carefully',
        ];
        $body = 'long result prose that should not lead the headline';
        $message = new TaskFinishSignal($fields, $body);

        $this->assertSame('[task_finish.completed] implement auth for api endpoints carefully', $message->text());
        $this->assertSame('completed', $message->kind());
        $this->assertSame('task_finish.completed', $message->label());
    }

    public function testHeadlinePrefersBodyWhenStopped(): void
    {
        $fields = [
            'task_id' => 'task-1',
            'thread_id' => 't10001',
            'status' => 'stopped',
            'task' => 'implement auth',
        ];
        $body = 'RunCancelledException: user cancelled';
        $message = new TaskFinishSignal($fields, $body);

        $this->assertSame('[task_finish.stopped] RunCancelledException: user cancelled', $message->text());
        $this->assertSame('task_finish.stopped', $message->label());
    }

    public function testFinishDecodeRoundTrip(): void
    {
        $fields = [
            'task_id' => 'task-1',
            'thread_id' => 'worker',
            'status' => 'completed',
            'task' => 'do the thing',
        ];
        $original = new TaskFinishSignal($fields, 'done');
        $decoded = TaskFinishSignal::decode($original->jsonSerialize());

        $this->assertSame('task-1', $decoded->taskId());
        $this->assertSame('do the thing', $decoded->task());
        $this->assertSame(TaskFinishSignal::NAME, $decoded->name);
        $this->assertSame($original->text(), $decoded->text());
        $this->assertArrayNotHasKey('headline', $original->jsonSerialize());
    }
}
