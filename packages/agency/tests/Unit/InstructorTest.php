<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Event\InstructFail;
use Switon\Agency\Event\InstructSuccess;
use Switon\Agency\Instruct\Response;
use Switon\Agency\Instructor;
use Switon\Ai\InstructorInterface;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Core\Json;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Llm\Exception\ApiFailedException;
use Switon\Llm\Exception\ApiIncompleteException;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;

use function spl_object_id;

#[CoversClass(ApiFailedException::class)]
#[CoversClass(ApiIncompleteException::class)]
#[CoversClass(Instructor::class)]
class InstructorTest extends TestCase
{
    protected function configureEchoTool(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo'),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    protected function instructor(): InstructorInterface
    {
        return $this->container->make(InstructorInterface::class);
    }

    /** Wire name {@see Instructor} registers for this live instance. */
    protected function responseWire(object $instructor): string
    {
        return 'tmp_instruct_' . spl_object_id($instructor);
    }

    public function testInstructAllowsIntermediateToolsBeforeResponse(): void
    {
        $this->configureEchoTool();
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $responseName = $this->responseWire($instructor);
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['message' => 'step', '.caption' => 'Echo step'], 'call_echo', null);
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $responseName,
            ['code' => Response::OK, 'data' => ['message' => 'done']],
            'call_result',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        $profile = $this->testProfile(tools: ['echo']);
        $result = $instructor->ask(
            $profile,
            'work',
            ['message' => ['type' => 'string']],
        );

        $this->assertSame(['message' => 'done'], $result);
        $this->assertSame(['echo'], $profile->tools);
        // echo -> response -> closing step: `response` is an ordinary tool, so its result is
        // handed back to the model and the run ends on the model's next (empty) reply.
        $this->assertCount(3, $stub->requests);
        $this->assertArrayNotHasKey(
            $responseName,
            $this->container->get(ToolsInterface::class)->pick([$responseName]),
        );
    }

    public function testInstructRetriesInvalidResponseArguments(): void
    {
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $responseName = $this->responseWire($instructor);
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $responseName,
            ['code' => Response::OK, 'data' => new \stdClass()],
            'call_bad',
            null,
        );
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $responseName,
            ['code' => Response::OK, 'data' => ['message' => 'done']],
            'call_ok',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        $result = $instructor->ask(
            $this->testProfile(),
            'work',
            ['message' => ['type' => 'string']],
        );

        $this->assertSame(['message' => 'done'], $result);
        // bad response -> corrected response -> closing step
        $this->assertCount(3, $stub->requests);
        $this->assertTrue($this->wireHasToolResultContaining($stub->requests[1], 'arguments invalid'));
    }

    public function testInstructRetriesMissingBusinessData(): void
    {
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $responseName = $this->responseWire($instructor);
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $responseName,
            ['code' => Response::OK],
            'call_bad',
            null,
        );
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $responseName,
            ['code' => Response::OK, 'data' => ['message' => 'done']],
            'call_ok',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        $result = $instructor->ask(
            $this->testProfile(),
            'work',
            ['message' => ['type' => 'string']],
        );

        $this->assertSame(['message' => 'done'], $result);
        // bad response -> corrected response -> closing step
        $this->assertCount(3, $stub->requests);
        $this->assertTrue($this->wireHasToolResultContaining($stub->requests[1], 'arguments invalid'));
        $this->assertTrue($this->wireHasToolResultContaining($stub->requests[1], 'data.message'));
    }

    public function testInstructThrowsOnNonOkResponseCode(): void
    {
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $this->responseWire($instructor),
            [
                'code' => 'insufficient_input',
                'msg' => 'missing repo path',
                'data' => ['hint' => 'path'],
            ],
            'call_fail',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        try {
            $instructor->ask($this->testProfile(), 'scan repo', [
                'path' => ['type' => 'string'],
            ]);
            $this->fail('Expected ApiFailedException.');
        } catch (ApiFailedException $e) {
            $this->assertSame('missing repo path', $e->reason);
            $this->assertSame('missing repo path', $e->getMessage());
            $this->assertSame(['hint' => 'path'], $e->details);
        }
    }

    public function testInstructDispatchesSuccessForAnAcceptedResponse(): void
    {
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $this->responseWire($instructor),
            ['code' => Response::OK, 'data' => ['message' => 'done']],
            'call_result',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $dispatcher->clear();

        $instructor->ask($this->testProfile(), 'work', ['message' => ['type' => 'string']]);

        $events = $dispatcher->getEventsByClass(InstructSuccess::class);
        $this->assertCount(1, $events);
        $this->assertSame('call_result', $events[0]->toolCall->id);
        $this->assertSame(Response::OK, $events[0]->result['code'] ?? null);
        $this->assertCount(0, $dispatcher->getEventsByClass(InstructFail::class));
    }

    public function testInstructDispatchesFailForANonOkResponseCode(): void
    {
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $this->responseWire($instructor),
            ['code' => 'insufficient_input', 'msg' => 'missing repo path'],
            'call_fail',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $dispatcher->clear();

        try {
            $instructor->ask($this->testProfile(), 'scan repo', ['path' => ['type' => 'string']]);
            $this->fail('Expected ApiFailedException.');
        } catch (ApiFailedException) {
        }

        $events = $dispatcher->getEventsByClass(InstructFail::class);
        $this->assertCount(1, $events);
        $this->assertSame('insufficient_input', $events[0]->failureCode);
        $this->assertSame('missing repo path', $events[0]->message);
        $this->assertCount(0, $dispatcher->getEventsByClass(InstructSuccess::class));
    }

    public function testInstructLeavesNestedDefaultsUnset(): void
    {
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $this->responseWire($instructor),
            ['code' => Response::OK],
            'call_result',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        $result = $instructor->ask($this->testProfile(), 'work', [
            'status' => [
                'type' => 'string',
                'default' => 'pending',
            ],
        ]);

        $this->assertSame([], $result);
    }

    public function testInstructRaisesWhenDriveReturnsRunning(): void
    {
        $instructor = $this->instructor();
        $this->expectException(ApiIncompleteException::class);
        $this->expectExceptionMessage('Instruct did not complete with ' . $this->responseWire($instructor) . '.');

        $instructor->ask(
            $this->testProfile(maxSteps: 0),
            'work',
            ['message' => ['type' => 'string']],
        );
    }

    public function testIncompleteInstructKeepsLandedSteps(): void
    {
        $this->configureEchoTool();
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['message' => 'step', '.caption' => 'Echo step'], 'call_echo', null);
        $stub->responses[] = StubLlm::assistant('still open');
        $stub->responses[] = StubLlm::assistant('still open');
        $this->container->set(LlmInterface::class, $stub);

        $instructor = $this->instructor();

        try {
            $instructor->ask(
                $this->testProfile(tools: ['echo'], maxSteps: 2),
                'work',
                ['message' => ['type' => 'string']],
                );
            $this->fail('Expected ApiIncompleteException.');
        } catch (ApiIncompleteException) {
            $this->assertCount(2, $stub->requests);
            $hasEcho = false;
            foreach ($stub->requests[1] as $message) {
                if (!$message instanceof ToolMessage) {
                    continue;
                }
                foreach ($message->results as $result) {
                    if ($result->name === 'echo') {
                        $hasEcho = true;
                        break 2;
                    }
                }
            }
            $this->assertTrue($hasEcho);
        }
    }

    public function testInstructPromptRendersProfileBody(): void
    {
        $stub = new StubLlm();
        $instructor = $this->instructor();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            $this->responseWire($instructor),
            ['code' => Response::OK, 'data' => ['message' => 'done']],
            'call_result',
            null,
        );
        $this->container->set(LlmInterface::class, $stub);

        $instructor->ask(
            $this->testProfile(prompt: 'schema-body'),
            'work',
            ['message' => ['type' => 'string']],
        );

        $this->assertSame('system', $stub->requests[0][0]->role);
        $this->assertStringContainsString('schema-body', (string) $stub->requests[0][0]->content);
    }

    /**
     * @param list<Message> $messages
     */
    protected function wireHasToolResultContaining(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (!$message instanceof ToolMessage) {
                continue;
            }
            foreach ($message->results as $result) {
                if (str_contains(Json::text($result->content), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
