<?php

declare(strict_types=1);

namespace Switon\Dict;

use Switon\Core\Attribute\ResourceAlias;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;
use Switon\Validating\ValidatorInterface;

/**
 * Registers Dict resource aliases and validator template directories.
 *
 * Road-signs:
 * - @switon.dict.resources
 * - ValidatorInterface dirs
 * - DictValue validation messages
 *
 * @see \Switon\Core\ServiceProviderInterface
 * @see \Switon\Validating\ValidatorInterface
 */
#[ResourceAlias(alias: '@switon.dict.resources', path: 'src/Templates')]
class ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->set(ValidatorInterface::class, [
            'dirs' => ['@switon.dict.resources'],
        ]);
    }

    public function boot(): void
    {
    }
}
