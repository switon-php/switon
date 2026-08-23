<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval;

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\ProfilesInterface;
use Switon\Skill\SkillsInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\Runtime;
use Switon\Ai\Envelope;
use Switon\Agency\Agent;

use function is_dir;
use function is_string;
use function rtrim;
use function scandir;

/** Run one skills eval scenario against a configured container. */
class SkillsEvalRunner
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    /**
     * @param array{
     *     id: string,
     *     user: string,
     *     skills_root?: string,
     *     expect: array<string, mixed>,
     * } $scenario
     */
    public function run(array $scenario): ScenarioResult
    {
        if (isset($scenario['skills_root']) && is_string($scenario['skills_root']) && $scenario['skills_root'] !== '') {
            /** @var SkillsInterface $skills */
            $skills = $this->container->get(SkillsInterface::class);
            $root = rtrim($scenario['skills_root'], '/');
            $entries = scandir($root);
            if ($entries !== false) {
                foreach ($entries as $leaf) {
                    if ($leaf === '.' || $leaf === '..' || !is_dir($root . '/' . $leaf)) {
                        continue;
                    }
                    $skill = $skills->load($root . '/' . $leaf);
                    if ($skill === null) {
                        continue;
                    }
                    $skills->set($skill);
                }
            }
        }

        $profiles = $this->container->get(ProfilesInterface::class);
        $base = $profiles->get('host');
        $profile = clone $base;
        $profile->tools = array_values(array_unique([
            ...$base->tools,
            'read',
            'grep',
        ]));

        /** @var Agent $host */
        $host = $this->container->get(RecordingAgentManager::class)->mint([
            'profile' => $profile,
        ]);

        require_once dirname(__DIR__) . '/manual/live_bootstrap.php';
        liveTrackOpenAgent($host);

        $container = $this->container;
        $run = static function () use ($host, $scenario, $container): void {
            $host->run(Envelope::of($scenario['user'], liveModel($container, $host->profile()->model)));
        };

        try {
            if (\Swoole\Coroutine::getCid() > 0) {
                $run();
            } elseif (extension_loaded('swoole')) {
                // Agent inbox workers require Coroutine::run(); liveRunInCoroutine skips it when runtime is off.
                $prevEnabled = Runtime::isCoroutineEnabled();
                Runtime::setCoroutineEnabled(true);
                try {
                    liveRunInCoroutine($run);
                } finally {
                    Runtime::setCoroutineEnabled($prevEnabled);
                }
            } else {
                $run();
            }
        } finally {
            liveKillAgentTree($host);
        }

        return ScenarioEvaluator::evaluateSkills($host->thread(), $scenario);
    }
}
