<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Attribute;

use Psr\Container\ContainerInterface;
use Switon\Binding\Attribute\ResolvedBy;
use Switon\Binding\ObjectResolver;
use Switon\Http\Attribute\RequestBody;
use Switon\Http\RequestBodyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Test cases for RequestBody attribute class.
 */
class RequestBodyTest extends TestCase
{
    /**
     * RequestBody is discoverable through the inherited #[ResolvedBy] contract.
     *
     * Value resolvers are wired by inheritance, so discovery -- not the attribute declaration --
     * is what callers depend on.
     */
    public function testRequestBodyIsDiscoverableAsResolverBinding(): void
    {
        $resolver = new class ($this->createStub(ContainerInterface::class)) extends ObjectResolver {
            public function __construct(ContainerInterface $container)
            {
                $this->container = $container;
            }
        };

        $this->assertTrue($resolver->hasResolver(RequestBodyInput::class));
    }

    /**
     * Test that RequestBody attribute instance can be created.
     */
    public function testRequestBodyAttributeInstance(): void
    {
        $attribute = new RequestBody();
        $this->assertInstanceOf(RequestBody::class, $attribute);
        $this->assertInstanceOf(ResolvedBy::class, $attribute);
        $this->assertSame(RequestBodyResolver::class, $attribute->getResolver());
    }
}

#[RequestBody]
class RequestBodyInput
{
    public string $name = '';
}
