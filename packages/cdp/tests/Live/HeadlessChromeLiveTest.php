<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Switon\Cdp\Exception;
use Switon\Cdp\HeadlessChrome;
use Switon\Cdp\Launcher;
use Switon\Cdp\Paths;
use Switon\Core\WebFetcherInterface;
use Switon\Core\Clock;
use Switon\Core\Filesystem;
use Switon\Core\PathAlias;

use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fsockopen;
use function is_array;
use function is_int;
use function is_resource;
use function json_decode;
use function json_encode;
use function microtime;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function str_starts_with;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const JSON_THROW_ON_ERROR;

/**
 * Live headless Chrome: open a local page, read it, and POST from that origin.
 *
 * Skips when Chrome is missing. Profile and debugging port are ephemeral.
 *
 * Guidance: one browser and one page server serve the whole class. A launch costs about a
 * second, so paying it per test would dominate the suite; only
 * {@see HeadlessChromeLiveTest::closeStopsTheDebuggingPort()} owns a browser of its own.
 */
class HeadlessChromeLiveTest extends TestCase
{
    /** Browser, page server, and profile shared by every test in this class. */
    protected static ?Launcher $sharedLauncher = null;

    /** @var resource|null */
    protected static $sharedServer = null;

    protected static string $sharedBaseUrl = '';

    protected static string $sharedCapture = '';

    protected static string $sharedUserDataDir = '';

    protected static int $sharedPort = 0;

    /** The shared fixture, handed to each test as its own properties. */
    protected ?Launcher $launcher = null;

    protected string $baseUrl = '';

    protected string $capture = '';

    protected string $userDataDir = '';

    protected int $port = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (Paths::detect() === '') {
            return;
        }

        self::$sharedUserDataDir = sys_get_temp_dir() . '/switon-cdp-headless-' . getmypid();
        self::$sharedPort = self::pickFreePort();
        self::$sharedLauncher = self::bootLauncher();
        self::$sharedBaseUrl = self::startSharedServer();

        // Launch here, so the cost lands on the class instead of on whichever test runs first.
        self::makeChrome(self::$sharedLauncher, self::$sharedUserDataDir, self::$sharedPort)
            ->open(self::$sharedBaseUrl)
            ->close();
    }

    public static function tearDownAfterClass(): void
    {
        self::$sharedLauncher?->stopOwnedProcess(self::$sharedPort);
        self::$sharedLauncher = null;

        if (is_resource(self::$sharedServer)) {
            proc_terminate(self::$sharedServer);
            proc_close(self::$sharedServer);
            self::$sharedServer = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (Paths::detect() === '') {
            $this->markTestSkipped('Chrome/Chromium not installed.');
        }
        if (self::$sharedLauncher === null || self::$sharedBaseUrl === '') {
            $this->markTestSkipped('The shared Chrome fixture did not start.');
        }

        $this->launcher = self::$sharedLauncher;
        $this->userDataDir = self::$sharedUserDataDir;
        $this->port = self::$sharedPort;
        $this->baseUrl = self::$sharedBaseUrl;
        $this->capture = self::$sharedCapture;

        // The capture file describes one POST; a leftover would let this test read an earlier one.
        @unlink($this->capture);
    }

    #[Test]
    public function waitReadsTheElementAfterItAppears(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'late');
        try {
            $page->wait('p#ready');
            $html = $page->content();
        } finally {
            $page->close();
        }

        $this->assertStringContainsString('appeared', $html);
    }

    #[Test]
    public function fillSubmitSendsARealEnterThatSubmitsTheForm(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'submit');
        try {
            $page->fill('input#name', 'ada', true);
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && !str_contains($page->content(), 'submitted:ada')) {
                usleep(50_000);
            }
            $html = $page->content();
        } finally {
            $page->close();
        }

        $this->assertStringContainsString('submitted:ada', $html);
    }

    #[Test]
    public function clickRefusesAnElementCoveredByAnotherNode(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'covered');
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessageMatches('/covered by/');
            $page->click('#target');
        } finally {
            $page->close();
        }
    }

    #[Test]
    public function fillReplacesContentEditableInsteadOfAppending(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'editable');
        try {
            $page->fill('#note', 'REPLACED');
            $html = $page->content();
        } finally {
            $page->close();
        }

        $this->assertStringContainsString('>REPLACED<', $html);
        $this->assertStringNotContainsString('hello world', $html);
    }

    #[Test]
    public function fetchRefererDoesNotStickToTheNextRequest(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl);
        try {
            $first = $page->fetch('GET', $this->baseUrl . 'echo', ['Referer' => $this->baseUrl . 'from']);
            $second = $page->fetch('GET', $this->baseUrl . 'echo');
        } finally {
            $page->close();
        }

        $sent = json_decode($first['body'], true, 512, JSON_THROW_ON_ERROR);
        $leaked = json_decode($second['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($sent);
        $this->assertIsArray($leaked);
        $this->assertSame($this->baseUrl . 'from', $sent['referer'] ?? null);
        $this->assertNotSame($this->baseUrl . 'from', $leaked['referer'] ?? null, 'the Referer must not outlive its fetch');
    }

    #[Test]
    public function openSurfacesANetworkFailureInsteadOfAnErrorPage(): void
    {
        $chrome = $this->chrome();
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/failed: net::ERR_/');
        $chrome->open('http://127.0.0.1:1/');
    }

    #[Test]
    public function diagnosticsReportConsoleErrorsExceptionsAndFailedRequests(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'broken');
        try {
            // The page throws on a timer and fetches a missing endpoint; both land asynchronously.
            $deadline = microtime(true) + 5;
            $entries = [];
            while (microtime(true) < $deadline) {
                $entries = $page->diagnostics();
                $text = json_encode($entries);
                if (str_contains((string) $text, 'BOOM') && str_contains((string) $text, '404')) {
                    break;
                }
                usleep(100_000);
            }
        } finally {
            $page->close();
        }

        $text = (string) json_encode($entries);
        $this->assertStringContainsString('boot: starting', $text, 'console.log is kept');
        $this->assertStringContainsString('fatal: config missing', $text, 'console.error is kept');
        $this->assertStringContainsString('BOOM', $text, 'an uncaught exception is kept');
        $this->assertStringContainsString('404', $text, 'a failed request is kept');
    }

    #[Test]
    public function diagnosticsResetOnNavigate(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'broken');
        try {
            usleep(600_000);
            $this->assertNotSame([], $page->diagnostics());
            $page->navigate($this->baseUrl);
            $this->assertSame([], $page->diagnostics(), 'lines from the previous document must not leak');
        } finally {
            $page->close();
        }
    }

    #[Test]
    public function anAlertDoesNotBlockTheNextCall(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'dialog');
        try {
            $page->click('#go');
            $text = $page->evaluate("document.getElementById('log').textContent");
        } finally {
            $page->close();
        }

        $this->assertSame('AFTER-ALERT', $text, 'the dialog was accepted, so the handler finished');
    }

    #[Test]
    public function evaluateRunsScriptAndRaisesPageErrors(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl);
        try {
            $this->assertSame(2, $page->evaluate('1 + 1'));
            $this->assertSame('v', $page->evaluate('(localStorage.setItem("k","v"), localStorage.getItem("k"))'));
            try {
                $page->evaluate('null.boom');
                $this->fail('A page exception must surface as an error.');
            } catch (Exception $e) {
                $this->assertStringContainsString('TypeError', $e->getMessage());
            }
        } finally {
            $page->close();
        }
    }

    #[Test]
    public function inspectReportsComputedStylesAndTheBox(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'styled');
        try {
            $hidden = $page->inspect('#hid');
            $visible = $page->inspect('#ok', ['color']);
        } finally {
            $page->close();
        }

        $this->assertSame('none', $hidden['styles']['display'] ?? null);
        $this->assertFalse($hidden['visible']);
        $this->assertSame('rgb(255, 0, 0)', $visible['styles']['color'] ?? null);
        $this->assertTrue($visible['visible']);
    }

    #[Test]
    public function aShadowRootNodeIsReachableThroughItsBackendNodeId(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'shadow');
        try {
            $backendId = $this->firstInteractiveBackendId($page->accessibilityTree());
            $this->assertGreaterThan(0, $backendId);

            $page->click('#' . $backendId);
            $log = $page->evaluate("document.getElementById('log').textContent");
        } finally {
            $page->close();
        }

        $this->assertSame('SHADOW-CLICKED', $log, 'a CSS selector for this node matches nothing');
    }

    #[Test]
    public function framesAreListedAndTheirContentIsReadable(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'frames');
        try {
            $frames = $page->frames();
            $same = null;
            foreach ($frames as $frame) {
                if (str_contains($frame['url'], 'frames-inner')) {
                    $same = $frame;
                }
            }
            $this->assertIsArray($same, 'the child frame must be listed');
            $this->assertTrue($same['sameOrigin']);
            $tree = $page->frameSnapshot($same);
        } finally {
            $page->close();
        }

        $this->assertStringContainsString('frame button', (string) json_encode($tree));
    }

    /**
     * @param array<string, mixed> $ax
     */
    protected function firstInteractiveBackendId(array $ax): int
    {
        foreach (($ax['nodes'] ?? []) as $node) {
            if (!is_array($node) || ($node['role']['value'] ?? '') !== 'button') {
                continue;
            }
            $id = $node['backendDOMNodeId'] ?? null;
            if (is_int($id) && $id > 0) {
                return $id;
            }
        }

        return 0;
    }

    #[Test]
    public function aNodeInsideACrossOriginFrameIsClickableAndFillable(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'xo-parent');
        try {
            // The child is served from `localhost`, so Chrome puts it in its own process.
            $frame = null;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                foreach ($page->frames() as $candidate) {
                    if (!$candidate['sameOrigin'] && $candidate['ownerBackendNodeId'] > 0) {
                        $frame = $candidate;
                        break 2;
                    }
                }
                usleep(200_000);
            }
            $this->assertIsArray($frame, 'the cross-origin frame must be discovered with its owner id');

            $tree = $page->frameSnapshot($frame);
            $buttonId = 0;
            $inputId = 0;
            foreach (($tree['nodes'] ?? []) as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $role = $node['role']['value'] ?? '';
                $id = $node['backendDOMNodeId'] ?? null;
                if (!is_int($id) || $id <= 0) {
                    continue;
                }
                if ($role === 'button') {
                    $buttonId = $id;
                }
                if ($role === 'textbox') {
                    $inputId = $id;
                }
            }
            $this->assertGreaterThan(0, $buttonId, 'a button inside the frame must be reachable');
            $this->assertGreaterThan(0, $inputId, 'an input inside the frame must be reachable');

            $prefix = '#' . $frame['ownerBackendNodeId'] . ':' . $frame['sessionId'] . '/';
            $page->fill($prefix . $inputId, '4111', true);
            $page->click($prefix . $buttonId);
            usleep(600_000);

            $state = $page->frameEvaluate($frame['sessionId'], "document.getElementById('st').textContent");
            $value = $page->frameEvaluate($frame['sessionId'], "document.getElementById('card').value");
        } finally {
            $page->close();
        }

        $this->assertSame('4111', $value, 'typing reached the input in the other document');
        $this->assertSame('PAID:4111', $state, 'the click ran the handler in the other document');
    }

    #[Test]
    public function inspectReadsStylesForANodeInACrossOriginFrame(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'xo-parent');
        try {
            $frame = null;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                foreach ($page->frames() as $candidate) {
                    if (!$candidate['sameOrigin'] && $candidate['ownerBackendNodeId'] > 0) {
                        $frame = $candidate;
                        break 2;
                    }
                }
                usleep(200_000);
            }
            $this->assertIsArray($frame);

            $tree = $page->frameSnapshot($frame);
            $buttonId = $this->firstInteractiveBackendId($tree);
            $this->assertGreaterThan(0, $buttonId);

            $prefix = '#' . $frame['ownerBackendNodeId'] . ':' . $frame['sessionId'] . '/';
            $styles = $page->inspect($prefix . $buttonId, ['display']);
        } finally {
            $page->close();
        }

        $this->assertSame('inline-block', $styles['styles']['display'] ?? null);
        $this->assertTrue($styles['visible']);
    }

    #[Test]
    public function aSmallStaticPageIsNotSlowedDownByTreeSettling(): void
    {
        $chrome = $this->chrome();
        $start = microtime(true);
        $page = $chrome->open($this->baseUrl);
        try {
            $tree = $page->accessibilityTree();
        } finally {
            $page->close();
        }
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(0, count($tree['nodes'] ?? []));
        // The tree of a rendered page agrees with itself on the second read, so the wait is one
        // pause, not the whole budget.
        $this->assertLessThan(8.0, $elapsed, 'a static page must not pay the settle budget');
    }

    #[Test]
    public function aPageThatMountsWithoutNetworkWorkIsCoveredByAWait(): void
    {
        // `networkIdle` cannot see a page that mounts on a timer alone: no request follows, so the
        // navigation legitimately returns the shell. The documented escape hatch is an explicit
        // `wait()`, which is what a caller uses when it knows the page renders late.
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'deferred');
        try {
            $page->wait('button#late');
            $tree = $page->accessibilityTree();
        } finally {
            $page->close();
        }

        $names = [];
        foreach (($tree['nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = (string) ($node['name']['value'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $this->assertContains('late button', $names, 'wait() is the explicit answer for a timer-mounted page');
    }

    #[Test]
    public function assertReportsEachConditionKind(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'assert-conditions');
        try {
            $cases = [
                ['#shown', 'visible', '', true],
                ['#hidden', 'hidden', '', true],
                ['#hidden', 'visible', '', false],
                ['#shown', 'text', 'visible text', true],
                ['#shown', 'text_contains', 'visible', true],
                ['#go', 'enabled', '', true],
                ['#off', 'enabled', '', false],
                ['#cb', 'checked', '', false],
            ];
            $outcomes = [];
            foreach ($cases as [$path, $kind, $expected, $shouldPass]) {
                $result = $page->assert($path, $kind, $expected, 0.2);
                $outcomes[] = [$path, $kind, $shouldPass, $result['ok'], $result['actual']];
            }
        } finally {
            $page->close();
        }

        foreach ($outcomes as [$path, $kind, $shouldPass, $ok, $actual]) {
            $this->assertSame(
                $shouldPass,
                $ok,
                sprintf('%s on %s should be %s, got %s', $kind, $path, $shouldPass ? 'true' : 'false', $actual),
            );
        }
    }

    #[Test]
    public function assertWaitsForAPageThatUpdatesAfterTheAction(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'assert-conditions');
        try {
            $this->assertTrue($page->assert('#status', 'text', 'idle', 0.5)['ok']);
            $page->click('#go');
            // The page rewrites the text 600ms later; a single sample would miss it.
            $result = $page->assert('#status', 'text', 'done', 5.0);
        } finally {
            $page->close();
        }

        $this->assertTrue($result['ok'], 'the assertion must keep checking until the page caught up');
        $this->assertGreaterThan(300, $result['waitedMs'], 'it should have actually waited for the update');
    }

    #[Test]
    public function assertGivesUpAndSaysWhatItFound(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'assert-conditions');
        try {
            $start = microtime(true);
            $result = $page->assert('#status', 'text', 'never happens', 0.5);
            $elapsed = microtime(true) - $start;
        } finally {
            $page->close();
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('idle', $result['actual'], 'the message must say what the page actually shows');
        $this->assertGreaterThan(0.4, $elapsed);
    }

    #[Test]
    public function assertReportsAMissingElementInsteadOfThrowing(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'assert-conditions');
        try {
            $result = $page->assert('#no-such-thing', 'visible', '', 0.2);
        } finally {
            $page->close();
        }

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no element matches', $result['actual']);
    }

    #[Test]
    public function assertReportsAValueAfterFilling(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'assert-conditions');
        try {
            $page->fill('#field', 'typed-value');
            $result = $page->assert('#field', 'value', 'typed-value', 1.0);
        } finally {
            $page->close();
        }

        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function clickFillAndScreenshotTheLocalForm(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl . 'form');
        try {
            $page->fill('input#name', 'ada');
            $page->click('button#go');
            $html = $page->content();
            $png = $page->screenshot();
            try {
                $page->click('   ');
                $this->fail('Empty selector should throw.');
            } catch (Exception $e) {
                $this->assertStringContainsString('must not be empty', $e->getMessage());
            }
            try {
                $page->click('button#missing');
                $this->fail('Missing selector should throw.');
            } catch (Exception $e) {
                $this->assertStringContainsString('matched nothing', $e->getMessage());
            }
        } finally {
            $page->close();
        }

        $this->assertStringContainsString('ada', $html);
        $this->assertStringContainsString('clicked', $html);
        $this->assertTrue(str_starts_with($png, "\x89PNG"));
    }

    #[Test]
    public function openReadsTheLocalDocument(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open($this->baseUrl);
        try {
            $html = $page->content();
            $page->activate();
        } finally {
            $page->close();
        }

        $this->assertStringContainsString('headless-ok', $html);
        $this->assertStringContainsString('<html', strtolower($html));
    }

    #[Test]
    public function requestGetReturnsTheDocument(): void
    {
        $response = $this->chrome()->request('GET', $this->baseUrl);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('headless-ok', $response['body']);
    }

    #[Test]
    public function requestGetKeepsADocumentErrorStatus(): void
    {
        $response = $this->chrome()->request('GET', $this->baseUrl . 'missing');

        $this->assertSame(404, $response['status']);
        $this->assertIsArray($response['headers']);
        $this->assertStringContainsString('missing-page', $response['body']);
    }

    #[Test]
    public function requestPostSendsMethodHeaderAndBody(): void
    {
        $response = $this->chrome()->request(
            'POST',
            $this->baseUrl . 'api',
            ['X-Switon-Mark' => 'cdp-live', 'Referer' => $this->baseUrl . 'from'],
            'hello-body',
            $this->baseUrl,
        );

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('true', $response['body']);

        $captured = json_decode((string) file_get_contents($this->capture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($captured);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('cdp-live', $captured['mark']);
        $this->assertSame('hello-body', $captured['body']);
        $this->assertSame($this->baseUrl . 'from', $captured['referer']);
    }

    #[Test]
    public function fetchSendsMethodHeadersAndBody(): void
    {
        // The portable contract: a caller that only knows `WebFetcherInterface` can still POST.
        $response = $this->chrome()->fetch(
            $this->baseUrl . 'api',
            'POST',
            ['X-Switon-Mark' => 'core-contract', 'Referer' => $this->baseUrl . 'from'],
            'body-via-contract',
            [
                WebFetcherInterface::ORIGIN => $this->baseUrl,
                WebFetcherInterface::TIMEOUT => 30.0,
            ],
        );

        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['headers']);
        $this->assertStringContainsString('true', $response['body']);

        $captured = json_decode((string) file_get_contents($this->capture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($captured);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('core-contract', $captured['mark']);
        $this->assertSame('body-via-contract', $captured['body']);
        $this->assertSame($this->baseUrl . 'from', $captured['referer']);
    }

    #[Test]
    public function fetchWaitsForReadyBeforeCapturing(): void
    {
        $response = $this->chrome()->fetch($this->baseUrl . 'late', 'GET', [], null, [
            WebFetcherInterface::READY => 'p#ready',
            WebFetcherInterface::TIMEOUT => 30.0,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['headers']);
        $this->assertStringContainsString('appeared', $response['body'], 'the capture happens after the selector matches');
    }

    #[Test]
    public function closeStopsTheDebuggingPort(): void
    {
        // Owns a browser: this test's subject is the process lifecycle, and closing the shared
        // one would pull the browser out from under every later test in the class.
        $port = self::pickFreePort();
        $dir = $this->userDataDir . '-close';
        $launcher = self::bootLauncher();

        try {
            $chrome = self::makeChrome($launcher, $dir, $port);
            $page = $chrome->open($this->baseUrl);
            $page->close();
            $this->assertTrue($launcher->portOpen($port));

            $chrome->close();
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                if (!$launcher->portOpen($port)) {
                    $this->assertFalse($launcher->portOpen($port));

                    return;
                }
                usleep(50_000);
            }

            $this->fail('Debugging port stayed open after close().');
        } finally {
            $launcher->stopOwnedProcess($port);
        }
    }

    protected function chrome(): HeadlessChrome
    {
        $launcher = $this->launcher;
        if (!$launcher instanceof Launcher) {
            $this->fail('Shared launcher is missing.');
        }

        return self::makeChrome($launcher, $this->userDataDir, $this->port);
    }

    protected static function makeChrome(Launcher $launcher, string $userDataDir, int $port): HeadlessChrome
    {
        return new class ($launcher, new Clock(), $userDataDir, $port) extends HeadlessChrome {
            public function __construct(Launcher $launcher, Clock $clock, string $userDataDir, int $port)
            {
                $this->launcher = $launcher;
                $this->clock = $clock;
                $this->userDataDir = $userDataDir;
                $this->port = $port;
                $this->autoLaunch = true;
                $this->timeout = 30.0;
                // CI and agent sandboxes block Chrome's own sandbox, which kills the GPU process at
                // startup. The package default stays sandboxed; only these tests opt out.
                $this->args = ['--no-sandbox', '--disable-dev-shm-usage'];
            }
        };
    }

    protected static function startSharedServer(): string
    {
        $dir = sys_get_temp_dir();
        self::$sharedCapture = $dir . '/switon-cdp-capture-' . getmypid() . '.json';
        $router = $dir . '/switon-cdp-router-' . getmypid() . '.php';
        file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/api') {
    file_put_contents(getenv('SWITON_CAPTURE'), json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'mark' => $_SERVER['HTTP_X_SWITON_MARK'] ?? '',
        'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        'body' => file_get_contents('php://input'),
    ]));
    header('Content-Type: application/json');
    echo '{"ok":true}';
    return;
}
if ($path === '/missing') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body>missing-page</body></html>';
    return;
}
if ($path === '/echo') {
    header('Content-Type: application/json');
    echo json_encode(['referer' => $_SERVER['HTTP_REFERER'] ?? null]);
    return;
}
if ($path === '/submit') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body>
<form id="f" onsubmit="event.preventDefault();document.getElementById('out').textContent='submitted:'+document.getElementById('name').value">
<input id="name" value="preset">
<button type="submit">Go</button>
</form>
<p id="out"></p>
</body></html>
HTML;
    return;
}
if ($path === '/covered') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body style="margin:0">
<button id="target" onclick="document.getElementById('r').textContent='TARGET'">Target</button>
<div id="r"></div>
<div id="veil" style="position:fixed;inset:0;z-index:10" onclick="document.getElementById('r').textContent='VEIL'"></div>
</body></html>
HTML;
    return;
}
if ($path === '/editable') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body><div id="note" contenteditable>hello world</div></body></html>';
    return;
}
if ($path === '/assert-conditions') {
    header('Content-Type: text/html; charset=utf-8');
    // A page that covers every condition kind, including the negative ones.
    echo <<<'HTML'
<html><body>
<button id="go">Go</button>
<button id="off" disabled>Off</button>
<div id="shown">visible text</div>
<div id="hidden" style="display:none">secret</div>
<input id="field" value="">
<input type="checkbox" id="cb">
<p id="status">idle</p>
<script>
document.getElementById('go').addEventListener('click', function () {
  // Updates 600ms later, the way a request round trip does.
  setTimeout(function () { document.getElementById('status').textContent = 'done'; }, 600);
});
</script>
</body></html>
HTML;
    return;
}
if ($path === '/deferred') {
    header('Content-Type: text/html; charset=utf-8');
    // Mounts on a timer with no network request, which `networkIdle` cannot observe. The element
    // exists only after the timer, so `wait()` is the way to reach it.
    echo <<<'HTML'
<html><body><div id="app"></div><script>
setTimeout(function () {
  var b = document.createElement('button');
  b.id = 'late';
  b.textContent = 'late button';
  document.getElementById('app').appendChild(b);
}, 1200);
</script></body></html>
HTML;
    return;
}
if ($path === '/frames-inner') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body><button id="fb">frame button</button><p>frame paragraph</p></body></html>';
    return;
}
if ($path === '/xo-child') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body style="margin:0">
<input id="card" value="" style="width:260px;height:32px;margin:8px">
<button id="pay" style="width:150px;height:40px" onclick="document.getElementById('st').textContent='PAID:'+document.getElementById('card').value">Pay</button>
<p id="st">waiting</p>
</body></html>
HTML;
    return;
}
if ($path === '/xo-parent') {
    header('Content-Type: text/html; charset=utf-8');
    // `localhost` and `127.0.0.1` are different sites, so Chrome isolates this frame in its own
    // process — which is the case a selector cannot reach.
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    echo '<html><body style="margin:0"><h1>Checkout</h1>'
        . '<iframe src="http://localhost:' . $port . '/xo-child" style="width:360px;height:240px;border:4px solid #ccc"></iframe>'
        . '</body></html>';
    return;
}
if ($path === '/frames') {
    header('Content-Type: text/html; charset=utf-8');
    // A relative src keeps the frame same-origin, which is what a frameId can read.
    echo '<html><body><h1>outer</h1><iframe id="fr" src="/frames-inner" style="width:300px;height:120px;border:0"></iframe></body></html>';
    return;
}
if ($path === '/broken') {    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body>
<div id="app">loading...</div>
<script>
  console.log("boot: starting");
  console.error("fatal: config missing");
  fetch('/missing').then(function (r) { console.log('api status', r.status); });
  setTimeout(function () { throw new Error("BOOM: undefined is not a function"); }, 60);
</script>
</body></html>
HTML;
    return;
}
if ($path === '/dialog') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body>
<div id="log">before</div>
<button id="go" onclick="alert('confirm');document.getElementById('log').textContent='AFTER-ALERT'">go</button>
</body></html>
HTML;
    return;
}
if ($path === '/shadow') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body>
<div id="log">none</div>
<div id="host"></div>
<script>
const root = document.getElementById('host').attachShadow({mode: 'open'});
root.innerHTML = '<button id="sb" style="width:200px;height:60px;display:block">shadow button</button>';
root.getElementById('sb').addEventListener('click', function () {
  document.getElementById('log').textContent = 'SHADOW-CLICKED';
});
</script>
</body></html>
HTML;
    return;
}
if ($path === '/styled') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body>
<style>.hid { display: none } .red { color: rgb(255, 0, 0) }</style>
<div id="ok" class="red">visible</div>
<div id="hid" class="hid">hidden</div>
</body></html>
HTML;
    return;
}
if ($path === '/form') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body>
<input id="name" value="old">
<button id="go" type="button">Go</button>
<p id="typed"></p>
<p id="clicked"></p>
<script>
document.getElementById("name").addEventListener("input", function () {
  document.getElementById("typed").textContent = this.value;
});
document.getElementById("go").addEventListener("click", function () {
  document.getElementById("clicked").textContent = "clicked";
});
</script>
</body></html>
HTML;
    return;
}
if ($path === '/late') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<html><body><script>
setTimeout(function () {
  var node = document.createElement("p");
  node.id = "ready";
  node.textContent = "appeared";
  document.body.appendChild(node);
}, 400);
</script></body></html>
HTML;
    return;
}
header('Content-Type: text/html; charset=utf-8');
echo '<html><head><title>Switon Headless</title></head><body><h1>headless-ok</h1></body></html>';
PHP);
        $port = self::pickFreePort();
        $log = $dir . '/switon-cdp-server-' . getmypid() . '.log';
        self::$sharedServer = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $log, 'w'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            null,
            ['SWITON_CAPTURE' => self::$sharedCapture],
        );
        if (!is_resource(self::$sharedServer)) {
            throw new RuntimeException('Cannot start the local page server.');
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                return 'http://127.0.0.1:' . $port . '/';
            }
            usleep(50_000);
        }

        throw new RuntimeException('Local page server did not accept connections.');
    }

    protected static function pickFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new RuntimeException("Cannot allocate free port: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name === false || !str_contains($name, ':')) {
            throw new RuntimeException('Cannot resolve ephemeral port.');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    protected static function bootLauncher(): Launcher
    {
        return new class (new Clock(), self::newFilesystem()) extends Launcher {
            public function __construct(Clock $clock, Filesystem $filesystem)
            {
                $this->clock = $clock;
                $this->filesystem = $filesystem;
            }
        };
    }

    protected static function newFilesystem(): Filesystem
    {
        return new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }
        };
    }
}
