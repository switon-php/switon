<?php

declare(strict_types=1);

/**
 * Multi-turn prompt-cache probe. Works with any catalog model.
 *
 *   php tests/manual/live-cache-hit-probe.php [model]
 */

require __DIR__ . '/bootstrap.php';

use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;

$model = $argv[1] ?? 'deepseek/flash';
$container = liveLlmContainer();
$llm = liveLlmResolve($container, $model, timeout: 300);
liveLlmEchoProviderSummary($model, $llm, stream: false);
echo "\n";

$pad = str_repeat(
    "You are helping a PHP framework team (Switon) integrate MCP. "
    . "Prefer concrete protocol facts: JSON-RPC, tools/list, tools/call, resources, prompts, stdio vs SSE. ",
    40,
);
$charter = Charter::of("Background for this session:\n{$pad}");

$questions = [
    '请系统介绍一下 MCP（Model Context Protocol）是什么、要解决什么问题，控制在 400 字左右。',
    '接着讲传输层：stdio 和 SSE/HTTP 各自怎么工作，客户端和服务端角色分别是什么？尽量具体。',
    'tools/list 和 tools/call 的消息长什么样？参数 schema 怎么约定？举一个读文件工具的例子。',
    '和我们这种 agent 循环（多轮 tool call）结合时，哪些内容适合放进稳定前缀以便 prompt cache？哪些必须每轮变化？',
];

$thread = new Thread('cache-hit-mcp-probe');

foreach ($questions as $i => $question) {
    $n = $i + 1;
    $thread->turns[] = Turn::of(
        't',
        completions: [Completion::of(UserMessage::of($question))],
        status: TurnStatus::Running,
        model: $model,
    );

    echo "--- turn {$n} ---\n";
    echo '  user: ' . mb_strimwidth($question, 0, 80, '…') . "\n";
    $completion = $llm->complete($thread, $charter);
    dumpUsage($completion->usage);
    $text = trim($completion->output?->content ?? '');
    echo '  assistant: ' . mb_strimwidth(str_replace("\n", ' ', $text), 0, 120, '…') . "\n\n";
}

function dumpUsage(mixed $usage): void
{
    if ($usage === null) {
        echo "  usage: null\n";

        return;
    }

    $hit = $usage->hit === null ? 'null' : sprintf('%.1f%%', $usage->hit * 100);
    $ideal = $usage->ideal === null ? 'null' : sprintf('%.1f%%', $usage->ideal * 100);
    $gap = ($usage->hit !== null && $usage->ideal !== null)
        ? sprintf('%+.1fpp', ($usage->ideal - $usage->hit) * 100)
        : 'n/a';

    echo sprintf(
        "  usage: in=%d out=%d cacheRead=%d cacheWrite=%d uncached=%d | hit=%s ideal=%s gap=%s\n",
        $usage->input,
        $usage->output,
        $usage->cacheRead,
        $usage->cacheWrite,
        $usage->uncached(),
        $hit,
        $ideal,
        $gap,
    );
}
