<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Agency\Exception;
use Switon\Agency\SegmentsInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Profile;
use Switon\Ai\PromptRendererInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Lazy;
use Switon\Tooling\ToolsInterface;
use function in_array;
use function sort;
use function str_contains;
use function strpos;
use function substr;
use function trim;
use const SORT_STRING;

/**
 * Charter assembly for one {@see AgentInterface}.
 *
 * Guidance: prompt text is rendered by {@see PromptRendererInterface}. A bare segment ref on a
 * desk profile (`{deskId}.{leaf}`) uses that desk's own segment when the segment exists.
 */
class Charters implements ChartersInterface
{
    #[Autowired] protected SegmentsInterface $segments;
    #[Autowired] protected PromptRendererInterface $promptRenderer;
    #[Autowired] protected ToolsInterface|Lazy $tools;

    public function get(AgentInterface $agent): Charter
    {
        $profile = $agent->profile();

        $prompts = [];
        $tools = [];
        foreach ($profile->tools as $tool) {
            if (!in_array($tool, $tools, true)) {
                $tools[] = $tool;
            }
        }
        foreach ($this->segmentIds($profile) as $id) {
            if (!$this->segments->has($id)) {
                Exception::raise('Segment "{id}" not found.', ['id' => $id]);
            }
            $segment = $this->segments->get($id);
            $prompts[] = $segment->prompt;
            foreach ($segment->tools as $tool) {
                if (!in_array($tool, $tools, true)) {
                    $tools[] = $tool;
                }
            }
        }
        sort($tools, SORT_STRING);

        $data = [
            'agent' => ['is_root' => $agent->parent() === null],
            'tools' => $tools,
        ];

        $prompt = $this->promptRenderer->render($agent, $profile->prompt, $prompts, $data);
        $tools[] = 'recall';

        return Charter::of(prompt: $prompt, tools: $this->tools->pick($tools));
    }

    /**
     * Segment ids for this profile. A ref that contains <code>.</code> is already qualified.
     * A bare ref on a desk profile uses <code>{deskId}.{leaf}</code> when that segment exists.
     *
     * @return list<string>
     */
    protected function segmentIds(Profile $profile): array
    {
        $dot = strpos($profile->id, '.');
        $deskId = ($dot === false || $dot === 0) ? '' : substr($profile->id, 0, $dot);
        $ids = [];
        foreach ($profile->segments as $ref) {
            $id = trim((string)$ref);
            if ($deskId !== '' && !str_contains($id, '.')) {
                $namespaced = $deskId . '.' . $id;
                if ($this->segments->has($namespaced)) {
                    $id = $namespaced;
                }
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
