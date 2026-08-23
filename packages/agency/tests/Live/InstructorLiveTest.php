<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Instructor;
use Switon\Ai\InstructorInterface;
use Switon\Ai\Profile;
use Switon\Agency\ProfilesInterface;

/**
 * Instruct runs against a real LLM ({@see \Switon\Llm\Anthropic} or {@see \Switon\Llm\OpenAi}).
 *
 * @group live
 * @group integration
 */
#[CoversClass(Instructor::class)]
#[Group('live')]
#[Group('integration')]
class InstructorLiveTest extends LiveTestCase
{
    protected function instructor(): InstructorInterface
    {
        return $this->container->make(InstructorInterface::class);
    }

    protected function instructorProfile(int $maxSteps = 8): Profile
    {
        $profile = clone $this->container->get(ProfilesInterface::class)->get('instructor');
        $profile->maxSteps = $maxSteps;

        return $profile;
    }

    public function testInstructEndsWithResponseStringField(): void
    {
        $this->assertLiveLlmBound();

        $result = liveRunInCoroutine(
            fn (): array => $this->instructor()->ask(
                $this->instructorProfile(),
                'End the run with the completion tool. Set message exactly to: instruct-live-ok.',
                [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Exact token to return.',
                    ],
                ],
            ),
        );

        $this->assertSame(['message' => 'instruct-live-ok'], $result);
    }

    public function testInstructReturnsIntegerField(): void
    {
        $this->assertLiveLlmBound();

        $result = liveRunInCoroutine(
            fn (): array => $this->instructor()->ask(
                $this->instructorProfile(),
                'What is 41 + 1?',
                [
                    'sum' => [
                        'type' => 'integer',
                        'description' => 'Numeric sum.',
                    ],
                ],
            ),
        );

        $this->assertSame(['sum' => 42], $result);
    }

    public function testInstructLeavesOmittedOptionalFieldUnset(): void
    {
        $this->assertLiveLlmBound();

        $result = liveRunInCoroutine(
            fn (): array => $this->instructor()->ask(
                $this->instructorProfile(),
                'End the run with the completion tool. Set message exactly to instruct-live-ok. '
                . 'Do not include status.',
                [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Exact token to return.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Leave unset.',
                        'default' => 'pending',
                    ],
                ],
            ),
        );

        $this->assertSame(['message' => 'instruct-live-ok'], $result);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testInstructReturnsBashStdout(): void
    {
        $this->assertLiveLlmBound();

        $profile = $this->instructorProfile();
        $profile->tools = ['bash'];

        $result = liveRunInCoroutine(
            fn (): array => $this->instructor()->ask(
                $profile,
                'Run bash with command `printf %s instruct-live-tool`. '
                . 'Put that exact stdout token in message and nothing else.',
                [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Exact stdout token.',
                    ],
                ],
            ),
        );

        $this->assertSame(['message' => 'instruct-live-tool'], $result);
    }
}
