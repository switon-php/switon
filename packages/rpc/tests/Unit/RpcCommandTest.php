<?php

declare(strict_types=1);

namespace Switon\Rpc\Tests\Unit;

use Switon\Core\ConsoleInterface;
use Switon\Rpc\Command\RpcCommand;
use Switon\Rpc\RpcInterface;
use PHPUnit\Framework\TestCase;

class RpcCommandTest extends TestCase
{
    public function testDaemonActionInvokesRpcDaemonAndReturnsZero(): void
    {
        $rpc = $this->createMock(RpcInterface::class);
        $rpc->expects($this->once())->method('daemon');

        $command = new RpcCommandProbe();
        $command->setDependencies($this->createStub(ConsoleInterface::class), $rpc);

        $this->assertSame(0, $command->daemonAction());
    }
}

class RpcCommandProbe extends RpcCommand
{
    public function setDependencies(ConsoleInterface $console, RpcInterface $rpc): void
    {
        $this->console = $console;
        $this->rpc = $rpc;
    }
}
