<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Agency\Event\InstructFail;
use Switon\Agency\Event\InstructSuccess;
use Switon\Agency\Instruct\Response;
use Switon\Ai\AgentInterface;
use Switon\Ai\Envelope;
use Switon\Ai\InstructorInterface;
use Switon\Ai\Profile;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\Ai\TurnStatus;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\Strings;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Llm\Exception\ApiFailedException;
use Switon\Llm\Exception\ApiIncompleteException;
use Switon\Thread\ThreadsInterface;
use Switon\Tooling\ToolsInterface;

use function array_find;
use function array_key_exists;
use function array_splice;
use function count;
use function getcwd;
use function in_array;
use function is_array;
use function is_string;
use function spl_object_id;

use stdClass;

/**
 * Structured output as one agent run. This instance is the terminal tool.
 *
 * Guidance: resolve a fresh instance with {@see \Switon\Core\ContainerInterface::make()} — `get()`
 * shares one object, and its schema property is the latest `instruct()` call. The wire id is
 * `tmp_instruct_{object_id}`. It is registered on {@see ToolsInterface} for the call and removed
 * when `instruct()` returns. `ask()` opens that session and closes it. `instruct()` uses an agent the
 * caller already started. `schema()` returns the property assigned at the start of that call.
 *
 * @see \Switon\Ai\InstructorInterface
 */
class Instructor implements InstructorInterface, ToolInterface
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected ThreadsInterface $threads;
    #[Autowired] protected ToolsInterface $tools;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    /** Schema for the current `instruct()` call. Null before the first call. */
    protected ?ToolSchema $schema = null;

    /**
     * Business fields for the current call. `call()` validates `data` against this.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $properties = [];

    /** Wire id for this instance. Stable for the object lifetime. */
    public function wire(): string
    {
        return 'tmp_instruct_' . spl_object_id($this);
    }

    public function schema(string $name): ?ToolSchema
    {
        unset($name);

        return $this->schema;
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        unset($agent, $call);
        $error = $this->validate($arguments);
        if ($error !== null) {
            return ['error' => 'response arguments invalid: ' . $error . '. Call again with valid arguments.'];
        }

        return $arguments;
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     *
     * @return array<string, mixed>
     */
    public function ask(Profile $profile, string $content, array $properties): array
    {
        $thread = Thread::of(Strings::uuid());
        $this->threads->create($thread);

        /** @var Agent $agent */
        $agent = $this->container->make(Agent::class, [
            'thread' => $thread,
            'profile' => $profile,
            'workspace' => getcwd() ?: '',
        ]);
        $agent->thread()->state = ['profileId' => $profile->id];
        $agent->start();
        try {
            return $this->instruct($agent, $content, $properties);
        } finally {
            $agent->shutdown();
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     *
     * @return array<string, mixed>
     */
    public function instruct(AgentInterface $agent, string $content, array $properties): array
    {
        $this->properties = $properties;
        $this->schema = $this->buildSchema($properties);
        $wire = $this->wire();
        $this->tools->addTool($wire, $this);
        $profile = $agent->profile();
        $profile->tools[] = $wire;
        $agent->charter()->invalidate();
        try {
            $turn = $agent->run(Envelope::of($content, $profile->model));
            if ($turn === null) {
                ApiIncompleteException::raise('Instruct did not complete with {name}.', ['name' => $wire]);
            }
            if ($turn->status === TurnStatus::Suspended) {
                ApiIncompleteException::raise('Instruct suspended; a sync instruct cannot resume.');
            }

            $closing = $this->closingCall($turn);
            try {
                $data = $this->settle($closing);
            } catch (ApiFailedException $e) {
                $this->dispatchReply($agent, $closing, $e);

                throw $e;
            }
            $this->dispatchReply($agent, $closing, null);

            return $data;
        } finally {
            $this->tools->removeTool($wire);
            $index = array_search($wire, $profile->tools, true);
            if ($index !== false) {
                array_splice($profile->tools, $index, 1);
            }
            $agent->charter()->invalidate();
        }
    }

    /**
     * Report the closing terminal call to observability: accepted envelope, or the failure category.
     *
     * Guidance: the events name {@see Agent}, so a non-{@see Agent} host skips them; an absent closing
     * call is {@see ApiIncompleteException}, not a business failure, and is not reported here.
     */
    protected function dispatchReply(AgentInterface $agent, ?ToolCall $closing, ?ApiFailedException $failure): void
    {
        if (!$agent instanceof Agent || $closing === null) {
            return;
        }

        $this->eventDispatcher->dispatch($failure === null
            ? new InstructSuccess($agent, $closing, $closing->arguments)
            : InstructFail::fromException($agent, $closing, $failure));
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     */
    protected function buildSchema(array $properties): ToolSchema
    {
        $data = [
            'type' => 'object',
            'description' => 'Business fields when code is ok; optional details when failed.',
            // Open: a failure envelope carries free-form details, so closing `data` would reject
            // exactly the payload `code !== ok` exists to describe. Business fields are enforced
            // by {@see self::validate()} on the ok path, not by schema closedness.
            'additionalProperties' => true,
        ];
        if ($properties === []) {
            $data['properties'] = new stdClass();
        } else {
            // No `required` here: business fields are mandatory only on the ok path, which JSON
            // Schema cannot express without conditionals. {@see self::validate()} is the single
            // enforcement point, so a failure envelope may carry `data` with only free-form details.
            $data['properties'] = $properties;
        }

        return new ToolSchema(
            $this,
            description: 'End this run by calling this tool.',
            properties: [
                'code' => [
                    'type' => 'string',
                    'enum' => [Response::OK, ...Response::FAIL_CODES],
                    'description' => 'ok on success; otherwise a failure category.',
                ],
                'msg' => [
                    'type' => 'string',
                    'description' => 'Required when code is not ok.',
                ],
                'data' => $data,
            ],
            required: ['code'],
        );
    }

    /**
     * The last call to {@see wire()} in this turn, or null.
     *
     * Guidance: matched by wire name ({@see ToolCall::$name}), not by call id — the id is minted by
     * the provider and unknown here.
     */
    protected function closingCall(\Switon\Ai\Turn $turn): ?ToolCall
    {
        $wire = $this->wire();
        for ($ci = count($turn->completions) - 1; $ci >= 0; --$ci) {
            $call = array_find(
                $turn->completions[$ci]->toolCalls(),
                static fn (ToolCall $call): bool => $call->name === $wire,
            );
            if ($call !== null) {
                return $call;
            }
        }

        return null;
    }

    /**
     * Unwrap `data` when the closing call is `ok`; raise on a failure code or a malformed shape.
     *
     * @return array<string, mixed>
     */
    protected function settle(?ToolCall $closing): array
    {
        if ($closing === null) {
            ApiIncompleteException::raise('Instruct did not complete with {name}.', ['name' => $this->wire()]);
        }

        $arguments = $closing->arguments;
        $code = $arguments['code'] ?? '';
        if (!is_string($code) || $code !== Response::OK) {
            if (is_string($code) && in_array($code, Response::FAIL_CODES, true)) {
                throw ApiFailedException::fromArguments($arguments);
            }

            ApiIncompleteException::raise('Instruct response code must be ok.');
        }

        $data = $arguments['data'] ?? [];
        if (!is_array($data)) {
            ApiIncompleteException::raise('Instruct response data must be an object.');
        }

        return $data;
    }

    /**
     * Human-readable reason the terminal arguments are unusable, or null when fine.
     *
     * @param array<string, mixed> $arguments
     */
    protected function validate(array $arguments): ?string
    {
        $code = $arguments['code'] ?? '';
        if (is_string($code) && in_array($code, Response::FAIL_CODES, true)) {
            $msg = $arguments['msg'] ?? '';
            if (!is_string($msg) || $msg === '') {
                return 'msg required';
            }

            return null;
        }
        if ($code !== Response::OK) {
            return 'code must be ok';
        }

        $data = $arguments['data'] ?? [];
        if (!is_array($data)) {
            return 'data must be an object';
        }

        foreach ($this->properties as $property => $schema) {
            if (array_key_exists('default', $schema)) {
                continue;
            }
            if (!array_key_exists($property, $data)) {
                return 'data.' . $property . ' required';
            }
            $value = $data[$property];
            if (is_string($value) && $value === '') {
                return 'data.' . $property . ' required';
            }
        }

        return null;
    }
}
