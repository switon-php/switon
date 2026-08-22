<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Switon\Cdp\Chrome;
use Switon\Cdp\HeadedChrome;
use Switon\Cdp\HeadlessChrome;
use Switon\Cdp\Launcher;
use Switon\Core\Clock;

/**
 * Mode policy hooks: which tab `open()` focuses, and whether a foreign listener is reclaimed.
 *
 * Both live on the subclass, so the base class never asks which mode it is.
 */
#[CoversClass(Chrome::class)]
#[CoversClass(HeadlessChrome::class)]
#[CoversClass(HeadedChrome::class)]
class ChromeModePolicyTest extends TestCase
{
    #[Test]
    public function headlessKeepsThePreviousTabInFront(): void
    {
        $chrome = $this->headless();

        $this->assertSame('previous', $this->focusTarget($chrome, 'previous', 'opened'));
    }

    #[Test]
    public function headlessFocusesNothingWhenThereWasNoOtherTab(): void
    {
        $chrome = $this->headless();

        $this->assertSame('', $this->focusTarget($chrome, '', 'opened'));
        $this->assertSame('', $this->focusTarget($chrome, 'opened', 'opened'));
    }

    #[Test]
    public function headedShowsTheTabItJustOpened(): void
    {
        $chrome = $this->headed();

        $this->assertSame('opened', $this->focusTarget($chrome, 'previous', 'opened'));
        $this->assertSame('opened', $this->focusTarget($chrome, '', 'opened'));
    }

    #[Test]
    public function headedNeverReclaimsAForeignListener(): void
    {
        $launcher = $this->launcher();
        $chrome = $this->headed($launcher);

        $this->assertFalse($this->reclaim($chrome));
        $this->assertSame([], $launcher->calls, 'headed must not inspect or quit a window it did not open');
    }

    #[Test]
    public function headedRefusesAPortHeldByAHeadlessChrome(): void
    {
        $launcher = $this->launcher();
        $launcher->listenerHeadless = true;
        $chrome = $this->headed($launcher);

        $this->expectException(\Switon\Cdp\Exception::class);
        $this->expectExceptionMessageMatches('/held by a headless Chrome/');
        $chrome->open('about:blank');
    }

    #[Test]
    public function headedAcceptsItsOwnVisibleListener(): void
    {
        $launcher = $this->launcher();
        $launcher->listenerHeadless = false;
        $chrome = $this->headed($launcher);

        // A visible window on the port is the attach path. The fake launcher answers no DevTools
        // request, so `open()` fails later — the point is that the mode guard is not what stopped it.
        try {
            $chrome->open('about:blank');
            $this->fail('The fake listener exposes no page target.');
        } catch (\Switon\Cdp\Exception $e) {
            $this->assertStringNotContainsString('held by a headless Chrome', $e->getMessage());
            $this->assertStringContainsString('no page target', $e->getMessage());
        }
    }

    #[Test]
    public function headedFocusRaisesTheWindow(): void
    {
        $launcher = $this->launcher();
        $chrome = $this->headed($launcher);

        $chrome->focusTarget('tab-1');

        $this->assertContains('GET http://127.0.0.1:59223/json/activate/tab-1', $chrome->requests);
        $this->assertContains('GET http://127.0.0.1:59223/json/version', $chrome->requests);
        $this->assertContains('foreground:59223', $launcher->calls);
    }

    #[Test]
    public function headedFocusOfAMissingTargetDoesNotRaiseAWindow(): void
    {
        $launcher = $this->launcher();
        $chrome = $this->headed($launcher);

        $chrome->focusTarget('   ');

        $this->assertSame([], $launcher->calls);
    }

    #[Test]
    public function headlessFocusDoesNotRaiseAWindow(): void
    {
        $launcher = $this->launcher();
        $chrome = $this->headless($launcher);

        $chrome->focusTarget('tab-1');

        $this->assertNotContains('foreground:59222', $launcher->calls);
    }

    #[Test]
    public function headlessLeavesAnUnknownListenerAlone(): void
    {
        $launcher = $this->launcher();
        $launcher->listenerHeadless = null;
        $chrome = $this->headless($launcher);

        $this->assertFalse($this->reclaim($chrome));
        $this->assertSame([], $chrome->requests, 'an unreadable command line must not be treated as headed');
    }

    #[Test]
    public function headlessLeavesAHeadlessListenerAlone(): void
    {
        $launcher = $this->launcher();
        $launcher->listenerHeadless = true;
        $chrome = $this->headless($launcher);

        $this->assertFalse($this->reclaim($chrome));
        $this->assertSame([], $chrome->requests);
    }

    #[Test]
    public function headlessLeavesAVisibleWindowWithTabsAlone(): void
    {
        $launcher = $this->launcher();
        $launcher->listenerHeadless = false;
        $chrome = $this->headless($launcher, static fn (): ?array => [
            ['type' => 'page', 'id' => 'login-tab', 'url' => 'https://example.com/account'],
        ]);

        $this->assertFalse($this->reclaim($chrome), 'a window on a real page is the login-reuse path');
        $this->assertNotContains('GET http://127.0.0.1:59222/json/version', $chrome->requests);
    }

    #[Test]
    public function headlessQuitsAFreshVisibleWindowAndRelaunches(): void
    {
        $launcher = $this->launcher();
        $launcher->listenerHeadless = false;
        $launcher->open = false;
        // A freshly launched window always holds one blank tab; that still counts as unused.
        $chrome = $this->headless($launcher, static fn (string $url): ?array => str_contains($url, '/json/list')
            ? [['type' => 'page', 'id' => 'blank', 'url' => 'about:blank']]
            : null);

        $this->assertTrue($this->reclaim($chrome));
        $this->assertContains('GET http://127.0.0.1:59222/json/version', $chrome->requests);
    }

    #[Test]
    public function headlessLeavesAWindowAloneWhenTheTargetListIsUnreadable(): void
    {
        $launcher = $this->launcher();
        $launcher->listenerHeadless = false;
        $chrome = $this->headless($launcher, static fn (): ?array => null);

        $this->assertFalse($this->reclaim($chrome), 'an unknown state must not cost someone their window');
        $this->assertNotContains('GET http://127.0.0.1:59222/json/version', $chrome->requests);
    }

    #[Test]
    public function closeLeavesABrowserItDidNotStartRunning(): void
    {
        $launcher = $this->launcher();
        $launcher->owns = false;
        $chrome = $this->headless($launcher);

        $chrome->close();

        $this->assertSame([], $chrome->requests, 'must not send Browser.close to a browser it attached to');
        $this->assertNotContains('stopOwnedProcess', $launcher->calls);
        $this->assertContains('ownsProcess:59222', $launcher->calls, 'ownership is asked for this port');
    }

    #[Test]
    public function closeQuitsTheBrowserItStarted(): void
    {
        $launcher = $this->launcher();
        $launcher->owns = true;
        $chrome = $this->headless($launcher);

        $chrome->close();

        $this->assertContains('GET http://127.0.0.1:59222/json/version', $chrome->requests);
        $this->assertContains('stopOwnedProcess', $launcher->calls);
    }

    protected function focusTarget(Chrome $chrome, string $previousId, string $targetId): string
    {
        $method = new ReflectionMethod($chrome, 'tabToFocusAfterOpen');
        $method->setAccessible(true);

        return (string) $method->invoke($chrome, $previousId, $targetId);
    }

    protected function reclaim(Chrome $chrome): bool
    {
        $method = new ReflectionMethod($chrome, 'reclaimForeignListener');
        $method->setAccessible(true);

        return (bool) $method->invoke($chrome);
    }

    /**
     * @param null|callable(string): ?array $responder
     */
    protected function headless(?Launcher $launcher = null, ?callable $responder = null): HeadlessChrome
    {
        $chrome = new class () extends HeadlessChrome {
            /** @var list<string> */
            public array $requests = [];

            /** @var null|callable(string): ?array */
            public $responder = null;

            protected function httpJson(string $url, float $timeout, string $method = 'GET'): ?array
            {
                $this->requests[] = $method . ' ' . $url;
                $responder = $this->responder;

                return $responder === null ? null : $responder($url);
            }
        };
        $chrome->responder = $responder;
        $this->inject($chrome, 'launcher', $launcher ?? $this->launcher());
        $this->inject($chrome, 'clock', new Clock());

        return $chrome;
    }

    protected function headed(?Launcher $launcher = null): HeadedChrome
    {
        $chrome = new class () extends HeadedChrome {
            /** @var list<string> */
            public array $requests = [];

            protected function httpJson(string $url, float $timeout, string $method = 'GET'): ?array
            {
                $this->requests[] = $method . ' ' . $url;

                return null;
            }
        };
        $this->inject($chrome, 'launcher', $launcher ?? $this->launcher());
        $this->inject($chrome, 'clock', new Clock());

        return $chrome;
    }

    protected function launcher(): Launcher
    {
        return new class () extends Launcher {
            /** @var list<string> */
            public array $calls = [];

            public ?bool $listenerHeadless = null;

            public bool $open = true;

            public bool $owns = false;

            public function __construct()
            {
            }

            public function listenerIsHeadless(int $port): ?bool
            {
                $this->calls[] = 'listenerIsHeadless';

                return $this->listenerHeadless;
            }

            public function portOpen(int $port): bool
            {
                $this->calls[] = 'portOpen';

                return $this->open;
            }

            public function ownsProcess(int $port): bool
            {
                $this->calls[] = 'ownsProcess:' . $port;

                return $this->owns;
            }

            public function stopOwnedProcess(int $port): void
            {
                $this->calls[] = 'stopOwnedProcess';
            }

            public function foreground(int $port): void
            {
                $this->calls[] = 'foreground:' . $port;
            }
        };
    }

    protected function inject(object $target, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($target, $property);
        $ref->setAccessible(true);
        $ref->setValue($target, $value);
    }
}
