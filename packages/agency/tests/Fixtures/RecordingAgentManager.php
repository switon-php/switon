<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use SplObjectStorage;
use ReflectionProperty;
use Switon\Agency\Agent;
use Switon\Agency\Exception;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Profile;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\Strings;
use Switon\Thread\ThreadsInterface;
use function basename;

use function getcwd;
use function is_array;
use function is_string;
use function trim;
use Switon\Ai\Thread;

/**
 * Test stand-in for minting a root agent and shutting its inbox down afterwards.
 *
 * `mint()` is the old `create(array $params)` bag, kept here so tests can still pass
 * `datadir` / `profile` / `onDelta` without a production factory.
 */
class RecordingAgentManager
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected ThreadsInterface $threads;
    #[Autowired] protected ProfilesInterface $profiles;

    /** @var SplObjectStorage<AgentInterface, true> */
    protected SplObjectStorage $tracked;

    public function __construct()
    {
        $this->tracked = new SplObjectStorage();
    }

    public function track(AgentInterface $agent): void
    {
        $this->tracked[$agent] = true;
    }

    public function stopTrackedAgents(): void
    {
        $agents = iterator_to_array($this->tracked);
        usort($agents, static fn (AgentInterface $a, AgentInterface $b): int => $b->depth() <=> $a->depth());

        foreach ($agents as $agent) {
            $this->stopActiveChildren($agent);
            $agent->shutdown();
        }

        $this->tracked = new SplObjectStorage();
    }

    /** Close spawned children left open after a test. */
    protected function stopActiveChildren(AgentInterface $agent): void
    {
        if (!$agent instanceof Agent) {
            return;
        }

        $children = (new ReflectionProperty(Agent::class, 'children'))->getValue($agent);
        if (!$children instanceof \WeakMap) {
            return;
        }
        foreach ($children as $child => $leaf) {
            if ($child instanceof AgentInterface) {
                $child->shutdown();
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param class-string<Agent> $agentClass
     */
    public function mint(array $params = [], string $agentClass = Agent::class): AgentInterface
    {
        unset($params['parent']);

        $createDatadir = is_string($params['datadir'] ?? null) ? trim($params['datadir']) : '';
        unset($params['datadir']);
        if (!isset($params['thread'])) {
            if ($createDatadir === '') {
                $createDatadir = '@threads/' . Strings::uuid();
            }
            $thread = Thread::of(basename($createDatadir), $createDatadir);
            $this->threads->create($thread);
            $params['thread'] = $thread;
        }
        unset($params['threadId'], $params['leaf']);

        $profile = $params['profile'] ?? null;
        unset($params['profile']);
        $registryId = is_string($params['profileId'] ?? null) ? trim($params['profileId']) : '';
        unset($params['profileId']);

        if ($profile instanceof Profile) {
            $this->profiles->set($profile);
        } else {
            $profile = $this->profiles->get($registryId !== '' ? $registryId : 'host');
        }
        $state = is_array($params['state'] ?? null) ? $params['state'] : [];
        $rawWorkspace = is_string($params['workspace'] ?? null) ? $params['workspace'] : '';
        unset($params['state']);

        $workspace = $params['workspace'] ?? null;
        if (!is_string($workspace) || trim($workspace) === '') {
            $workspace = getcwd() ?: '';
        }
        $params['workspace'] = $workspace;
        $params['profile'] = $profile;

        /** @var Agent $agent */
        $agent = $this->container->make($agentClass, $params);

        $state['profileId'] = $profile->id;
        if ($rawWorkspace !== '') {
            $state['workspace'] = $rawWorkspace;
        }
        $datadir = $agent->thread()->datadir;
        if ($datadir === null) {
            Exception::raise('Agent create requires thread with datadir.');
        }
        $agent->thread()->state = $state;
        $this->threads->saveState($agent->thread());
        $agent->start();
        $this->track($agent);

        return $agent;
    }
}
