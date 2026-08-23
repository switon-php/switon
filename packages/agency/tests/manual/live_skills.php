<?php

declare(strict_types=1);

/**
 * Live skills smoke: catalog match → skill_load → read → answer.
 *
 *   php packages/agency/tests/manual/live_skills.php
 *   php packages/agency/tests/manual/live_skills.php live-demo "custom user task…"
 *
 * User task must NOT mention skill_load; the model should pick the skill from the catalog.
 */

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Skill\SkillsInterface;
use Switon\Core\PathAliasInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$skillName = $argv[1] ?? 'live-demo';
$secret = 'SKILL-LIVE-7742';
$skillsRoot = __DIR__ . '/skills';
$task = $argv[2] ?? 'What is the live demo verification secret? Reply with only the code.';

Runner::run(function (ContainerInterface $container) use ($skillName, $secret, $skillsRoot, $task): void {
liveBindTools($container, ['read', 'grep']);
liveAppendSkillPaths($container, [$skillsRoot]);
liveRegisterAgentPackage($container);
liveStartConsole($container);

$skills = $container->get(SkillsInterface::class);
if (!$skills->has($skillName)) {
    fwrite(STDERR, "Skill \"{$skillName}\" not found under {$skillsRoot}\n");
    exit(1);
}

$skillsPrompt = trim($container->get(\Switon\Agency\SegmentsInterface::class)->get('skills')->prompt);
$host = $container->get(RecordingAgentManager::class)->mint([
    'profile' => new \Switon\Ai\Profile(
        id: 'skills-smoke',
        prompt: $skillsPrompt,
        tools: ['read', 'grep', 'skill_load'],
        skills: [],
        model: 'main',
        maxSteps: 12,
    ),
]);
liveTrackOpenAgent($host);

$threadId = $host->threadId();
echo "Skills root: {$skillsRoot}\n";
echo "Skill: {$skillName}\n";
echo "Task: {$task}\n";
echo "thread={$threadId}\n\n";

try {
    liveRunInCoroutine(static function () use ($host, $task): void {
        $host->run(Envelope::of($task, liveModel($container, $host->profile()->model)));
    });
} catch (HttpStatusException $e) {
    fwrite(STDERR, "LLM HTTP {$e->getStatusCode()}: {$e->getResponseBody()}\n");
    exit(1);
} catch (\Switon\Agency\Exception $e) {
    fwrite(STDERR, "Agent error: {$e->getMessage()}\n");
    exit(1);
}

$sawSkillLoad = false;
$sawExpectedSkillLoad = false;
$sawFileRead = false;
$sawSkillRootLine = false;
$lastAssistant = '';

foreach (liveWireMessages($host->thread()->completions()) as $message) {
    if ($message instanceof ToolMessage) {
        foreach ($message->results as $result) {
                $body = $result->content;
                foreach ($result->parts as $part) {
                    if ($part instanceof \Switon\Ai\Part\TextPart) {
                        $body .= $part->text;
                    }
                }
                if ($result->name === 'skill_load') {
                    $sawSkillLoad = true;
                    if (str_contains($body, 'Skill root:')) {
                        $sawSkillRootLine = true;
                    }
                    if (str_contains($body, '# Live demo skill')) {
                        $sawExpectedSkillLoad = true;
                    }
                }
                if ($result->name === 'read' && str_contains($body, $secret)) {
                $sawFileRead = true;
            }
        }
        continue;
    }
    if ($message instanceof AssistantMessage) {
        $lastAssistant = trim((string) ($message->wire()[0]['text'] ?? $message->content ?? ''));
    }
}

echo "\n--- checks ---\n";
echo 'skill_load called: ' . ($sawSkillLoad ? 'yes' : 'NO') . "\n";
echo 'live-demo body loaded: ' . ($sawExpectedSkillLoad ? 'yes' : 'NO') . "\n";
echo 'Skill root line in result: ' . ($sawSkillRootLine ? 'yes' : 'NO') . "\n";
echo 'read saw secret: ' . ($sawFileRead ? 'yes' : 'NO') . "\n";
echo 'final assistant: ' . ($lastAssistant !== '' ? $lastAssistant : '(empty)') . "\n";

$answerOk = str_contains($lastAssistant, $secret);
echo 'answer contains secret: ' . ($answerOk ? 'yes' : 'NO') . "\n";

$runtime = $container->get(PathAliasInterface::class)->get('@runtime');
echo "\nSession: {$runtime}/threads/{$threadId}\n";
echo "REPL:    php packages/agency/bin/agent-chat.php --agent=host --thread={$threadId}\n";

liveKillAgentTree($host);

if (!$sawSkillLoad || !$sawExpectedSkillLoad || !$sawSkillRootLine || !$sawFileRead || !$answerOk) {
    fwrite(STDERR, "\nRESULT: FAIL\n");
    exit(1);
}

echo "\nRESULT: OK\n";
});
