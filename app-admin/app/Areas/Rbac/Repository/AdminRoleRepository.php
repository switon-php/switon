<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Repository;

use App\Areas\Rbac\Entity\AdminRole;
use Switon\Orm\JunctionRepositoryTrait;
use Switon\Orm\Repository;

/**
 * @extends Repository<AdminRole>
 */
class AdminRoleRepository extends Repository
{
    use JunctionRepositoryTrait;
}
