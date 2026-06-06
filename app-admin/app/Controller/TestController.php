<?php

declare(strict_types=1);

namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Http\SseEvent;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

use function sleep;

#[Authorize]
#[RequestMapping('/test')]
class TestController extends Controller
{
    #[GetMapping]
    public function indexAction(): void
    {

    }

    #[Authorize(Authorize::ANONYMOUS)]
    #[GetMapping]
    public function chunkAction(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->response->write("chunk $i\n");
            sleep(1);
        }
    }

    #[Authorize(Authorize::ANONYMOUS)]
    #[GetMapping]
    public function sseAction(): void
    {
        $this->response->setHeader('Content-Type', 'text/event-stream');
        $this->response->setHeader('Connection', 'keep-alive');
        $this->response->setHeader('Cache-Control', 'no-cache');

        for ($i = 0; $i < 10; $i++) {
            $this->response->write(new SseEvent(['id' => $i, 'data' => "hello $i"]));
            sleep(1);
        }
    }
}
