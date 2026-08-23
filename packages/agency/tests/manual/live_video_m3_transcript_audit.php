<?php

declare(strict_types=1);

/**
 * Dump stored transcript vs OpenAI wire after one successful media attach.
 *
 *   php packages/agency/tests/manual/live_video_m3_transcript_audit.php
 */

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Profile;
use Switon\Ai\Completion;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Message\UserMessage;
use Switon\Llm\AbstractLlm;
use Switon\Llm\LlmProviders;
use Switon\Llm\LlmProvidersInterface;
use Switon\Llm\OpenAi;
use Switon\Core\FilesystemInterface;
use Switon\Thread\ThreadsInterface;
use Switon\Tooling\Tool\MediaTool;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$videoUrl = 'https://www.w3schools.com/html/mov_bbb.mp4';

Runner::run(function (ContainerInterface $container) use ($videoUrl): void {
    liveBindTools($container, [
        'media' => MediaTool::class,
    ]);
    liveDisableMcp($container);
    liveRegisterAgentPackage($container);
    liveStartConsole($container);

    $providers = $container->get(LlmProvidersInterface::class);
    $prop = new ReflectionProperty(LlmProviders::class, 'providers');
    $all = $prop->getValue($providers);
    $entry = $all['minimax'];
    $entry['class'] = OpenAi::class;
    $base = rtrim((string) preg_replace('#/anthropic(?:/v1)?$#', '', rtrim((string) ($entry['baseUrl'] ?? ''), '/')), '/');
    if ($base === '') {
        $base = 'https://api.minimaxi.com';
    }
    if (!str_ends_with($base, '/v1')) {
        $base .= '/v1';
    }
    $entry['baseUrl'] = $base;
    $all['minimax'] = $entry;
    $prop->setValue($providers, $all);

    $host = $container->get(RecordingAgentManager::class)->mint([
        'profile' => Profile::decode([
            'id' => 'video-m3-audit',
            'prompt' => 'You answer briefly.',
            'tools' => ['media'],
            'skills' => [],
            'model' => 'minimax/M3',
            'max_steps' => 8,
        ]),
    ]);
    liveTrackOpenAgent($host);

    $host->run(Envelope::of("Analyze this video in one short sentence:\n{$videoUrl}", liveModel($container, 'minimax/M3')));

    $thread = $host->thread();
    $datadir = rtrim($thread->datadir, '/');
    echo "datadir: {$datadir}\n\n";

    echo "=== 1) stored ToolResult (MediaPart must remain) ===\n";
    $toolHits = 0;
    foreach ($thread->completions() as $completion) {
        $input = $completion->input;
        if (!$input instanceof ToolMessage) {
            continue;
        }
        foreach ($input->results as $result) {
            if (!$result instanceof ToolResult) {
                continue;
            }
            $toolHits++;
            $partKinds = [];
            foreach ($result->parts as $part) {
                $partKinds[] = $part instanceof MediaPart
                    ? 'MediaPart ref=' . $part->ref . ' mime=' . ($part->mime ?? '')
                    : $part::class;
            }
            echo '- name=' . ($result->name !== '' ? $result->name : '(pending)')
                . ' content=' . json_encode(mb_substr((string) $result->content, 0, 80), JSON_UNESCAPED_UNICODE)
                . ' parts=[' . implode('; ', $partKinds) . "]\n";
            if ($result->parts === [] || !($result->parts[0] instanceof MediaPart)) {
                throw new RuntimeException('stored ToolResult lost MediaPart');
            }
            $mp = $result->parts[0];
            if ($mp->ref !== $videoUrl) {
                throw new RuntimeException('stored ref mismatch: ' . $mp->ref);
            }
        }
    }
    if ($toolHits < 1) {
        throw new RuntimeException('no ToolResult with media');
    }

    echo "\n=== 2) disk transcript ===\n";
    foreach (['transcript.txt', 'transcript.md', 'transcript.jsonl'] as $name) {
        $path = $datadir . '/' . $name;
        echo existsLabel($path);
    }
    $txt = $datadir . '/transcript.txt';
    if (is_file($txt)) {
        $body = file_get_contents($txt);
        echo "--- transcript.txt (last 50 lines) ---\n";
        $lines = explode("\n", (string) $body);
        echo implode("\n", array_slice($lines, -50)) . "\n";
        if (str_contains((string) $body, 'data:video') || str_contains((string) $body, 'base64,')) {
            throw new RuntimeException('transcript.txt embeds base64 media — should keep ref only');
        }
        if (!str_contains((string) $body, $videoUrl) && !str_contains((string) $body, 'video')) {
            echo "(note: url may only live in jsonl)\n";
        }
    }
    $jsonl = $datadir . '/transcript.jsonl';
    if (is_file($jsonl)) {
        echo "--- transcript.jsonl media refs ---\n";
        foreach (file($jsonl, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || !str_contains($line, 'MediaPart') && !str_contains($line, '"type":"video"')) {
                if (!str_contains($line, 'mov_bbb') && !str_contains($line, 'MediaPart')) {
                    continue;
                }
            }
            // Compact: show MediaPart-ish snippets only.
            if (preg_match_all('/\{[^{}]*MediaPart[^{}]*\}/', $line, $m) === 1) {
                // fall through to json walk
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            dumpMediaRefsInRow($row);
        }
    } else {
        $fs = $container->get(FilesystemInterface::class);
        $turns = liveTranscriptJsonlTurns($fs, $datadir);
        echo 'transcript.jsonl turns=' . count($turns) . "\n";
    }

    echo "\n=== 3) OpenAI wire: historical tool media → unload notice ===\n";
    $llm = $providers->get('minimax/M3');
    if (!$llm instanceof OpenAi) {
        throw new RuntimeException('expected OpenAi after rebind');
    }
    // Pending follow-up makes prior completions historical (attachMedia: false).
    $turn = $thread->lastTurn();
    if ($turn === null) {
        throw new RuntimeException('no turn');
    }
    $turn->completions[] = Completion::of(UserMessage::of('What color is the butterfly?'));
    $working = $thread->lastCompletion();
    $encode = new ReflectionMethod(OpenAi::class, 'encodeWireMessages');
    /** @var list<array<string, mixed>> $wire */
    $wire = $encode->invoke($llm, $thread, '', $working);

    $notice = AbstractLlm::UNLOADED_MEDIA_NOTICE;
    $unloadHits = 0;
    $attachHits = 0;
    $dataUrlHits = 0;
    foreach ($wire as $i => $msg) {
        $role = $msg['role'] ?? '?';
        $summary = summarizeWireContent($msg['content'] ?? null, $notice, $unloadHits, $attachHits, $dataUrlHits);
        if ($summary === '') {
            continue;
        }
        echo "[{$i}] role={$role} {$summary}\n";
    }
    echo "unload_notices={$unloadHits} attached_av={$attachHits} data_urls={$dataUrlHits}\n";
    if ($unloadHits < 1) {
        throw new RuntimeException('historical tool media should become unload notice on wire');
    }
    if ($attachHits > 0) {
        // Working input is text-only; prior tool media must not re-attach as video_url.
        throw new RuntimeException('historical tool media must not stay attached on wire');
    }

    echo "\nok transcript audit\n";
});

function existsLabel(string $path): string
{
    $ok = is_file($path);
    echo ($ok ? '[x] ' : '[ ] ') . $path . ($ok ? ' (' . filesize($path) . " B)\n" : "\n");

    return '';
}

/**
 * @param array<string, mixed> $row
 */
function dumpMediaRefsInRow(array $row): void
{
    $stack = [$row];
    while ($stack !== []) {
        $cur = array_pop($stack);
        if (!is_array($cur)) {
            continue;
        }
        if (($cur['class'] ?? '') === MediaPart::class || (($cur['type'] ?? '') === 'video' || ($cur['type'] ?? '') === 'image')) {
            echo '  ref=' . json_encode($cur['ref'] ?? null, JSON_UNESCAPED_SLASHES)
                . ' mime=' . json_encode($cur['mime'] ?? null)
                . ' keys=' . implode(',', array_keys($cur)) . "\n";
            if (isset($cur['bytes']) || isset($cur['data']) || isset($cur['base64'])) {
                throw new RuntimeException('jsonl MediaPart carries payload bytes');
            }
        }
        foreach ($cur as $v) {
            if (is_array($v)) {
                $stack[] = $v;
            }
        }
    }
}

/**
 * @param mixed $content
 */
function summarizeWireContent(mixed $content, string $notice, int &$unloadHits, int &$attachHits, int &$dataUrlHits): string
{
    if (is_string($content)) {
        if (str_contains($content, $notice)) {
            $unloadHits++;

            return 'text=UNLOAD ' . json_encode(mb_substr($content, 0, 120), JSON_UNESCAPED_UNICODE);
        }
        $t = trim($content);

        return $t === '' ? '' : 'text=' . json_encode(mb_substr($t, 0, 80), JSON_UNESCAPED_UNICODE);
    }
    if (!is_array($content)) {
        return '';
    }
    $bits = [];
    foreach ($content as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = $block['type'] ?? '?';
        if ($type === 'text') {
            $text = (string) ($block['text'] ?? '');
            if (str_contains($text, $notice)) {
                $unloadHits++;
                $bits[] = 'text:UNLOAD+' . json_encode(mb_substr($text, 0, 90), JSON_UNESCAPED_UNICODE);
            } elseif ($text !== '') {
                $bits[] = 'text:' . json_encode(mb_substr($text, 0, 48), JSON_UNESCAPED_UNICODE);
            }
        } elseif ($type === 'video_url' || $type === 'image_url') {
            $attachHits++;
            $key = $type === 'video_url' ? 'video_url' : 'image_url';
            $url = (string) ($block[$key]['url'] ?? '');
            if (str_starts_with($url, 'data:')) {
                $dataUrlHits++;
                $bits[] = "{$type}:data(…)";
            } else {
                $bits[] = "{$type}:" . mb_substr($url, 0, 70);
            }
        } else {
            $bits[] = (string) $type;
        }
    }

    return $bits === [] ? '' : 'content=[' . implode(' | ', $bits) . ']';
}
