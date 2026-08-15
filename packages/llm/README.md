# Switon LLM Package

[![LLM CI](https://img.shields.io/github/actions/workflow/status/switon-php/llm/ci.yml?branch=main&label=LLM%20CI)](https://github.com/switon-php/llm/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's chat client for application services that want a named model catalog, an OpenAI-compatible request, and one injected `LlmInterface`.

## Highlights

- **Named models:** each entry in `~/.switon/llm.yml` is a model you can call by name.
- **Aliases:** a short name such as `main` or `fast` points at one model. The first declaration wins.
- **Default model:** an empty name uses the first model in the catalog.
- **Chat protocols:** OpenAI-compatible chat by default, and Anthropic Messages when a connection asks for it.
- **Inspect and ping:** list the catalog, show one model, or send a one-shot reply from the command line.

## Installation

```bash
composer require switon/llm
```

## Quick Start

```php
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\Attribute\Autowired;

class ChatService
{
    #[Autowired] protected LlmInterface $llm;

    public function ask(string $text): ?string
    {
        $slot = Completion::of(UserMessage::of($text));
        $thread = Thread::of('chat');
        $thread->turns[] = Turn::of('ask', [$slot], TurnStatus::Running, $this->llm->model()->id);

        return $this->llm->complete($thread, Charter::of())->output?->text();
    }
}
```

Docs: https://github.com/switon-php/llm/blob/main/docs/README.md

## License

MIT.
