<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Launcher;

/**
 * Process ownership is per debugging port.
 *
 * One container resolves a single launcher for both the headless and the headed service, so
 * "I own a browser" has to mean "I started the browser on this port".
 */
#[CoversClass(Launcher::class)]
class LauncherOwnershipTest extends TestCase
{
    #[Test]
    public function anUnownedPortReportsNoOwnership(): void
    {
        $launcher = $this->launcher();

        $this->assertFalse($launcher->ownsProcess(59222));
        $this->assertFalse($launcher->ownsProcess(59223));
    }

    #[Test]
    public function ownershipDoesNotLeakBetweenPorts(): void
    {
        $launcher = $this->launcher();
        $launcher->seed(59222, getmypid());

        $this->assertTrue($launcher->ownsProcess(59222));
        $this->assertFalse($launcher->ownsProcess(59223), 'a pid owned on one port must not own another');
    }

    #[Test]
    public function stoppingOnePortKeepsTheOtherPortOwned(): void
    {
        $launcher = $this->launcher();
        $launcher->seed(59222, getmypid());
        $launcher->seed(59223, getmypid());

        // getmypid() is this test process: stopping it would end the run, so only the bookkeeping
        // of the other port is asserted through the pid map.
        $launcher->forget(59222);

        $this->assertFalse($launcher->ownsProcess(59222));
        $this->assertTrue($launcher->ownsProcess(59223), 'closing one mode must not release the other');
    }

    private function launcher(): Launcher
    {
        return new class () extends Launcher {
            public function __construct()
            {
            }

            public function seed(int $port, int $pid): void
            {
                $this->ownedPids[$port] = $pid;
            }

            public function forget(int $port): void
            {
                unset($this->ownedPids[$port]);
            }
        };
    }
}
