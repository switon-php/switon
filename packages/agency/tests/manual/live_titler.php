<?php

declare(strict_types=1);

/**
 * Live: Titler sidebar titles — user messages must not mention titling (no meta / no task name).
 *
 *   php packages/agency/tests/manual/live_titler.php
 */

use Psr\Container\ContainerInterface;
use Switon\Cli\Runner;
use Switon\Agency\TitlerInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\Message\UserMessage;

require __DIR__ . '/live_bootstrap.php';

function liveTitlerThread(string $id, Completion ...$completions): Thread
{
    $turns = [];
    foreach ($completions as $i => $completion) {
        $turns[] = Turn::of('t' . ($i + 1), [$completion]);
    }

    return new Thread($id, $turns);
}

/** @var list<array{id: string, thread: Thread, note: string}> $scenarios */
$scenarios = [
    [
        'id' => 'long-zh-bug',
        'note' => '长中文排错',
        'thread' => liveTitlerThread('live-zh-bug', Completion::of(UserMessage::of(
                '生产环境登录接口偶发 404，本地复现不了。nginx 反代到 Swoole 9510，'
                . '只有带 cookie 的老会话会踩到，新隐身窗口正常。帮我从路由、session 粘滞、'
                . 'worker 热更新三条线给排查思路，先别改代码。',
            )),
        ),
    ],
    [
        'id' => 'long-en-refactor',
        'note' => '长英文重构',
        'thread' => liveTitlerThread('live-en-refactor', Completion::of(UserMessage::of(
                'We need to split SessionService out of the WebSocket controller: create thread, '
                . 'resolve host, and list sessions should stay in one service. Controllers only wire HTTP/WS. '
                . 'Point me at the smallest diff that keeps transcript paths unchanged.',
            )),
        ),
    ],
    [
        'id' => 'code-paste',
        'note' => '贴代码',
        'thread' => liveTitlerThread('live-code', Completion::of(UserMessage::of(
                "这个 goroutine 泄漏了吗？\n"
                . "```go\n"
                . "go func() {\n"
                . "  for msg := range ch {\n"
                . "    handle(msg)\n"
                . "  }\n"
                . "}()\n"
                . "```\n"
                . 'ch 没人 close，主函数 return 就退出了。',
            )),
        ),
    ],
    [
        'id' => 'multi-steer',
        'note' => '多轮 user',
        'thread' => liveTitlerThread('live-multi', Completion::of(UserMessage::of('读一下 frontend/src/api.ts 里 SessionSummary 结构')),
            Completion::of(UserMessage::of('侧边栏会话列表现在只有时间，想显示一句摘要')),
            Completion::of(UserMessage::of('超过 100 字节的首条消息再异步生成，短的先不管')),
        ),
    ],
    [
        'id' => 'with-assistant-noise',
        'note' => '含 assistant（wire 应忽略）',
        'thread' => liveTitlerThread('live-noise', Completion::of(UserMessage::of('给 Vue 3 聊天页加一个项目树：展开项目后列出最近会话，点击切换 threadId。'), new AssistantMessage('我先看 SwitonProjectSidebar 和 useChatSession。')),
            Completion::of(UserMessage::of('列表项别用 threadId 前 8 位，要能看出聊的是啥。')),
        ),
    ],
];

echo "=== live titler ===\n";
echo "scenarios: " . count($scenarios) . "\n\n";

$passed = 0;
$failed = 0;

$ok = Runner::run(static function (ContainerInterface $container) use ($scenarios, &$passed, &$failed): bool {
    $titler = $container->get(TitlerInterface::class);

    foreach ($scenarios as $scenario) {
        $id = $scenario['id'];
        $thread = $scenario['thread'];
        $userBytes = 0;
        foreach (liveWireMessages($thread->completions()) as $message) {
            if ($message instanceof \Switon\Ai\Message\UserMessage) {
                $userBytes += strlen(trim((string) $message->text()));
            }
        }

        echo "--- {$id} ({$scenario['note']}; user_bytes={$userBytes}) ---\n";
        $started = microtime(true);

        try {
            $title = trim($titler->title($thread));
            $ms = (int) round((microtime(true) - $started) * 1000);
        } catch (Throwable $e) {
            echo "[FAIL] {$e->getMessage()}\n\n";
            $failed++;

            continue;
        }

        if ($title === '') {
            echo "[FAIL] empty title ({$ms}ms)\n\n";
            $failed++;

            continue;
        }

        $len = strlen($title);
        $bad = str_contains($title, '```')
            || str_contains($title, "\n")
            || ($len > 80);

        echo "title ({$len} chars, {$ms}ms): {$title}\n";
        if ($bad) {
            echo "[WARN] noisy output\n";
        } else {
            echo "[OK]\n";
        }
        echo "\n";
        $passed++;
    }

    return $failed === 0;
});

echo "RESULT: " . ($ok ? 'PASS' : 'FAIL') . " ({$passed} ok, {$failed} failed)\n";
exit($ok ? 0 : 1);
