<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Strings;

/**
 * Shared tool base. `isReadonly()` is false. Oversized text goes through {@see SpillerInterface}.
 *
 * Guidance: a null thread datadir is clipped in memory; a set datadir spills under `tool-result/`.
 */
abstract class AbstractTool implements ToolInterface
{
    #[Autowired] protected SpillerInterface $spiller;
    #[Autowired] protected FilesystemInterface $filesystem;

    /** Inline / spill-start threshold in KiB (passed to {@see SpillerInterface::spill()}). */
    #[Autowired] protected int $budget = 32;
    /** Preview edge length in bytes (passed to {@see SpillerInterface::spill()}). */
    #[Autowired] protected int $keep = 1024;

    /** Schema for this instance. `$handler` is this tool. */
    protected ?ToolSchema $schema = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return false;
    }

    /**
     * Return `$data` when it fits. Otherwise spill `tool-result/{call}[-channel].txt` and return the preview.
     */
    protected function spillField(string $data, string $callId, AgentInterface $agent, string $channel = ''): string
    {
        $datadir = $agent->thread()->datadir;
        if ($datadir === null) {
            return Spiller::clipped($data, $this->budget, $this->keep);
        }

        return $this->spiller->spill(
            $datadir,
            self::spillFile($callId, $channel),
            $data,
            $this->budget,
            $this->keep,
        );
    }

    /** Relative spill path for one call and stream: `tool-result/{safe-id}[-channel].txt`. */
    public static function spillFile(string $callId, string $channel = ''): string
    {
        $leaf = Strings::fileName($callId);

        return 'tool-result/' . ($channel === '' ? $leaf : $leaf . '-' . $channel) . '.txt';
    }
}
