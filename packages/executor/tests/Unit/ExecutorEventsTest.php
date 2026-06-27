<?php

declare(strict_types=1);

namespace Switon\Executor\Tests\Unit;

use RuntimeException;
use Switon\Executor\Event\RunnerFinished;
use Switon\Executor\Event\RunnerStarting;
use Switon\Executor\Tests\TestCase;

class ExecutorEventsTest extends TestCase
{
    public function testRunnerStartingJsonSerialize(): void
    {
        $executor = $this->createStub(\Switon\Executor\ExecutorInterface::class);
        $runner = $this->createStub(\Switon\Core\RunnerInterface::class);
        $event = new RunnerStarting($executor, $runner, 'Svc:job', ['a' => 1]);

        $this->assertSame(['task' => 'Svc:job'], $event->jsonSerialize());
    }

    public function testRunnerFinishedJsonSerializeWithError(): void
    {
        $err = new RuntimeException('x', 7);
        $executor = $this->createStub(\Switon\Executor\ExecutorInterface::class);
        $runner = $this->createStub(\Switon\Core\RunnerInterface::class);
        $event = new RunnerFinished($executor, $runner, 'T', null, null, $err, 1.25);

        $data = $event->jsonSerialize();
        $this->assertSame('T', $data['task']);
        $this->assertNull($data['result']);
        $this->assertSame(1.25, $data['elapsed']);
        $this->assertIsArray($data['error']);
        $this->assertSame(RuntimeException::class, $data['error']['class']);
        $this->assertSame('x', $data['error']['message']);
        $this->assertSame(7, $data['error']['code']);
    }
}
