<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Switon\Http\Server\Event\ServerConnect;
use Switon\Http\Server\Event\ServerFinish;
use Switon\Http\Server\Event\ServerPacket;
use Switon\Http\Server\Event\ServerPipeMessage;
use Switon\Http\Server\Event\ServerReady;
use Switon\Http\Server\Event\ServerStart;
use Switon\Http\Server\Event\ServerStarted;
use Switon\Http\Server\Event\ServerTask;
use Switon\Http\Server\Event\ServerTaskerError;
use Switon\Http\Server\Event\ServerTaskerStart;
use Switon\Http\Server\Event\ServerTaskerStop;
use Switon\Http\Server\Event\ServerWorkerError;
use Switon\Http\Server\Event\ServerWorkerStart;
use Switon\Http\Server\Event\ServerWorkerStop;

#[RequiresPhpExtension('swoole')]
class ServerEventJsonTest extends TestCase
{
    public function testServerEventsExposeJsonPayloads(): void
    {
        $server = new ServerEventFakeWebSocketServer();
        $server->setting = ['worker_num' => 2];

        $events = [
            new ServerConnect($server, 1, 0),
            new ServerPacket($server, 'payload', ['fd' => 1]),
            new ServerStart($server),
            new ServerStarted('ready', $server, 0.1, '127.0.0.1', 8080, 'test', PHP_VERSION, SWOOLE_VERSION, 2, 9),
            new ServerReady($server, '127.0.0.1', 8080, []),
            new ServerWorkerStart($server, 0, 2),
            new ServerTaskerStart($server, 2, 0),
            new ServerWorkerStop($server, 0, 2),
            new ServerTaskerStop($server, 2, 0),
            new ServerTask($server, 1, 0, ['job' => true]),
            new ServerFinish($server, 1, 'ok'),
            new ServerPipeMessage($server, 0, 'hello'),
            new ServerWorkerError($server, 0, 9, 1, 11),
            new ServerTaskerError($server, 2, 0, 10, 1, 9),
        ];

        foreach ($events as $event) {
            $encoded = json_encode($event, JSON_THROW_ON_ERROR);
            $this->assertNotSame('', $encoded);
            $this->assertIsArray(json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
        }
    }
}

class ServerEventFakeWebSocketServer extends \Swoole\WebSocket\Server
{
    public function __construct()
    {
    }
}
