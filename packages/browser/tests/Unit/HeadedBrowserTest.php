<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Switon\Browser\Exception;
use Switon\Browser\HeadedBrowser;
use Switon\Cdp\Exception as CdpException;
use Switon\Cdp\HeadedChromeInterface;
use Switon\Cdp\Paths;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;

use function bin2hex;
use function file_put_contents;
use function glob;
use function mkdir;
use function random_bytes;
use function range;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(HeadedBrowser::class)]
class HeadedBrowserTest extends TestCase
{
    #[Test]
    public function snapshotMapsRefsThenClickAndTypeUseThem(): void
    {
        $page = $this->page();
        $browser = $this->browser($page);

        $browser->navigate('https://example.com/', null, 'main');
        $text = $browser->snapshot();

        $this->assertSame('main', $page->waited);
        $this->assertStringContainsString('https://example.com/', $text);
        $this->assertStringContainsString('[e1] button "Go"', $text);
        $this->assertStringNotContainsString('below the fold', $text);

        $browser->click('e1');
        $browser->navigate('https://example.com/again');
        $browser->snapshot();
        $browser->type('e1', 'Ada', true);

        $this->assertSame(['#11'], $page->clicked);
        $this->assertSame([['#11', 'Ada', true]], $page->filled);
        $this->assertSame('https://example.com/again', $page->navigated);
    }

    #[Test]
    public function scrollMovesByPixelsOrByRef(): void
    {
        $page = $this->page();
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');
        $browser->snapshot();

        $browser->scroll('', 400);
        $browser->scroll('e1');

        $this->assertSame([['', 400], ['#11', 0]], $page->scrolled);
    }

    #[Test]
    public function anUnknownRefAndAnEmptyUrlAreRejected(): void
    {
        $browser = $this->browser($this->page());

        try {
            $browser->navigate('  ');
            $this->fail('An empty URL must be rejected.');
        } catch (Exception $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $browser->navigate('https://example.com/');
        $this->expectException(Exception::class);
        $browser->click('e1');
    }

    #[Test]
    public function abortStopsTheCall(): void
    {
        $browser = $this->browser($this->page());

        $this->expectException(Exception::class);
        $browser->navigate('https://example.com/', static fn (): bool => true);
    }

    #[Test]
    public function diagnosticsInspectAssertAndEvaluatePassThrough(): void
    {
        $page = $this->page();
        $page->console = [['type' => 'console', 'level' => 'error', 'text' => 'boom', 'url' => '', 'line' => 1]];
        $page->inspected = ['tag' => 'button', 'visible' => true, 'box' => [], 'styles' => []];
        $page->scriptResult = 7;
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');
        $browser->snapshot();

        $this->assertSame('boom', $browser->diagnostics()[0]['text']);
        $this->assertSame('button', $browser->inspect('e1')['tag']);
        $this->assertTrue($browser->assert('e1')['ok']);
        $this->assertSame(7, $browser->evaluate('1+6'));
        $this->assertSame([], $browser->frames());
    }

    #[Test]
    public function assertAcceptsASelectorForTextWithoutARef(): void
    {
        $page = $this->page();
        $page->assertion = [
            'ok' => true,
            'actual' => 'value=1',
            'target' => '',
            'kind' => 'text',
            'expected' => 'value=1',
            'waitedMs' => 3,
        ];
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');
        $browser->snapshot();

        // A paragraph is readable text, so the snapshot lists it without an eN ref.
        $result = $browser->assert('#out', 'text', 'value=1');

        $this->assertTrue($result['ok']);
        $this->assertSame('value=1', $result['actual']);
    }

    #[Test]
    public function assertStillRejectsARefThatWasNeverListed(): void
    {
        $browser = $this->browser($this->page());
        $browser->navigate('https://example.com/');
        $browser->snapshot();

        $this->expectException(Exception::class);
        $browser->assert('e99');
    }

    #[Test]
    public function assertRejectsAnEmptyTarget(): void
    {
        $browser = $this->browser($this->page());
        $browser->navigate('https://example.com/');
        $browser->snapshot();

        $this->expectException(Exception::class);
        $browser->assert('   ');
    }

    #[Test]
    public function screenshotWritesThePngAndCloseDropsTheTab(): void
    {
        $page = $this->page();
        $dir = sys_get_temp_dir() . '/switon-browser-shot-' . bin2hex(random_bytes(4));
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('isDir')->willReturn(true);
        $written = '';
        $filesystem->method('write')->willReturnCallback(static function (string $path, string $body) use (&$written): void {
            $written = $body;
            file_put_contents($path, $body);
        });
        $chrome = $this->createStub(HeadedChromeInterface::class);
        $chrome->method('open')->willReturn($page);
        $browser = $this->make(HeadedBrowser::class, [
            'chrome' => $chrome,
            'filesystem' => $filesystem,
            'pathAlias' => new PathAlias(),
            'screenshotDir' => $dir,
        ]);
        mkdir($dir, 0700, true);
        try {
            $browser->navigate('https://example.com/');
            $shot = $browser->takeScreenshot();
            $this->assertSame("\x89PNG\r\n\x1a\n", $written);
            $this->assertSame(8, $shot['bytes']);
            $this->assertSame('https://example.com/', $shot['url']);

            $browser->closePage();
            $this->assertTrue($page->closed);
            $browser->closePage();
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    #[Test]
    public function snapshotIncludesAChildFrame(): void
    {
        $page = $this->page();
        $page->childFrames = [[
            'frameId' => 'f1',
            'url' => 'https://example.com/embed',
            'sessionId' => '',
            'sameOrigin' => true,
            'ownerBackendNodeId' => 0,
            'offset' => ['x' => 0.0, 'y' => 0.0],
        ]];
        $page->frameTrees['f1'] = [
            'nodes' => [[
                'nodeId' => '1',
                'role' => ['value' => 'link'],
                'name' => ['value' => 'Inside'],
                'backendDOMNodeId' => 22,
            ]],
        ];
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');

        $text = $browser->snapshot();

        $this->assertStringContainsString('[iframe /embed]', $text);
        $this->assertStringContainsString('link "Inside"', $text);

        // The ref must be an address `click()` can resolve, not a bare node id.
        $browser->click('e2');
        $this->assertSame(['#22'], $page->clicked);
    }

    #[Test]
    public function snapshotStillWorksWhenLayoutFails(): void
    {
        $page = $this->page();
        $page->cdpFail = 'layout';
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');

        $this->assertStringContainsString('[e1] button "Go"', $browser->snapshot());
    }

    #[Test]
    public function snapshotOmitsFramesWhenTheFrameListFails(): void
    {
        $page = $this->page();
        $page->cdpFail = 'frames';
        $page->childFrames = [[
            'frameId' => 'f1',
            'url' => 'https://example.com/embed',
            'sessionId' => '',
            'sameOrigin' => true,
            'ownerBackendNodeId' => 0,
            'offset' => ['x' => 0.0, 'y' => 0.0],
        ]];
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');

        $this->assertStringNotContainsString('[iframe', $browser->snapshot());
    }

    #[Test]
    public function snapshotMarksAnUnreadableFrame(): void
    {
        $page = $this->page();
        $page->childFrames = [[
            'frameId' => 'f1',
            'url' => 'https://other.test/',
            'sessionId' => 's1',
            'sameOrigin' => false,
            'ownerBackendNodeId' => 9,
            'offset' => ['x' => 0.0, 'y' => 0.0],
        ]];
        $page->cdpFail = 'frameSnapshot';
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');

        $this->assertStringContainsString('[iframe (cross-origin) other.test] content not readable', $browser->snapshot());
    }

    #[Test]
    public function snapshotMarksAnEmptyFrame(): void
    {
        $page = $this->page();
        $page->childFrames = [[
            'frameId' => 'f1',
            'url' => 'https://example.com/blank',
            'sessionId' => '',
            'sameOrigin' => true,
            'ownerBackendNodeId' => 0,
            'offset' => ['x' => 0.0, 'y' => 0.0],
        ]];
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');

        $this->assertStringContainsString('[iframe /blank] content not readable', $browser->snapshot());
    }

    #[Test]
    public function snapshotAddressesACrossOriginControl(): void
    {
        $page = $this->page();
        $page->childFrames = [[
            'frameId' => 'f1',
            'url' => 'https://other.test/widget',
            'sessionId' => 's1',
            'sameOrigin' => false,
            'ownerBackendNodeId' => 9,
            'offset' => ['x' => 0.0, 'y' => 0.0],
        ]];
        $page->frameTrees['f1'] = [
            'nodes' => [[
                'nodeId' => '1',
                'role' => ['value' => 'button'],
                'name' => ['value' => 'Pay'],
                'backendDOMNodeId' => 22,
            ]],
        ];
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');
        $browser->snapshot();
        $browser->click('e2');

        $this->assertSame(['#9:s1/22'], $page->clicked);
    }

    #[Test]
    public function evaluateAndSnapshotNeedAPageAndANonEmptyExpression(): void
    {
        $browser = $this->browser($this->page());

        try {
            $browser->snapshot();
            $this->fail('snapshot before navigate must fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('navigate()', $e->getMessage());
        }

        $browser->navigate('https://example.com/');
        $this->expectException(Exception::class);
        $browser->evaluate('  ');
    }

    #[Test]
    public function aCdpFailureBecomesABrowserException(): void
    {
        $page = $this->page();
        $page->cdpFail = 'click';
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');
        $browser->snapshot();

        $this->expectException(Exception::class);
        $browser->click('e1');
    }

    #[Test]
    public function navigateMapsAChromeFailure(): void
    {
        $chrome = $this->createStub(HeadedChromeInterface::class);
        $chrome->method('open')->willReturnCallback(static function (): never {
            CdpException::raise('chrome refused');
        });
        $browser = $this->make(HeadedBrowser::class, [
            'chrome' => $chrome,
            'filesystem' => $this->createStub(FilesystemInterface::class),
            'pathAlias' => new PathAlias(),
        ]);

        $this->expectException(Exception::class);
        $browser->navigate('https://example.com/');
    }

    #[Test]
    public function scrollRejectsAnUnknownRef(): void
    {
        $browser = $this->browser($this->page());
        $browser->navigate('https://example.com/');
        $browser->snapshot();

        $this->expectException(Exception::class);
        $browser->scroll('e99');
    }

    #[Test]
    public function inspectRejectsAnEmptyRef(): void
    {
        $browser = $this->browser($this->page());
        $browser->navigate('https://example.com/');

        $this->expectException(Exception::class);
        $browser->inspect('  ');
    }

    #[Test]
    public function closePageSwallowsACloseFailure(): void
    {
        $page = $this->page();
        $page->closeThrows = true;
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');
        $browser->closePage();

        $this->assertFalse($page->closed);
    }

    #[Test]
    public function screenshotCreatesTheDirectoryAndMapsAWriteFailure(): void
    {
        $page = $this->page();
        $dir = sys_get_temp_dir() . '/switon-browser-shot-' . bin2hex(random_bytes(4));
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('isDir')->willReturn(false);
        $filesystem->method('mkdir');
        $filesystem->method('write')->willReturnCallback(static function (): never {
            throw new RuntimeException('disk full');
        });
        $alias = $this->createStub(PathAliasInterface::class);
        $alias->method('resolve')->willReturn($dir);
        $chrome = $this->createStub(HeadedChromeInterface::class);
        $chrome->method('open')->willReturn($page);
        $browser = $this->make(HeadedBrowser::class, [
            'chrome' => $chrome,
            'filesystem' => $filesystem,
            'pathAlias' => $alias,
            'screenshotDir' => '',
        ]);
        $browser->navigate('https://example.com/');

        try {
            $browser->takeScreenshot();
            $this->fail('A screenshot that cannot be written must be reported.');
        } catch (Exception $e) {
            $this->assertStringContainsString(Paths::displayPath($dir), $e->getMessage());
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
            if ($home !== '') {
                $this->assertStringNotContainsString($home, $e->getMessage());
            }
        }
    }

    #[Test]
    public function snapshotCountsWhatIsBelowTheFold(): void
    {
        $page = $this->page();
        $page->view = ['width' => 800, 'height' => 600, 'scrollY' => 0, 'scrollHeight' => 4000];
        $page->boxes = [11 => ['x' => 0.0, 'y' => 2400.0, 'w' => 80.0, 'h' => 24.0]];
        $browser = $this->browser($page);
        $browser->navigate('https://example.com/');

        $text = $browser->snapshot();

        $this->assertStringContainsString('(1 more below the fold; scroll to bring them into view)', $text);
        $this->assertStringContainsString('(offscreen) button "Go"', $text);
    }

    #[Test]
    public function snapshotFoldsProsePastTheInjectedBudget(): void
    {
        $page = $this->page();
        $nodes = $page->ax['nodes'];
        foreach (range(1, 20) as $i) {
            $nodes[] = [
                'nodeId' => 'p' . $i,
                'role' => ['value' => 'paragraph'],
                'name' => ['value' => str_repeat('Reference sentence. ', 8)],
            ];
        }
        $page->ax = ['nodes' => $nodes];
        $chrome = $this->createStub(HeadedChromeInterface::class);
        $chrome->method('open')->willReturn($page);

        // A tight budget proves the injected value reaches the builder.
        $browser = $this->make(HeadedBrowser::class, [
            'chrome' => $chrome,
            'filesystem' => $this->createStub(FilesystemInterface::class),
            'pathAlias' => new PathAlias(),
            'maxTextChars' => 200,
        ]);
        $browser->navigate('https://example.com/');

        $text = $browser->snapshot();

        $this->assertStringContainsString('more text lines', $text);
        $this->assertStringContainsString('[e1] button "Go"', $text, 'a control is never folded');
    }

    private function page(): FakePage
    {
        $page = new FakePage();
        $page->ax = [
            'nodes' => [
                [
                    'nodeId' => '1',
                    'role' => ['value' => 'RootWebArea'],
                    'name' => ['value' => 'Example'],
                ],
                [
                    'nodeId' => '2',
                    'role' => ['value' => 'button'],
                    'name' => ['value' => 'Go'],
                    'backendDOMNodeId' => 11,
                ],
            ],
        ];

        return $page;
    }

    private function browser(FakePage $page): HeadedBrowser
    {
        $chrome = $this->createStub(HeadedChromeInterface::class);
        $chrome->method('open')->willReturn($page);

        return $this->make(HeadedBrowser::class, [
            'chrome' => $chrome,
            'filesystem' => $this->createStub(FilesystemInterface::class),
            'pathAlias' => new PathAlias(),
        ]);
    }
}
