<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Tests\Fixtures\StubSiteSearch;
use Switon\Search\Tool\WebSearchTool;
use Switon\Search\SiteSearchInterface;
use Switon\Search\Tool\SiteSearchTool;
use Switon\Tooling\Tool\BashTool;

/**
 * Live behavior checks for {@see SiteSearchTool} against a real LLM.
 *
 * Uses a stub vault backend so tool choice (not remote APIs) is under test.
 *
 *   bash tests/run.sh agency live -- --filter SiteSearchToolLiveTest
 */
#[CoversClass(SiteSearchTool::class)]
class SiteSearchToolLiveTest extends ToolsLiveTestCase
{
    public function testPrefersSiteSearchForPackagistStyleQuery(): void
    {
        $this->assertLiveLlmBound();

        $token = 'SWITON_SITE_' . strtoupper(bin2hex(random_bytes(3)));
        $stub = new StubSiteSearch();
        $stub->results = [
            [
                'title' => "vendor/{$token}",
                'url' => 'https://packagist.org/packages/vendor/' . strtolower($token),
                'snippet' => "Package mentioning {$token}.",
            ],
        ];
        $this->container->replace(SiteSearchInterface::class, $stub);

        $siteSearch = $this->recording('site_search', $this->make(SiteSearchTool::class));
        $webSearch = $this->recording('web_search', $this->make(WebSearchTool::class));
        $bash = $this->recording('bash', $this->container->make(BashTool::class));

        $data = $this->instructor()->ask(
            $this->toolProfile(['site_search', 'web_search', 'bash']),
            "Find the PHP package related to {$token} using site_search on the packagist site. "
            . 'Report the top result URL. Do not invent URLs.',
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $hits = $this->callsNamed($calls, 'site_search');
        $this->assertNotEmpty($hits, 'expected site_search; calls=' . json_encode($calls));
        $this->assertSame('packagist', (string) ($hits[0]['arguments']['site'] ?? ''));
        $query = (string) ($hits[0]['arguments']['query'] ?? '');
        $this->assertStringContainsString($token, $query);

        $answer = (string) ($data['answer'] ?? '');
        $this->assertStringContainsString(strtolower($token), strtolower($answer));
    }
}
