<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Attribute;

use ReflectionMethod;
use Switon\Core\Exception\RuntimeException;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\TenantFilter;
use Switon\Http\Criteria;
use Switon\Http\CriteriaResolver;
use Switon\Http\KeywordTransformerInterface;
use Switon\Http\Request\File\Exception as FileException;
use Switon\Http\RequestInterface;
use Switon\Http\Tests\Fixtures\CriteriaAttributeApplyFixture;
use Switon\Http\Tests\Fixtures\CriteriaAttributesController;
use Switon\Http\Tests\Fixtures\IdentityFilterCriteriaController;
use Switon\Http\Tests\Support\BoundAction;
use Switon\Http\Tests\TestCase;
use Switon\Principal\IdentityInterface;
use Switon\Principal\TenantInterface;

class CriteriaResolverCoverageTest extends TestCase
{
    public function testResolveRejectsUnexpectedType(): void
    {
        $resolver = $this->make(CriteriaResolver::class);
        $controller = new IdentityFilterCriteriaController();
        $parameter = (new ReflectionMethod($controller, 'indexAction'))->getParameters()[0];

        $this->expectException(RuntimeException::class);

        $resolver->resolve($parameter, 'array');
    }

    public function testResolveMergesIdentityFilterScopeIntoCriteria(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('getId')->willReturn(88);
        $this->container->replace(IdentityInterface::class, $identity);

        $controller = new IdentityFilterCriteriaController();
        BoundAction::invoke($this->container, $controller, 'indexAction');

        $criteria = $controller->criteria;
        $this->assertNotNull($criteria);
        $this->assertSame(['owner_id' => 88], $criteria->filters);
    }

    public function testResolveAppliesCriteriaAttributesAndSkipsNonCriteriaAttributes(): void
    {
        $controller = new CriteriaAttributesController();
        BoundAction::invoke($this->container, $controller, 'indexAction');

        $criteria = $controller->criteria;
        $this->assertNotNull($criteria);
        $this->assertSame(['id', 'email'], $criteria->fields);
        $this->assertSame(['created_at' => -1], $criteria->orders);
        $this->assertSame(['deleted_at' => 0], $criteria->filters);
    }

    public function testFiltersMergesRequestFiltersAheadOfExistingCriteria(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('filters')
            ->with(['status', 'owner_id'])
            ->willReturn(['status' => 'published', 'owner_id' => 7]);
        $this->container->replace(RequestInterface::class, $request);

        $attribute = $this->make(Filters::class, ['fields' => ['status', 'owner_id']]);
        $criteria = new Criteria();
        $criteria->filters = ['status' => 'draft', 'existing' => 'keep'];

        $parameter = (new ReflectionMethod(CriteriaAttributeApplyFixture::class, 'withFilters'))->getParameters()[0];
        $attribute->apply($criteria, $parameter);

        $this->assertSame(
            ['status' => 'draft', 'owner_id' => 7, 'existing' => 'keep'],
            $criteria->filters,
        );
    }

    public function testKeywordAppliesTransformedFilterAndPreservesExistingValue(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('get')
            ->with('keyword', '')
            ->willReturn('  acme  ');
        $this->container->replace(RequestInterface::class, $request);

        $transformer = $this->createMock(KeywordTransformerInterface::class);
        $transformer->expects($this->once())
            ->method('transform')
            ->with(['name', 'email'], 'acme', '*=')
            ->willReturn('email*=');
        $this->container->replace(KeywordTransformerInterface::class, $transformer);

        $attribute = $this->make(Keyword::class, ['fields' => ['name', 'email']]);
        $criteria = new Criteria();
        $criteria->filters = ['email*=' => 'existing'];

        $parameter = (new ReflectionMethod(CriteriaAttributeApplyFixture::class, 'withKeyword'))->getParameters()[0];
        $attribute->apply($criteria, $parameter);

        $this->assertSame(['email*=' => 'existing'], $criteria->filters);
    }

    public function testKeywordSkipsBlankKeywordAndEmptyFields(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->once())
            ->method('get')
            ->with('keyword', '')
            ->willReturn('   ');
        $this->container->replace(RequestInterface::class, $request);

        $transformer = $this->createMock(KeywordTransformerInterface::class);
        $transformer->expects($this->never())->method('transform');
        $this->container->replace(KeywordTransformerInterface::class, $transformer);

        $attribute = $this->make(Keyword::class, ['fields' => []]);
        $criteria = new Criteria();

        $parameter = (new ReflectionMethod(CriteriaAttributeApplyFixture::class, 'withEmptyKeywordFields'))->getParameters()[0];
        $attribute->apply($criteria, $parameter);

        $this->assertSame([], $criteria->filters);
    }

    public function testTenantFilterAppliesTenantId(): void
    {
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getId')->willReturn('tenant-17');
        $this->container->replace(TenantInterface::class, $tenant);

        $attribute = $this->make(TenantFilter::class, ['field' => 'tenant_id']);
        $criteria = new Criteria();

        $parameter = (new ReflectionMethod(CriteriaAttributeApplyFixture::class, 'withTenantFilter'))->getParameters()[0];
        $attribute->apply($criteria, $parameter);

        $this->assertSame(['tenant_id' => 'tenant-17'], $criteria->filters);
    }

    public function testRequestFileExceptionReturnsBadRequestStatusCode(): void
    {
        $this->assertSame(400, FileException::of('broken')->getStatusCode());
    }
}
