<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Path;
use Switon\Core\Strings;
use Switon\Lint\LinterInterface;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;

/** Tool `edit`. Exact replace of `old` with `new` in `path`. */
class EditTool extends AbstractTool
{
    #[Autowired] protected LinterInterface $linter;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Replace text in a file. On success, may include a `lint` report for the path when a driver exists.',
            properties: [
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute path.',
                ],
                'old' => [
                    'type' => 'string',
                    'description' => 'Text to find (non-empty).',
                    'minLength' => 1,
                ],
                'new' => [
                    'type' => 'string',
                    'description' => 'Replacement text.',
                ],
                'all' => [
                    'type' => 'boolean',
                    'description' => 'Replace every occurrence. Omit for first match only.',
                    'default' => false,
                ],
            ],
            required: ['path', 'old', 'new'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $path = Path::absolute($arguments['path']);
        $old = $arguments['old'];
        $new = $arguments['new'];
        $all = $arguments['all'] ?? false;

        if (!$this->filesystem->isFile($path)) {
            Exception::raise('File not found.');
        }

        $content = $this->filesystem->read($path);
        $applied = $this->replaceInContent($content, $old, $new, $all);
        $this->filesystem->write($path, $applied['content']);

        // Do not echo request args (`path` / `all`) — already on the call; wastes tokens.
        return $this->linter->attach([
            'replacements' => $applied['count'],
            // 1-based file line of the first match — edit preview pads to this for absolute gutters.
            'line' => $applied['line'],
        ], $path, fn (): bool => $agent->isCancelled() || $call->cancelled);
    }

    /**
     * Apply one StrReplace in memory (EOL-tolerant). Does not touch disk.
     *
     * @return array{content: string, count: int, line: int}
     */
    protected function replaceInContent(string $content, string $old, string $new, bool $all): array
    {
        // Model old/new use LF (read); map \n to file EOL and replace on original bytes.
        $oldLf = Strings::lf($old);
        $newLf = Strings::lf($new);

        foreach ($this->eolCandidates($content, $oldLf) as $eol) {
            $oldTry = $eol === "\n" ? $oldLf : str_replace("\n", $eol, $oldLf);
            $newTry = $eol === "\n" ? $newLf : str_replace("\n", $eol, $newLf);

            $applied = $this->tryReplace($content, $oldTry, $newTry, $all);
            if ($applied !== null) {
                return $applied;
            }
        }

        Exception::raise('old not found.');
    }

    /**
     * Primary file EOL first; if old has newlines, also try the other styles.
     *
     * @return list<string>
     */
    protected function eolCandidates(string $content, string $oldLf): array
    {
        $primary = Strings::eol($content);
        if (!str_contains($oldLf, "\n")) {
            return [$primary];
        }

        $out = [$primary];
        foreach (["\n", "\r\n", "\r"] as $eol) {
            if ($eol !== $primary) {
                $out[] = $eol;
            }
        }

        return $out;
    }

    /**
     * @return array{content: string, count: int, line: int}|null null = not found with this EOL
     */
    protected function tryReplace(string $content, string $old, string $new, bool $all): ?array
    {
        $first = strpos($content, $old);
        if ($first === false) {
            return null;
        }

        $line = substr_count(substr($content, 0, $first), "\n") + 1;

        if ($all) {
            $count = 0;
            $patched = str_replace($old, $new, $content, $count);

            return ['content' => $patched, 'count' => $count, 'line' => $line];
        }

        if (strpos($content, $old, $first + strlen($old)) !== false) {
            Exception::raise('old is not unique; set all or include more context.');
        }

        return [
            'content' => substr_replace($content, $new, $first, strlen($old)),
            'count' => 1,
            'line' => $line,
        ];
    }
}
