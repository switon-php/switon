<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval;

use Switon\Ai\Part\TextPart;
use Switon\Core\Json;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Thread;
use Switon\Ai\Message;
use Switon\Ai\Message\ToolMessage;

/** Read tool calls and assistant text from an agent {@see Thread}. */
class TranscriptInspector
{
    /**
     * @return list<array{name: string, content: mixed}>
     */
    public static function toolResults(Thread $thread): array
    {
        $results = [];
        foreach (self::messages($thread) as $message) {
            if (!$message instanceof ToolMessage) {
                continue;
            }
            foreach ($message->results as $result) {
                $text = self::contentText($result->content);
                foreach ($result->parts as $part) {
                    if ($part instanceof TextPart) {
                        $text .= $part->text;
                    }
                }
                $results[] = [
                    'name' => $result->name,
                    'content' => $text,
                ];
            }
        }

        return $results;
    }

    public static function calledTool(Thread $thread, string $name): bool
    {
        foreach (self::toolResults($thread) as $result) {
            if ($result['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    public static function toolContentContains(Thread $thread, string $toolName, string $needle): bool
    {
        foreach (self::toolResults($thread) as $result) {
            if ($result['name'] === $toolName && str_contains(self::contentText($result['content']), $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function contentText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return Json::stringify($content);
    }

    public static function lastAssistantText(Thread $thread): string
    {
        $text = '';
        foreach (self::messages($thread) as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }
            $body = $message->text();
            if ($body === null || $body === '') {
                continue;
            }
            $text = trim($body);
        }

        return $text;
    }

    /**
     * @return list<Message>
     */
    public static function messages(Thread $thread): array
    {
        $messages = [];
        foreach ($thread->completions() as $completion) {
            $messages[] = $completion->input;
            if ($completion->output !== null) {
                $messages[] = $completion->output;
            }
        }

        return $messages;
    }
}
