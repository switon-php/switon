<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Fixtures;

use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Http\ResponseInterface;
use Switon\Mcp\HttpServerInterface;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/mcp')]
class TestMcpController extends Controller
{
    #[Autowired] protected HttpServerInterface $httpServer;

    #[PostMapping('')]
    public function indexAction(): ResponseInterface
    {
        return $this->httpServer->handle();
    }
}
