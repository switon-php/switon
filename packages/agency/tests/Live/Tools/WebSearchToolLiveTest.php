<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Tests\Fixtures\StubWebSearch;
use Switon\Search\Tool\WebSearchTool;
use Switon\Search\WebSearchInterface;
use Switon\Tooling\Tool\BashTool;

/**
 * Live behavior checks for {@see WebSearchTool} against a real LLM.
 *
 * Uses a stub search backend so choice of tool (not live HTML scrape) is under test.
 *
 *   bash tests/run.sh agency live -- --filter WebSearchToolLiveTest
 */
#[CoversClass(WebSearchTool::class)]
class WebSearchToolLiveTest extends ToolsLiveTestCase
{
    public function testSearchesPreferringWebSearch(): void
    {
        $this->assertLiveLlmBound();

        $token = 'SWITON_SEARCH_' . strtoupper(bin2hex(random_bytes(3)));
        $stub = new StubWebSearch();
        $stub->results = [
            [
                'title' => "Doc about {$token}",
                'url' => 'https://example.com/' . strtolower($token),
                'snippet' => "Snippet mentioning {$token}.",
                'engine' => 'stub',
            ],
        ];
        $this->container->replace(WebSearchInterface::class, $stub);

        $webSearch = $this->recording('web_search', $this->make(WebSearchTool::class));
        $bash = $this->recording('bash', $this->container->make(BashTool::class));

        $data = $this->instructor()->ask(
            $this->toolProfile(['web_search', 'bash']),
            "Search the web for {$token} and report the top result URL. Do not invent URLs.",
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $searches = $this->callsNamed($calls, 'web_search');
        $this->assertNotEmpty($searches, 'expected web_search; calls=' . json_encode($calls));
        $query = (string) ($searches[0]['arguments']['query'] ?? '');
        $this->assertStringContainsString($token, $query);

        $bashes = $this->callsNamed($calls, 'bash');
        foreach ($bashes as $bashCall) {
            $command = (string) ($bashCall['arguments']['command'] ?? '');
            $this->assertDoesNotMatchRegularExpression(
                '/\b(curl|wget|lynx)\b/',
                $command,
                'bash should not curl search; command=' . $command,
            );
        }

        $answer = (string) ($data['answer'] ?? '');
        $this->assertStringContainsString(strtolower($token), strtolower($answer));
    }
}
