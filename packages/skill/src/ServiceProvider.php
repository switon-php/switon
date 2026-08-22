<?php

declare(strict_types=1);

namespace Switon\Skill;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Scans flat skill paths during boot.
 *
 * Guidance: {@see SkillsInterface::reload()} merges disk rows and keeps rows published with {@see SkillsInterface::set()}.
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected SkillsInterface $skills;

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
        $this->skills->reload();
    }
}
