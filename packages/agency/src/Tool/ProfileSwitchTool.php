<?php

declare(strict_types=1);

namespace Switon\Agency\Tool;

use Switon\Agency\Event\ProfileSwitched;
use Switon\Agency\Exception;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Tooling\AbstractTool;

use function is_string;

/**
 * Tool `profile_switch`. Which targets are sensible is the calling profile's prompt;
 * only existence is checked here. Who may call is profile tool config.
 */
class ProfileSwitchTool extends AbstractTool
{
    #[Autowired] protected ProfilesInterface $profiles;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    public function schema(string $name): ?ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Switch the session to another profile named in your instructions.',
            properties: [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Target profile id (e.g. coding.agent, coding.ask, coding.plan, coding.debug).',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Optional one-line why (shown in tool trace).',
                ],
            ],
            required: ['profile'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $profileId = $arguments['profile'];
        if ($profileId === '') {
            ToolInvalidArgumentException::raise('profile must be non-empty.', ['field' => 'profile']);
        }
        if (!$this->profiles->has($profileId)) {
            ToolInvalidArgumentException::raise(
                'Profile "{profile}" is not configured.',
                ['field' => 'profile', 'profile' => $profileId],
            );
        }
        if ($agent->profile()->id === $profileId) {
            return [
                'profile' => $profileId,
                'changed' => false,
            ];
        }

        $agent->switchProfile($this->profiles->get($profileId));

        $prompt = $agent->charter()->prompt;
        $this->eventDispatcher->dispatch(new ProfileSwitched($agent, $prompt));

        $result = [
            'profile' => $profileId,
            'changed' => true,
        ];
        $reason = isset($arguments['reason']) && is_string($arguments['reason'])
            ? $arguments['reason']
            : '';
        if ($reason !== '') {
            $result['reason'] = $reason;
        }

        return $result;
    }
}
