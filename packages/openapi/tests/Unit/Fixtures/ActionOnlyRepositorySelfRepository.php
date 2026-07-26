<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

class ActionOnlyRepositorySelfRepository
{
    public self $groupRepository;

    public function indexAction(): array
    {
        return $this->groupRepository->all();
    }
}
