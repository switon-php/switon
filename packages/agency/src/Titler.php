<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Strings;
use Switon\Llm\LlmProvidersInterface;

use function count;
use function implode;
use function trim;

/** LLM titling. Prompt is a markdown file; override `$prompt` to customize. Callers decide when to invoke. */
class Titler implements TitlerInterface
{
    protected const string LEAD = 'Title the conversation below per your instructions.';

    #[Autowired] protected LlmProvidersInterface $llmProviders;
    #[Autowired] protected FilesystemInterface $filesystem;

    /** Catalog name for {@see LlmProvidersInterface::get()}. Default `fast`. */
    #[Autowired] protected string $model = 'fast';
    /** Prompt file (alias or path). Default `@agency/titler.md`. */
    #[Autowired] protected string $prompt = '@agency/titler.md';
    /** Max prompts wired from the opening of the thread. */
    #[Autowired] protected int $max = 7;

    public function title(Thread $thread): string
    {
        $asks = [];
        foreach ($thread->completions() as $completion) {
            $input = $completion->input;
            if (!$input instanceof UserMessage) {
                continue;
            }
            $text = $input->text();
            if ($text === null) {
                continue;
            }
            $asks[] = $text;
            if (count($asks) === $this->max) {
                break;
            }
        }
        if ($asks === []) {
            return '';
        }

        $llm = $this->llmProviders->get($this->model);
        $prompt = trim($this->filesystem->read($this->prompt));
        $body = self::LEAD . "\n\n" . implode("\n\n", $asks);
        $turn = Turn::of(Strings::uuid(), [Completion::of(UserMessage::of($body))], model: $this->model);
        $output = $llm->complete(new Thread($thread->id, [$turn]), Charter::of($this->model, prompt: $prompt))->output;

        return $output instanceof AssistantMessage ? trim((string)$output->content) : '';
    }
}
