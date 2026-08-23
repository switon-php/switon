<?php

declare(strict_types=1);

/**
 * Live probe: form_ask flat schema accuracy with real LLM.
 *
 *   php packages/agency/tests/manual/live_form_ask_schema.php
 */
if (!defined('SWITON_AGENT_LIVE_BOOTSTRAP_READY')) {
    define('SWITON_AGENT_LIVE_BOOTSTRAP_READY', true);
}

use Switon\Tooling\ToolsInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Turn;
use Switon\Ai\Thread;
use Switon\Ai\LlmInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

Runner::run(function (ContainerInterface $container): void {
liveBindTools($container, ['form_ask']);
liveBindFormAskTypes($container);

/** @var ToolsInterface $toolsRegistry */
$toolsRegistry = $container->get(ToolsInterface::class);
$tools = $toolsRegistry->pick(['form_ask']);
$formAskSchema = $tools['form_ask'];
$schemaJson = json_encode($formAskSchema->jsonSchema(), JSON_UNESCAPED_UNICODE);
$decoded = json_decode($schemaJson, true);
$fieldItem = $decoded['properties']['fields']['items'] ?? [];

echo "=== form_ask schema (+ .caption) ===\n";
echo 'JSON bytes: ' . strlen($schemaJson) . "\n";
echo 'additionalProperties: ' . (($fieldItem['additionalProperties'] ?? false) ? 'true' : 'false') . "\n\n";

/** @var LlmInterface $chat */
$chat = $container->get(LlmInterface::class);

$prompt = <<<'TXT'
Return exactly one form_ask tool call (no prose).

Build form id="range-accuracy" title="区间测试" with EXACTLY these 5 fields:
1. name=generic label=通用区间 type=range min=1 max=36
2. name=calendar label=日期范围 type=date-range min=2025-01-01 max=2025-01-31
3. name=slot label=时段 type=time-range
4. name=period label=报表月 type=month-range
5. name=phone label=电话 type=tel required=true maxlength=11

Use .caption in the user's language.
TXT;

echo "=== live LLM (form_ask only) ===\n";

try {
    $thread = new Thread('live-form-ask-range');
    $thread->turns[] = \Switon\Ai\Turn::of(
        't',
        completions: [Completion::of(UserMessage::of($prompt))],
        status: TurnStatus::Running,
        model: 'main',
    );
    $completion = $chat->complete(
        $thread,
        Charter::of(tools: ['form_ask' => $formAskSchema], prompt: 'Return one tool call only. No assistant prose.', toolChoice: 'form_ask'),
    );
} catch (Throwable $e) {
    liveFail($e);
}

$call = $completion->output?->toolCalls[0] ?? null;
if (!$call instanceof ToolCall || $call->name !== 'form_ask') {
    fwrite(STDERR, "LLM did not return form_ask.\n");
    if ($completion->output instanceof AssistantMessage) {
        fwrite(STDERR, 'content: ' . ($completion->output->content ?? '') . "\n");
    }
    exit(1);
}

$args = $call->arguments;
echo "\n--- llm form_ask args ---\n";
echo json_encode($args, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

/** @var array<string, array<string, mixed>> $byName */
$byName = [];
foreach ($args['fields'] ?? [] as $field) {
    if (!is_array($field)) {
        continue;
    }
    $name = $field['name'] ?? null;
    if (is_string($name) && $name !== '') {
        $byName[$name] = $field;
    }
}

function fieldCheck(array $byName, string $name, callable $assert): bool
{
    if (!isset($byName[$name])) {
        return false;
    }

    try {
        $assert($byName[$name]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

$score = 0;
$total = 0;

$run = static function (bool $ok, string $label) use (&$score, &$total): void {
    $total++;
    if ($ok) {
        $score++;
        echo "[OK]   {$label}\n";
        return;
    }

    echo "[FAIL] {$label}\n";
};

$run(fieldCheck($byName, 'generic', static function (array $field): void {
    if (($field['type'] ?? null) !== 'range') {
        throw new RuntimeException('expected type range');
    }
}), 'generic');
$run(fieldCheck($byName, 'calendar', static function (array $field): void {
    if (($field['type'] ?? null) !== 'date-range') {
        throw new RuntimeException('expected type date-range');
    }
}), 'calendar');
$run(fieldCheck($byName, 'slot', static function (array $field): void {
    if (($field['type'] ?? null) !== 'time-range') {
        throw new RuntimeException('expected type time-range');
    }
}), 'slot');
$run(fieldCheck($byName, 'period', static function (array $field): void {
    if (($field['type'] ?? null) !== 'month-range') {
        throw new RuntimeException('expected type month-range');
    }
}), 'period');
$run(fieldCheck($byName, 'phone', static function (array $field): void {
    if (($field['type'] ?? null) !== 'tel') {
        throw new RuntimeException('expected type tel');
    }
}), 'phone');
$run(($args['id'] ?? null) === 'range-accuracy', 'id');
$run(is_string($args['.caption'] ?? null) && trim($args['.caption']) !== '', 'caption');

$passed = $score === $total;
echo "\nAccuracy: {$score}/{$total}\n";
echo 'RESULT: ' . ($passed ? 'PASS' : 'FAIL') . "\n";
exit($passed ? 0 : 1);
});
