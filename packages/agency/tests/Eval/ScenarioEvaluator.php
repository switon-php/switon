<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval;

use Switon\Skill\Skill;
use Switon\Ai\Thread;

/** Score agent transcripts against scenario expectations. */
class ScenarioEvaluator
{
    /**
     * @param array{
     *     id: string,
     *     expect?: array{
     *         skill_load?: bool,
     *         skill_load_body_contains?: string,
     *         skill_root_line?: bool,
     *         read_contains?: string,
     *         answer_contains?: string,
     *         tools_called?: list<string>,
     *     },
     * } $scenario
     */
    public static function evaluateSkills(Thread $thread, array $scenario): ScenarioResult
    {
        $id = $scenario['id'];
        $expect = $scenario['expect'] ?? [];
        $failures = [];
        $assistant = TranscriptInspector::lastAssistantText($thread);

        if (($expect['skill_load'] ?? false) && !TranscriptInspector::calledTool($thread, 'skill_load')) {
            $failures[] = 'expected skill_load';
        }

        if (($expect['skill_root_line'] ?? false)
            && !TranscriptInspector::toolContentContains($thread, 'skill_load', 'Skill root:')) {
            $failures[] = 'expected Skill root: in skill_load result';
        }

        $bodyNeedle = $expect['skill_load_body_contains'] ?? null;
        if (is_string($bodyNeedle) && $bodyNeedle !== ''
            && !TranscriptInspector::toolContentContains($thread, 'skill_load', $bodyNeedle)) {
            $failures[] = 'skill_load body missing: ' . $bodyNeedle;
        }

        $readNeedle = $expect['read_contains'] ?? null;
        if (is_string($readNeedle) && $readNeedle !== ''
            && !TranscriptInspector::toolContentContains($thread, 'read', $readNeedle)) {
            $failures[] = 'read missing: ' . $readNeedle;
        }

        $answerNeedle = $expect['answer_contains'] ?? null;
        if (is_string($answerNeedle) && $answerNeedle !== '' && !str_contains($assistant, $answerNeedle)) {
            $failures[] = 'assistant missing: ' . $answerNeedle;
        }

        $toolsCalled = $expect['tools_called'] ?? null;
        if (is_array($toolsCalled)) {
            foreach ($toolsCalled as $tool) {
                if (!is_string($tool) || $tool === '') {
                    continue;
                }
                if (!TranscriptInspector::calledTool($thread, $tool)) {
                    $failures[] = 'expected tool: ' . $tool;
                }
            }
        }

        return $failures === []
            ? ScenarioResult::pass($id, $assistant)
            : ScenarioResult::fail($id, $failures, $assistant);
    }
}
