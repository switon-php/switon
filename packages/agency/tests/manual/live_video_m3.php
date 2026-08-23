<?php

declare(strict_types=1);

/**
 * MiniMax-M3 multimodal media entry paths (OpenAI wire) — each asks twice.
 *
 *   php packages/agency/tests/manual/live_video_m3.php [minimax/M3] [video-url] [image-url]
 *
 * Paths (natural prompts; each path asks 3× on the same media):
 * 1) User text with online URL → analyze (tool unload → re-attach each follow-up)
 * 2) Code-composed UserMessage MediaPart (user parts stay; re-attach optional)
 * 3) MCP-shaped tool result image (unload → re-attach each follow-up)
 *
 * Rebinds minimax → {@see \Switon\Llm\OpenAi} + `/v1` (Anthropic MiniMax rejects tool_result video).
 */

use Switon\Ai\AgentInterface;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Profile;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Ai\Message\UserMessage;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\OpenAi;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Tool\MediaTool;
use Switon\Ai\Envelope;

require __DIR__ . '/live_bootstrap.php';

$model = $argv[1] ?? 'minimax/M3';
$videoUrl = $argv[2] ?? 'https://www.w3schools.com/html/mov_bbb.mp4';
$imageUrl = $argv[3] ?? 'https://www.w3schools.com/html/pic_trulli.jpg';

/**
 * Live-only stub: same outcome shape as MCP image blocks → {@see MediaPart} under tool-asset/.
 */
class LiveMcpImageTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return new ToolSchema(
            $this,
            description: 'Load the Alberobello sample photo into context for viewing.',
            properties: [],
            required: [],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return true;
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): ToolResult
    {
        $url = $GLOBALS['__live_mcp_image_url'] ?? '';
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('live mcp image url unset');
        }
        $bytes = @file_get_contents($url);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("download {$url}");
        }

        $datadir = $this->spillTarget($agent)[0];
        $ref = 'tool-asset/mcp-sample.jpg';
        $abs = $datadir . '/' . $ref;
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("mkdir {$dir}");
        }
        if (file_put_contents($abs, $bytes) === false) {
            throw new RuntimeException("write {$abs}");
        }

        return new ToolResult('', parts: [
            MediaPart::of($ref, mime: 'image/jpeg'),
        ]);
    }
}

/**
 * Point catalog `minimax` at OpenAI chat completions (same apiKey; strip `/anthropic`).
 */
function liveRebindMinimaxOpenAi(LlmDiscoveryInterface $discovery): void
{
    if (!$discovery instanceof LlmDiscovery) {
        throw new RuntimeException('Expected LlmDiscovery instance.');
    }

    $prop = new ReflectionProperty(LlmDiscovery::class, 'providers');
    /** @var array<string, array<string, mixed>> $all */
    $all = $prop->getValue($discovery);
    if (!isset($all['minimax']) || !is_array($all['minimax'])) {
        throw new RuntimeException('minimax provider missing in ~/.switon/llm.yml');
    }

    $entry = $all['minimax'];
    $entry['class'] = OpenAi::class;
    $base = rtrim((string) ($entry['baseUrl'] ?? ''), '/');
    $base = (string) preg_replace('#/anthropic(?:/v1)?$#', '', $base);
    $base = rtrim($base, '/');
    if ($base === '') {
        $base = 'https://api.minimaxi.com';
    }
    if (!str_ends_with($base, '/v1')) {
        $base .= '/v1';
    }
    $entry['baseUrl'] = $base;
    $all['minimax'] = $entry;
    $prop->setValue($discovery, $all);
    $discovery->discover();

    echo "Wire:  OpenAi @ {$base}\n";
}

/**
 * @return list<ToolCall>
 */
function liveMediaCalls(AgentInterface $agent): array
{
    $calls = [];
    foreach (liveWireMessages($agent->thread()->completions()) as $message) {
        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $toolCall) {
                if ($toolCall->name === 'media') {
                    $calls[] = $toolCall;
                }
            }
        }
    }

    return $calls;
}

/**
 * @param list<string> $tools
 */
function liveCreateMediaHost(ContainerInterface $container, string $model, string $id, array $tools): AgentInterface
{
    $agent = $container->get(RecordingAgentManager::class)->mint([
        'profile' => Profile::decode([
            'id' => $id,
            'prompt' => 'You answer briefly.',
            'tools' => $tools,
            'skills' => [],
            'model' => $model,
            'max_steps' => 8,
        ]),
    ]);
    liveTrackOpenAgent($agent);

    return $agent;
}

function liveClearAssistantText(AgentInterface $agent): void
{
    foreach ($agent->thread()->completions() as $row) {
        $output = $row->output;
        if ($output !== null && trim((string) $output->content) !== '') {
            $output->content = '';
        }
    }
}

function liveLastAnswer(AgentInterface $agent): string
{
    return trim((string) ($agent->thread()->lastCompletion()?->output?->content ?? ''));
}

/** Clear prior answers, ask again about the same media, require a non-empty reply. */
function liveFollowUp(AgentInterface $agent, string $question, string $label): string
{
    liveClearAssistantText($agent);
    $agent->run(Envelope::of($question, liveModel($container, $agent->profile()->model)));
    $answer = liveLastAnswer($agent);
    echo "{$label}: {$answer}\n";
    if ($answer === '') {
        throw new RuntimeException("{$label} empty");
    }

    return $answer;
}

Runner::run(function (ContainerInterface $container) use ($model, $videoUrl, $imageUrl): void {
    $GLOBALS['__live_mcp_image_url'] = $imageUrl;

    liveBindTools($container, [
        'media' => MediaTool::class,
        'mcp_image' => LiveMcpImageTool::class,
    ]);
    liveDisableMcp($container);
    liveRegisterAgentPackage($container);
    liveStartConsole($container);
    liveRebindMinimaxOpenAi($container->get(LlmDiscoveryInterface::class));

    // ── 1) User text URL → compose media; more asks on same video (unload) ──
    $t = liveCreateMediaHost($container, $model, 'video-m3-text-url', ['media']);
    echo "=== 1 user-text URL (same video, more asks) ===\n";
    echo "url: {$videoUrl}\n";
    $t->run(Envelope::of("Analyze this video in one short sentence:\n{$videoUrl}", liveModel($container, $t->profile()->model)));
    $a1 = liveLastAnswer($t);
    echo "ask1: {$a1}\n";
    if ($a1 === '') {
        throw new RuntimeException('1.ask1 empty');
    }
    $hit = false;
    foreach (liveMediaCalls($t) as $toolCall) {
        echo "tool: " . json_encode($toolCall->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (($toolCall->arguments['ref'] ?? '') === $videoUrl) {
            $hit = true;
        }
    }
    if (!$hit) {
        throw new RuntimeException('1.ask1 did not attach the url');
    }

    liveFollowUp($t, 'What color is the butterfly? One short sentence.', 'ask2');
    if (count(liveMediaCalls($t)) < 2) {
        throw new RuntimeException('1.ask2 expected re-attach after unload, got ' . count(liveMediaCalls($t)));
    }
    liveFollowUp($t, 'What falls into the grass near the rabbit? One short sentence.', 'ask3');
    echo "media calls total=" . count(liveMediaCalls($t)) . "\n";
    echo "ok 1\n\n";

    // ── 2) Code-composed UserMessage MediaPart; more asks (no unload) ───────
    $u = liveCreateMediaHost($container, $model, 'video-m3-user-blocks', ['media']);
    echo "=== 2 user MediaPart blocks (same video, more asks) ===\n";
    $u->run(Envelope::of(UserMessage::of(
        'Analyze this in one short sentence.',
        [MediaPart::of($videoUrl, mime: 'video/mp4')],
    ), liveModel($container, $u->profile()->model)));
    $b1 = liveLastAnswer($u);
    echo "ask1: {$b1}\n";
    if ($b1 === '') {
        throw new RuntimeException('2.ask1 empty');
    }

    liveFollowUp($u, 'What color is the butterfly? One short sentence.', 'ask2');
    liveFollowUp($u, 'What falls into the grass near the rabbit? One short sentence.', 'ask3');
    echo "media calls total=" . count(liveMediaCalls($u)) . " (user parts stay)\n";
    echo "ok 2\n\n";

    // ── 3) MCP-shaped ToolResult; more asks on same image (unload) ──────────
    $m = liveCreateMediaHost($container, $model, 'video-m3-mcp', ['mcp_image', 'media']);
    echo "=== 3 MCP-shaped image (same photo, more asks) ===\n";
    echo "image: {$imageUrl}\n";
    $m->run(Envelope::of('What does the Alberobello sample photo show? One short sentence.', liveModel($container, $m->profile()->model)));
    $c1 = liveLastAnswer($m);
    echo "ask1: {$c1}\n";
    if ($c1 === '') {
        throw new RuntimeException('3.ask1 empty');
    }
    $mcpCalled = false;
    foreach (liveWireMessages($m->thread()->completions()) as $message) {
        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $toolCall) {
                if ($toolCall->name === 'mcp_image') {
                    $mcpCalled = true;
                }
            }
        }
    }
    if (!$mcpCalled) {
        throw new RuntimeException('3.ask1 did not load sample via mcp_image');
    }

    $n1 = count(liveMediaCalls($m));
    liveFollowUp($m, 'What is the main subject? One short sentence.', 'ask2');
    if (count(liveMediaCalls($m)) <= $n1) {
        throw new RuntimeException('3.ask2 expected re-attach after MCP unload');
    }
    // Further asks: answer may reuse prior sight; unload already proven on ask2.
    liveFollowUp($m, 'What color are the house walls? One short sentence.', 'ask3');
    echo "media calls total=" . count(liveMediaCalls($m)) . "\n";
    echo "ok 3\n";
    echo "ok\n";
});
