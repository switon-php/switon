<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\TenantFilter;
use Switon\Http\Criteria;

class CriteriaAttributeApplyFixture
{
    #[Filters(['status', 'owner_id'])]
    public function withFilters(Criteria $criteria): void
    {
    }

    #[Keyword(['name', 'email'])]
    public function withKeyword(Criteria $criteria): void
    {
    }

    #[Keyword([])]
    public function withEmptyKeywordFields(Criteria $criteria): void
    {
    }

    #[TenantFilter('tenant_id')]
    public function withTenantFilter(Criteria $criteria): void
    {
    }
}
