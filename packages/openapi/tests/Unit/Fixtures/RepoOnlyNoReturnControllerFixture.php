<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

class RepoOnlyNoReturnControllerFixture
{
    public function __construct(
        public OpenApiTestsFakeGroupRepository $groupRepository,
    ) {
    }

    public function indexAction()
    {
        return $this->groupRepository->all();
    }
}
