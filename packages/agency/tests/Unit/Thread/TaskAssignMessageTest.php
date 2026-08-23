<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Thread;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Agency\Thread\TaskAssignMessage;
use Switon\Core\Json;

#[CoversClass(TaskAssignMessage::class)]
class TaskAssignMessageTest extends TestCase
{
    public function testOfCarriesBriefAndTaskId(): void
    {
        $assign = TaskAssignMessage::of('do work', taskId: 'task-1');

        $this->assertSame('do work', $assign->wire()[0]['text']);
        $this->assertSame('task-1', $assign->taskId);
        $this->assertSame('user', $assign->role);
    }

    public function testRoundTripPreservesTaskId(): void
    {
        $encoded = Json::parse(Json::stringify(TaskAssignMessage::of('brief', taskId: 'task-round')));
        $decoded = TaskAssignMessage::decode($encoded);

        $this->assertSame('brief', $decoded->wire()[0]['text']);
        $this->assertSame('task-round', $decoded->taskId);
        $this->assertSame(TaskAssignMessage::class, $encoded['class']);
    }
}
