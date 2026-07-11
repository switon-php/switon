<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Attribute;

use Switon\Binding\Attribute\ResolvedBy;
use Switon\Http\Attribute\RequestBody;
use Switon\Http\Attribute\RequestData;
use Switon\Http\Attribute\RequestQuery;
use Switon\Http\RequestBodyResolver;
use Switon\Http\RequestDataResolver;
use Switon\Http\RequestQueryResolver;
use PHPUnit\Framework\TestCase;

class RequestSourceAttributeTest extends TestCase
{
    public function testRequestDataResolverBinding(): void
    {
        $attribute = new RequestData();
        $this->assertInstanceOf(ResolvedBy::class, $attribute);
        $this->assertSame(RequestDataResolver::class, $attribute->getResolver());
    }

    public function testRequestQueryResolverBinding(): void
    {
        $attribute = new RequestQuery();
        $this->assertInstanceOf(ResolvedBy::class, $attribute);
        $this->assertSame(RequestQueryResolver::class, $attribute->getResolver());
    }

    public function testRequestBodyResolverBinding(): void
    {
        $attribute = new RequestBody();
        $this->assertInstanceOf(ResolvedBy::class, $attribute);
        $this->assertSame(RequestBodyResolver::class, $attribute->getResolver());
    }
}
