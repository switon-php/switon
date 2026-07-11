<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Filters;

use Switon\Core\Attribute\Autowired;
use Switon\Http\Event\RequestReceived;
use Switon\Http\Filter\MappingValidatorFilter;
use Switon\Http\RequestInterface;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\Tests\Fixtures\MappingValidatorActionFixture;
use Switon\Http\Tests\Support\RequestDispatchHarness;
use Switon\Http\Tests\TestCase;
use Switon\Routing\Exception\MethodNotAllowedException;

class MappingValidatorFilterTest extends TestCase
{
    #[Autowired] protected MappingValidatorFilter $filter;
    #[Autowired] protected RequestInterface $request;

    private MappingValidatorActionFixture $actions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actions = new MappingValidatorActionFixture();
    }

    protected function createRequestEvent(
        array $get = [],
        array $post = [],
        array $server = [],
        string $rawBody = '',
        array $cookie = [],
        array $files = []
    ): RequestReceived {
        return new RequestReceived(
            sender: $this->createStub(\Switon\Http\ServerInterface::class),
            GET: $get,
            POST: $post,
            SERVER: $server,
            RAW_BODY: $rawBody,
            COOKIE: $cookie,
            FILES: $files
        );
    }

    public function testOnValidatingAllowsRequestWhenMethodMatchesAttribute(): void
    {
        $requestEvent = $this->createRequestEvent(
            server: ['REQUEST_METHOD' => 'GET']
        );
        $this->request->onRequestReceived($requestEvent);

        $event = RequestDispatchHarness::validatingEvent(
            $this->createStub(RequestHandlerInterface::class),
            $this->actions,
            'getOnlyAction',
        );

        $this->filter->onValidating($event);
        $this->assertTrue(true);
    }

    public function testOnValidatingThrowsExceptionWhenMethodDoesNotMatchAttribute(): void
    {
        $requestEvent = $this->createRequestEvent(
            server: ['REQUEST_METHOD' => 'POST']
        );
        $this->request->onRequestReceived($requestEvent);

        $event = RequestDispatchHarness::validatingEvent(
            $this->createStub(RequestHandlerInterface::class),
            $this->actions,
            'getOnlyAction',
        );

        $this->expectException(MethodNotAllowedException::class);
        $this->filter->onValidating($event);
    }

    public function testOnValidatingDoesNothingWhenMethodHasNoMappingAttributes(): void
    {
        $requestEvent = $this->createRequestEvent(
            server: ['REQUEST_METHOD' => 'GET']
        );
        $this->request->onRequestReceived($requestEvent);

        $event = RequestDispatchHarness::validatingEvent(
            $this->createStub(RequestHandlerInterface::class),
            $this->actions,
            'noMappingAction',
        );

        $this->filter->onValidating($event);
        $this->assertTrue(true);
    }

    public function testOnValidatingAllowsRequestWhenMethodMatchesOneOfMultipleAttributes(): void
    {
        $requestEvent = $this->createRequestEvent(
            server: ['REQUEST_METHOD' => 'POST']
        );
        $this->request->onRequestReceived($requestEvent);

        $event = RequestDispatchHarness::validatingEvent(
            $this->createStub(RequestHandlerInterface::class),
            $this->actions,
            'getOrPostAction',
        );

        $this->filter->onValidating($event);
        $this->assertTrue(true);
    }
}
