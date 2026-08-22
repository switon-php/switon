<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Switon\Ai\AgentInterface;
use Switon\Browser\Exception;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\HeadedBrowsers;
use Switon\Core\MakerInterface;
use Switon\Testing\TestCase;

#[CoversClass(HeadedBrowsers::class)]
class HeadedBrowsersTest extends TestCase
{
    #[Test]
    public function openReusesOneIdAndIsolatesAnotherIdAndAnotherAgent(): void
    {
        $main = $this->createStub(HeadedBrowserInterface::class);
        $docs = $this->createStub(HeadedBrowserInterface::class);
        $other = $this->createStub(HeadedBrowserInterface::class);
        $made = [$main, $docs, $other];
        $maker = $this->createStub(MakerInterface::class);
        $maker->method('make')->willReturnCallback(static function () use (&$made): HeadedBrowserInterface {
            return array_shift($made) ?? throw new RuntimeException('no more tabs');
        });
        $tabs = $this->make(HeadedBrowsers::class, ['maker' => $maker]);
        $a = $this->createStub(AgentInterface::class);
        $b = $this->createStub(AgentInterface::class);

        $this->assertSame($main, $tabs->open($a, 'main'));
        $this->assertSame($main, $tabs->tab($a, ' main '));
        $this->assertSame($docs, $tabs->open($a, 'docs'));
        $this->assertSame($other, $tabs->open($b, 'main'));
        $this->assertSame([], $made);
    }

    #[Test]
    public function openReusesTheSameIdWithoutCreatingASecondTab(): void
    {
        $only = $this->createStub(HeadedBrowserInterface::class);
        $made = 0;
        $maker = $this->createStub(MakerInterface::class);
        $maker->method('make')->willReturnCallback(static function () use ($only, &$made): HeadedBrowserInterface {
            $made++;

            return $only;
        });
        $tabs = $this->make(HeadedBrowsers::class, ['maker' => $maker]);
        $agent = $this->createStub(AgentInterface::class);

        $this->assertSame($only, $tabs->open($agent, 'main'));
        $this->assertSame($only, $tabs->open($agent, 'main'));
        $this->assertSame(1, $made, 'navigating the same id again must reuse the tab');
    }

    #[Test]
    public function tabRejectsANameThatWasNeverOpened(): void
    {
        $tabs = $this->make(HeadedBrowsers::class, ['maker' => $this->createStub(MakerInterface::class)]);

        $this->expectException(Exception::class);
        $tabs->tab($this->createStub(AgentInterface::class), 'docs');
    }

    #[Test]
    public function openRejectsAnEmptyId(): void
    {
        $tabs = $this->make(HeadedBrowsers::class, ['maker' => $this->createStub(MakerInterface::class)]);

        $this->expectException(Exception::class);
        $tabs->open($this->createStub(AgentInterface::class), '  ');
    }

    #[Test]
    public function closeDropsThatIdOnly(): void
    {
        $main = $this->createMock(HeadedBrowserInterface::class);
        $main->expects($this->once())->method('closePage');
        $docs = $this->createMock(HeadedBrowserInterface::class);
        $docs->expects($this->never())->method('closePage');
        $queue = [$main, $docs];
        $maker = $this->createStub(MakerInterface::class);
        $maker->method('make')->willReturnCallback(static function () use (&$queue): HeadedBrowserInterface {
            return array_shift($queue) ?? throw new RuntimeException('no more tabs');
        });
        $tabs = $this->make(HeadedBrowsers::class, ['maker' => $maker]);
        $agent = $this->createStub(AgentInterface::class);
        $tabs->open($agent, 'main');
        $tabs->open($agent, 'docs');

        $closed = $tabs->close($agent, 'main');

        $this->assertSame('main', $closed, 'close reports the stored id');
        $this->assertSame($docs, $tabs->tab($agent, 'docs'));
        $this->expectException(Exception::class);
        $tabs->tab($agent, 'main');
    }

    #[Test]
    public function closeRejectsAnUnknownId(): void
    {
        $tabs = $this->make(HeadedBrowsers::class, ['maker' => $this->createStub(MakerInterface::class)]);

        $this->expectException(Exception::class);
        $tabs->close($this->createStub(AgentInterface::class), 'gone');
    }

    #[Test]
    public function closeClearsTheAgentEntryWhenThatWasItsLastTab(): void
    {
        $only = $this->createMock(HeadedBrowserInterface::class);
        $only->expects($this->once())->method('closePage');
        $maker = $this->createStub(MakerInterface::class);
        $maker->method('make')->willReturn($only);
        $tabs = $this->make(HeadedBrowsers::class, ['maker' => $maker]);
        $agent = $this->createStub(AgentInterface::class);
        $tabs->open($agent, 'main');

        $tabs->close($agent, 'main');

        $this->expectException(Exception::class);
        $tabs->tab($agent, 'main');
    }
}
