<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

class ActionOnlyRepositoryInheritedRepositoryParentFixture
{
    public OpenApiTestsFakeGroupRepository $groupRepository;
}

class ActionOnlyRepositoryInheritedRepositoryChildFixture extends ActionOnlyRepositoryInheritedRepositoryParentFixture
{
    public function indexAction(): array
    {
        return $this->groupRepository->all();
    }
}
