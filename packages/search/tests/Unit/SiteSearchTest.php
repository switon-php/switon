<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Search\Exception;
use Switon\Search\Sites;
use Switon\Search\SiteSearchInterface;
use Switon\Search\SitesInterface;
use Switon\Search\Tests\Fixtures\StubSiteSearch;
use Switon\Search\Tests\ToolTestCase;
use Switon\Search\Tool\SiteListTool;
use Switon\Search\Tool\SiteSearchTool;

use function bin2hex;
use function copy;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(Sites::class)]
#[CoversClass(SiteSearchTool::class)]
#[CoversClass(SiteListTool::class)]
class SiteSearchTest extends ToolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // `boot()` fills the registry once; mirror that here, since a tool resolves it from the container.
        $this->container->get(SitesInterface::class)->reload();
    }

    public function testSitesListsGeneralIdsOnly(): void
    {
        $sites = $this->sites();
        $this->assertContains('zhihu', array_keys($sites->all()));
        $this->assertContains('github', array_keys($sites->all()));
        $this->assertContains('github-code', array_keys($sites->all()));
        $this->assertContains('github-issues', array_keys($sites->all()));
        $this->assertSame('github', $sites->get('github')->id);
    }

    public function testADeskMountAddsItsSitesBesideTheBuiltIns(): void
    {
        $sites = $this->sites();
        $deskSite = $sites->load(dirname(__DIR__) . '/Fixtures/sites/extra-stub.yml');
        $deskSite->id = 'demo.' . $deskSite->id;
        $sites->set($deskSite);

        $this->assertTrue($sites->has('demo.extra-stub'));
        $this->assertContains('zhihu', array_keys($sites->all()));
        $this->assertSame('demo.extra-stub', $sites->get('demo.extra-stub')->id);
    }

    public function testAMissingDirectoryIsSkipped(): void
    {
        $sites = $this->make(Sites::class, [
            'paths' => new ArrayIterator(['/no/such/site-dir']),
        ]);
        $sites->reload();

        $this->assertSame([], $sites->all());
    }

    public function testInjectedPathsLoadAndOverrideBuiltIns(): void
    {
        // A Traversable replaces the default list; a plain array would merge with it.
        $sites = $this->make(Sites::class, [
            'paths' => new ArrayIterator([dirname(__DIR__) . '/Fixtures/sites']),
        ]);
        $sites->reload();

        $this->assertTrue($sites->has('extra-stub'), 'a configured directory is scanned');
        $this->assertFalse($sites->has('zhihu'), 'a path list replaces the built-in one');
    }

    public function testReloadRescansAfterTheFilesChange(): void
    {
        $dir = sys_get_temp_dir() . '/switon-sites-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $sites = $this->make(Sites::class, ['paths' => new ArrayIterator([$dir])]);
        $sites->reload();

        $this->assertSame([], $sites->all(), 'an empty directory registers nothing');

        copy(dirname(__DIR__) . '/Fixtures/sites/extra-stub.yml', $dir . '/extra-stub.yml');
        try {
            $this->assertFalse($sites->has('extra-stub'), 'a registry filled before the file existed does not see it');

            $sites->reload();

            $this->assertTrue($sites->has('extra-stub'), 'reload picks up a new file');
        } finally {
            unlink($dir . '/extra-stub.yml');
            rmdir($dir);
        }
    }

    public function testReloadKeepsSitesAlreadyRegistered(): void
    {
        $dir = sys_get_temp_dir() . '/switon-sites-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        copy(dirname(__DIR__) . '/Fixtures/sites/extra-stub.yml', $dir . '/extra-stub.yml');
        $sites = $this->make(Sites::class, ['paths' => new ArrayIterator([$dir])]);
        $sites->reload();
        $this->assertTrue($sites->has('extra-stub'));

        // A desk installed after boot has no site files yet; loading again must not drop the ones above.
        unlink($dir . '/extra-stub.yml');
        try {
            $sites->reload();

            $this->assertTrue($sites->has('extra-stub'), 'reload merges instead of rebuilding');
        } finally {
            rmdir($dir);
        }
    }

    public function testZhihuParseMapsArticleAndAnswer(): void
    {
        $json = json_encode([
            'data' => [
                [
                    'object' => [
                        'type' => 'article',
                        'id' => '123',
                        'title' => '节后行情',
                        'excerpt' => '修复',
                    ],
                ],
                [
                    'object' => [
                        'type' => 'answer',
                        'id' => '99',
                        'question' => ['id' => '88', 'title' => '如何看待？'],
                        'excerpt' => '看好',
                    ],
                ],
                [
                    'object' => [
                        'type' => 'people',
                        'name' => 'PHP架构师圈子',
                        'url_token' => 'phpjia-gou-shi-quan-zi',
                        'url' => 'https://api.zhihu.com/people/add295dbe9453cad17c57fa6df7f0551',
                        'headline' => '架构',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $site = $this->sites()->get('zhihu');
        $this->assertSame('browser', $site->fetch);
        $this->assertSame(20, $site->size);

        $results = $this->extractor()->extract($site, $json, 'https://www.zhihu.com/api/v4/search_v3?q=x');
        $this->assertCount(3, $results);
        $this->assertSame('https://zhuanlan.zhihu.com/p/123', $results[0]['url']);
        $this->assertSame('https://www.zhihu.com/question/88/answer/99', $results[1]['url']);
        $this->assertSame('https://www.zhihu.com/people/phpjia-gou-shi-quan-zi', $results[2]['url']);
        $this->assertSame('PHP架构师圈子', $results[2]['title']);
        $this->assertSame('架构', $results[2]['snippet']);
    }

    public function testCapabilitiesDeclarePagination(): void
    {
        $sites = $this->sites();
        $this->assertGreaterThan(0, $sites->get('zhihu')->size, 'a page size enables `page`');
        $this->assertSame(0, $sites->get('github')->size, 'no page size disables `page`');
    }

    public function testSiteSearchSchemaExposesRegistryEnum(): void
    {
        $tool = $this->make(SiteSearchTool::class);

        $schema = $tool->schema('site_search');
        $this->assertNotNull($schema);
        $properties = $schema->definition['inputSchema']['properties'];
        $this->assertContains('zhihu', $properties['site']['enum']);
        $this->assertContains('github-code', $properties['site']['enum']);
        $this->assertSame(['site', 'query'], $schema->definition['inputSchema']['required']);
        $this->assertArrayHasKey('page', $properties);
        $this->assertArrayNotHasKey('type', $properties, 'a site file declares no facets');
        $this->assertTrue($tool->isReadonly('site_search', []));

        $sites = $this->container->get(SitesInterface::class);
        $sites->set(\Switon\Search\Site::of([
            'id' => 'desk.extra',
            'description' => 'Extra',
            'request' => 'https://example.com/search?q={keyword}',
        ], 'desk.extra'));
        $again = $tool->schema('site_search');
        $this->assertContains('desk.extra', $again->definition['inputSchema']['properties']['site']['enum']);
    }

    public function testSiteSearchDispatchesBySiteId(): void
    {
        $stub = new StubSiteSearch();
        $this->container->set(SiteSearchInterface::class, $stub);

        $payload = $this->callTool(
            $this->make(SiteSearchTool::class),
            'site_search',
            ['site' => 'zhihu', 'query' => '节后', 'page' => 2],
        );

        $this->assertSame('zhihu', $payload['site']);
        $this->assertSame('节后', $payload['query']);
        $this->assertSame(2, $payload['page']);
        $this->assertSame('zhihu', $stub->lastSite);
    }

    public function testSiteSearchRejectsUnknownSite(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown site');

        $this->callTool($this->make(SiteSearchTool::class), 'site_search', ['site' => 'nope', 'query' => 'x']);
    }

    public function testSiteSearchRejectsPaginationOnNonPaginatedSite(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('does not support pagination');

        $this->callTool(
            $this->make(SiteSearchTool::class),
            'site_search',
            ['site' => 'github', 'query' => 'x', 'page' => 2],
        );
    }

    public function testSiteSearchEmptyQueryRaises(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Search query must not be empty.');

        $this->callTool($this->make(SiteSearchTool::class), 'site_search', ['site' => 'zhihu', 'query' => '  ']);
    }

    public function testSiteSearchPassesHttpStatus(): void
    {
        $stub = new StubSiteSearch();
        $stub->status = 403;
        $this->container->set(SiteSearchInterface::class, $stub);

        $payload = $this->callTool(
            $this->make(SiteSearchTool::class),
            'site_search',
            ['site' => 'github-code', 'query' => 'php'],
        );

        $this->assertSame(403, $payload['status']);
        $this->assertSame([], $payload['results']);
        $this->assertArrayNotHasKey('warning', $payload);
    }

    public function testVariablesRowExposeIdAndLabelSorted(): void
    {
        $rows = $this->sites()->variables($this->createStub(AgentInterface::class))['sites'];

        $ids = array_column($rows, 'id');
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $ids, 'catalog rows are sorted by id');
        $this->assertContains('zhihu', $ids);
        $byId = array_column($rows, null, 'id');
        $this->assertNotSame('', $byId['zhihu']['label']);
    }

    public function testSiteSearchDisabledHidesSchema(): void
    {
        $tool = $this->make(SiteSearchTool::class, ['enabled' => false]);
        $this->assertNull($tool->schema('site_search'));
    }

    public function testSiteListSchemaFollowsTheEnabledFlag(): void
    {
        $tool = $this->make(SiteListTool::class);
        $schema = $tool->schema('site_list');

        $this->assertNotNull($schema);
        $this->assertSame($schema, $tool->schema('site_list'));
        $this->assertNull($this->make(SiteListTool::class, ['enabled' => false])->schema('site_list'));
    }

    public function testSiteSearchEmptyResultsWarn(): void
    {
        $stub = new StubSiteSearch();
        $stub->results = [];
        $this->container->set(SiteSearchInterface::class, $stub);

        $payload = $this->callTool($this->make(SiteSearchTool::class), 'site_search', [
            'site' => 'github',
            'query' => 'php',
        ]);

        $this->assertSame([], $payload['results']);
        $this->assertArrayHasKey('warning', $payload);
    }

    public function testSiteListRowsExposeLabelAndFacets(): void
    {
        $tool = $this->make(SiteListTool::class);
        $this->assertTrue($tool->isReadonly('site_list', []));

        $rows = $this->callTool($tool, 'site_list');
        $byId = array_column($rows, null, 'id');
        $this->assertArrayHasKey('zhihu', $byId);
        $this->assertArrayHasKey('github', $byId);
        $this->assertTrue($byId['zhihu']['pagination']);
        $this->assertFalse($byId['github']['pagination']);
        $this->assertArrayNotHasKey('types', $byId['github'], 'a site file declares no facets');
    }
}
