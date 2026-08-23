<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Live\Tools;

use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Tests\Fixtures\RecordingTool;
use Switon\Agency\Tests\Live\LiveTestCase;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\InstructorInterface;
use Switon\Ai\LlmInterface;
use Switon\Ai\Profile;
use Switon\Ai\ToolInterface;
use Switon\Tooling\ToolsInterface;

use function array_filter;
use function array_values;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Base for tool-behavior live tests: drive a real LLM through {@see InstructorInterface::ask()}
 * while swapping the tool under test for a {@see RecordingTool}.
 *
 * Guidance: ask is profile-driven, so each test grants exactly its wires on the profile
 * ({@see toolProfile()}) and registers recording wrappers ({@see recording()}) — the model sees
 * the granted wires plus the terminal response tool, nothing else.
 *
 *   bash tests/run.sh agency live -- --filter LsToolLiveTest
 *
 * @group live
 */
#[Group('live')]
abstract class ToolsLiveTestCase extends LiveTestCase
{
    /** @var array<string, RecordingTool> wire name => recorder */
    protected array $recordedWires = [];

    /** @var list<string> */
    protected array $fixtureDirs = [];

    /** Gate on the switon LLM catalog instead of Claude Code settings ({@see LiveTestCase::requireLiveLlm()}). */
    protected function requireLiveLlm(): void
    {
        $path = (getenv('HOME') ?: '') . '/.switon/llm.yml';
        if (!is_file($path)) {
            $this->markTestSkipped('LLM catalog missing at ~/.switon/llm.yml (see packages/llm/resources/llm.yml.example)');
        }
    }

    /** Model identity comes from the catalog (`main`), so accept any provider instance. */
    protected function assertLiveLlmBound(): void
    {
        $this->assertInstanceOf(LlmInterface::class, $this->container->get(LlmInterface::class));
    }

    protected function instructor(): InstructorInterface
    {
        return $this->container->make(InstructorInterface::class);
    }

    /**
     * Instruct profile granting exactly {@see $wires}; the instructor appends the terminal response wire.
     *
     * @param list<string> $wires
     */
    protected function toolProfile(array $wires, int $maxSteps = 8, ?string $model = null): Profile
    {
        $profile = clone $this->container->get(ProfilesInterface::class)->get('instructor');
        $profile->tools = $wires;
        $profile->maxSteps = $maxSteps;
        if ($model !== null) {
            $profile->model = $model;
        }

        return $profile;
    }

    /** Swap the catalog entry {@see $wire} for a recording wrapper; returns the recorder. */
    protected function recording(string $wire, ToolInterface $inner): RecordingTool
    {
        $recorder = new RecordingTool($inner);
        $this->container->get(ToolsInterface::class)->addTool($wire, $recorder, overwrite: true);
        $this->recordedWires[$wire] = $recorder;

        return $recorder;
    }

    /**
     * @return array{answer: array<string, mixed>}
     */
    protected function responseProperties(): array
    {
        return [
            'answer' => [
                'type' => 'string',
                'description' => 'Short final answer for the user.',
            ],
        ];
    }

    /**
     * All recorded tool calls across wires, in call order.
     *
     * @return list<array{seq: int, name: string, arguments: array<string, mixed>, result: mixed}>
     */
    protected function recordedCalls(): array
    {
        $calls = [];
        foreach ($this->recordedWires as $recorder) {
            foreach ($recorder->calls as $record) {
                $calls[] = $record;
            }
        }

        usort($calls, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

        return $calls;
    }

    /**
     * @param list<array{seq: int, name: string, arguments: array<string, mixed>, result: mixed}> $calls
     *
     * @return list<array{seq: int, name: string, arguments: array<string, mixed>, result: mixed}>
     */
    protected function callsNamed(array $calls, string $name): array
    {
        return array_values(array_filter(
            $calls,
            static fn (array $call): bool => $call['name'] === $name,
        ));
    }

    protected function registerFixtureDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-agency-tool-live-' . uniqid('', true);
        mkdir($dir);
        $this->fixtureDirs[] = $dir;

        return $dir;
    }

    /** @return array{0: string, 1: string, 2: string} dir, needle file, needle token */
    protected function writeGrepFixture(): array
    {
        $dir = $this->registerFixtureDir();

        $needle = 'SWITON_GREP_' . strtoupper(bin2hex(random_bytes(4)));
        file_put_contents($dir . '/a.txt', "alpha\nnoise\n");
        file_put_contents($dir . '/b.txt', "beta\n{$needle} here\ngamma\n");
        file_put_contents($dir . '/c.md', "markdown only\n");

        return [$dir, $dir . '/b.txt', $needle];
    }

    protected function writeLineFixture(int $lineCount = 120): string
    {
        $dir = $this->registerFixtureDir();

        $path = $dir . '/sample.txt';
        $lines = [];
        for ($i = 1; $i <= $lineCount; $i++) {
            $lines[] = "LINE-{$i}";
        }
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || str_contains($entry, "\0")) {
                    continue;
                }
                @unlink($dir . '/' . $entry);
            }
            @rmdir($dir);
        }
        $this->fixtureDirs = [];
        $this->recordedWires = [];

        parent::tearDown();
    }
}
