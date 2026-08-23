<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Search\Tool\WebFetchTool;
use Switon\Tooling\Tool\BashTool;

/**
 * Live behavior checks for {@see WebFetchTool} against a real LLM.
 *
 *   bash tests/run.sh agency live -- --filter WebFetchToolLiveTest
 */
#[CoversClass(WebFetchTool::class)]
class WebFetchToolLiveTest extends ToolsLiveTestCase
{
    #[Autowired] protected WebFetchTool $webFetch;

    #[Autowired] protected BashTool $bash;

    public function testFetchesUrlPreferringWebFetch(): void
    {
        $this->assertLiveLlmBound();

        $url = 'https://example.com/';
        $webFetch = $this->recording('web_fetch', $this->webFetch);
        $bash = $this->recording('bash', $this->bash);

        $data = $this->instructor()->ask(
            $this->toolProfile(['web_fetch', 'bash']),
            "Fetch {$url} and report the page title. Do not invent content.",
            $this->responseProperties(),
        );

        $calls = $this->recordedCalls();
        $fetches = $this->callsNamed($calls, 'web_fetch');
        $this->assertNotEmpty($fetches, 'expected web_fetch; calls=' . json_encode($calls));
        $this->assertSame($url, $fetches[0]['arguments']['url'] ?? null);

        $bashes = $this->callsNamed($calls, 'bash');
        foreach ($bashes as $bashCall) {
            $command = (string) ($bashCall['arguments']['command'] ?? '');
            $this->assertDoesNotMatchRegularExpression(
                '/\b(curl|wget|fetch)\b/',
                $command,
                'bash should not curl/wget the URL; command=' . $command,
            );
        }

        $answer = (string) ($data['answer'] ?? '');
        $this->assertNotSame('', $answer);
        $this->assertMatchesRegularExpression('/example/i', $answer);
    }
}
