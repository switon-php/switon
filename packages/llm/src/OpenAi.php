<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\Completion;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\Message;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\ContentPart;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\Part\TextPart;
use Switon\Ai\StreamDelta;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolCallDelta;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Exception\InvalidResponseException;
use Switon\Llm\Exception\InvalidToolCallException;
use Switon\Llm\Exception\ProviderErrorException;
use Switon\Llm\Http\HttpClientInterface;
use Throwable;

use function base64_encode;
use function is_array;
use function is_int;
use function is_string;
use function rtrim;
use function str_starts_with;
use function substr;
use function trim;

/**
 * OpenAI-compatible `POST /chat/completions` application protocol.
 */
class OpenAi extends AbstractLlm
{
    public const string Protocol = 'openai';

    protected const string HEADER_CONTENT_TYPE = 'Content-Type';
    protected const string HEADER_ACCEPT = 'Accept';
    protected const string HEADER_AUTHORIZATION = 'Authorization';

    protected function wireComplete(CompletionRequesting $requesting): void
    {
        [$url, $headers, $payload] = $this->buildWireRequest($requesting);
        $response = $this->httpClient->post($url, $headers, $payload, $this->timeout);

        $this->readBody($response->body, $requesting->completion);
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: string}
     */
    protected function buildWireRequest(CompletionRequesting $requesting): array
    {
        $stream = $requesting->stream;

        $payload = [
            'model' => $this->model->model,
            'messages' => $this->encodeWireMessages(
                $requesting->thread,
                $requesting->charter->prompt,
                $requesting->completion,
            ),
            'stream' => $stream,
        ];

        if ($stream) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        $encodedTools = $this->encodeTools($requesting->charter->tools);
        if ($encodedTools !== []) {
            $payload['tools'] = $encodedTools;
        }

        $encodedToolChoice = $this->encodeToolChoice($requesting->charter->toolChoice);
        if ($encodedToolChoice !== null) {
            $payload['tool_choice'] = $encodedToolChoice;
        }

        $payload = $this->withExtra($payload, $requesting->charter);

        $headers = [
            self::HEADER_CONTENT_TYPE => 'application/json',
            self::HEADER_ACCEPT => $stream ? 'text/event-stream' : 'application/json',
        ];

        if ($this->apiKey !== '') {
            $headers[self::HEADER_AUTHORIZATION] = 'Bearer ' . $this->apiKey;
        }

        return [
            rtrim($this->baseUrl, '/') . '/chat/completions',
            $headers,
            Json::stringify($payload),
        ];
    }

    protected function readBody(string $body, Completion $completion): void
    {
        try {
            $decoded = Json::parse($body);
        } catch (Throwable $e) {
            InvalidResponseException::raise('Invalid LLM JSON response: {message}', ['message' => $e->getMessage()]);
        }

        if (!is_array($decoded)) {
            InvalidResponseException::raise('LLM response must decode to an object.');
        }

        $this->raiseProviderError($decoded);

        $choice = $decoded['choices'][0] ?? null;
        if (!is_array($choice)) {
            InvalidResponseException::raise('LLM response is missing choices.');
        }

        $message = $choice['message'] ?? null;
        if (!is_array($message)) {
            InvalidResponseException::raise('LLM response choice is missing message.');
        }

        $content = $this->parseAssistantContent($message['content'] ?? null);
        $refusal = is_string($message['refusal'] ?? null) ? $message['refusal'] : '';
        $toolCalls = $this->parseToolCalls($message['tool_calls'] ?? null);
        $parts = $refusal !== '' ? [RefusalPart::of($refusal)] : [];

        $completion->output = new AssistantMessage(
            ($content === '' && $parts !== []) ? null : $content,
            $toolCalls,
            $this->parseAssistantReasoning($message),
            [],
            $parts,
        );
        $completion->finishReason = $this->parseFinishReason($choice['finish_reason'] ?? null);
        $completion->usage = $this->parseUsage($decoded['usage'] ?? null);
    }

    protected function streamEndMarker(): string
    {
        return '[DONE]';
    }

    /** @param array<string, mixed> $decoded */
    protected function streamDeltaFromFrame(array $decoded): ?StreamDelta
    {
        $this->raiseProviderError($decoded);

        $choice = $decoded['choices'][0] ?? null;
        if (!is_array($choice)) {
            return null;
        }

        $wireDelta = is_array($choice['delta'] ?? null) ? $choice['delta'] : null;
        $content = $this->parseAssistantContent(is_array($wireDelta) ? ($wireDelta['content'] ?? null) : null);
        $refusal = is_array($wireDelta) && is_string($wireDelta['refusal'] ?? null) ? $wireDelta['refusal'] : '';

        return new StreamDelta(
            is_string($decoded['id'] ?? null) ? $decoded['id'] : '',
            $content,
            $wireDelta === null ? null : $this->parseAssistantReasoning($wireDelta),
            $this->parseFinishReason($choice['finish_reason'] ?? null),
            $this->parseUsage($decoded['usage'] ?? null),
            $this->parseToolCallDeltaDeltas(is_array($wireDelta) ? ($wireDelta['tool_calls'] ?? null) : null),
            [],
            $refusal,
        );
    }

    /**
     * OpenAI-compatible relays (e.g. kimitk) send `finish_reason: ""` on mid-stream chunks.
     * Empty string must not abort the curl write loop ({@see HttpClient::shouldStopStream}).
     */
    protected function parseFinishReason(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function encodeWireMessages(Thread $thread, string $prompt, Completion $working): array
    {
        $encoded = [];
        $prompt = trim($prompt);
        if ($prompt !== '') {
            $this->appendEncodedMessage($encoded, SystemMessage::of($prompt), $thread);
        }

        foreach ($thread->turns as $turn) {
            foreach ($turn->completions as $completion) {
                if ($completion === $working) {
                    continue;
                }
                $this->appendEncodedMessage($encoded, $completion->input, $thread, attachMedia: false);

                if ($completion->output === null) {
                    continue;
                }

                if ($completion->output instanceof AssistantMessage) {
                    $encoded[] = $this->encodeNativeMessage(
                        $completion->output,
                        $thread,
                        $this->passbackReasoningFor($turn),
                    );
                    continue;
                }

                $this->appendEncodedMessage($encoded, $completion->output, $thread, attachMedia: false);
            }
        }

        $this->appendEncodedMessage($encoded, $working->input, $thread, attachMedia: true);

        return $encoded;
    }

    /**
     * @param list<array<string, mixed>> $encoded
     */
    protected function appendEncodedMessage(
        array &$encoded,
        Message $message,
        Thread $thread,
        bool $attachMedia = true,
    ): void {
        if ($message instanceof ToolMessage) {
            foreach ($message->results as $result) {
                $encoded[] = $this->encodeToolResult($thread, $result, $attachMedia);
            }

            return;
        }

        $encoded[] = $this->encodeNativeMessage($message, $thread);
    }

    /**
     * @return array<string, mixed>
     */
    protected function encodeToolResult(
        Thread $thread,
        ToolResult $result,
        bool $attachMedia = true,
    ): array {
        $item = [
            'role' => ToolMessage::Role,
            'content' => $this->encodePartsContent(
                $thread,
                $result->wire(),
                $result->parts,
                $attachMedia,
            ),
            'tool_call_id' => $result->id,
            'name' => $result->name,
        ];

        return $item;
    }

    /**
     * Protocol hook: encode caller tool schemas for the wire request.
     *
     * @param array<string, ToolSchema> $tools
     *
     * @return list<array<string, mixed>>
     */
    protected function encodeTools(array $tools): array
    {
        $encoded = [];
        foreach ($tools as $name => $tool) {
            $function = ['name' => $name];

            $description = (string) ($tool->definition['description'] ?? '');
            if ($description !== '') {
                $function['description'] = $description;
            }

            $function['parameters'] = $tool->jsonSchema();

            $encoded[] = [
                'type' => 'function',
                'function' => $function,
            ];
        }

        return $encoded;
    }

    /**
     * @return string|array<string, mixed>|null
     */
    protected function encodeToolChoice(?string $toolChoice): string|array|null
    {
        if ($toolChoice === null) {
            return null;
        }

        if ($toolChoice === 'auto' || $toolChoice === 'none' || $toolChoice === 'required') {
            return $toolChoice;
        }

        return ['type' => 'function', 'function' => ['name' => $toolChoice]];
    }

    /**
     * OpenAI-compatible reasoning level field.
     *
     * @return array<string, mixed>
     */
    protected function effortPayload(?string $effort): array
    {
        return $effort === null ? [] : ['reasoning_effort' => $effort];
    }

    /**
     * @return array<string, mixed>
     */
    protected function encodeNativeMessage(
        Message $message,
        Thread $thread,
        bool $passbackReasoning = false,
    ): array {
        if ($message instanceof UserMessage) {
            return [
                'role' => $message->role,
                'content' => $this->encodePartsContent(
                    $thread,
                    '',
                    $message->parts,
                ),
            ];
        }

        if ($message instanceof FoldedMessage) {
            return [
                'role' => $message->role,
                'content' => $this->withLeadingText('', $message->wire()),
            ];
        }

        $content = $message->wire()[0]['text'] ?? '';
        $item = [
            'role' => $message->role,
            'content' => $content,
        ];

        if ($message instanceof AssistantMessage) {
            foreach ($message->parts as $part) {
                if ($part instanceof RefusalPart && $part->refusal !== '') {
                    $item['refusal'] = $part->refusal;
                    if ($content === '') {
                        $item['content'] = null;
                    }
                    break;
                }
            }

            if ($message->toolCalls !== []) {
                $item['tool_calls'] = $this->encodeToolCalls($message->toolCalls);

                if ($content === '') {
                    $item['content'] = null;
                }
            }

            if ($passbackReasoning && $message->reasoning !== null) {
                $item['reasoning_content'] = $message->reasoning;
            }

            if ($message->passback !== []) {
                $item = array_replace($item, $message->passback);
            }
        }

        return $item;
    }

    /**
     * Encode {@see ToolResult::$content}/{@see ToolResult::$parts} (or user parts) to OpenAI `content`.
     *
     * When `$attachMedia` is false (historical tool results), each {@see MediaPart} becomes an
     * unload text notice with its ref; {@see ToolResult} storage is unchanged.
     *
     * @param list<ContentPart> $parts
     *
     * @return string|list<array<string, mixed>>
     */
    protected function encodePartsContent(
        Thread $thread,
        mixed $content,
        array $parts,
        bool $attachMedia = true,
    ): string|array {
        $blocks = [];
        foreach ($parts as $part) {
            if ($part instanceof MediaPart) {
                if (!$attachMedia) {
                    $blocks[] = ['type' => 'text', 'text' => $this->unloadedMediaNotice($part->ref)];
                    continue;
                }
                $blocks[] = $this->encodeMediaPart($part, $thread->datadir ?? '');
                continue;
            }
            if ($part instanceof TextPart && $part->text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $part->text];
            }
        }

        return $this->withLeadingText(Json::text($content), $blocks);
    }

    /**
     * @return array<string, mixed> OpenAI content block (`image_url`, `video_url`, or `text`)
     */
    protected function encodeMediaPart(MediaPart $part, string $datadir): array
    {
        $mime = $part->mime ?? '';
        $isVideo = str_starts_with($mime, 'video/');
        $isImage = str_starts_with($mime, 'image/');
        if (!$isVideo && !$isImage) {
            return ['type' => 'text', 'text' => $this->unloadedMediaNotice($part->ref)];
        }

        $resolved = $this->refReader->resolve($part, $datadir);
        if ($resolved === null || $resolved === '') {
            return ['type' => 'text', 'text' => $this->unloadedMediaNotice($part->ref)];
        }

        if ($resolved === $part->ref) {
            $media = $this->withMediaExtra(['url' => $resolved], $part);

            return $isVideo
                ? ['type' => 'video_url', 'video_url' => $media]
                : ['type' => 'image_url', 'image_url' => $media];
        }

        $media = $this->withMediaExtra([
            'url' => 'data:' . $mime . ';base64,' . base64_encode($resolved),
        ], $part);

        return $isVideo
            ? ['type' => 'video_url', 'video_url' => $media]
            : ['type' => 'image_url', 'image_url' => $media];
    }

    /**
     * Normalize assistant `content`: string as-is; array of parts → concatenated `text` blocks.
     */
    protected function parseAssistantContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }

            $type = is_string($part['type'] ?? null) ? $part['type'] : 'text';
            if ($type !== 'text') {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * Prefer domestic `reasoning_content`, then OpenRouter-style `reasoning`.
     *
     * @param array<string, mixed> $message
     */
    protected function parseAssistantReasoning(array $message): ?string
    {
        if (array_key_exists('reasoning_content', $message) && is_string($message['reasoning_content'])) {
            return $message['reasoning_content'];
        }

        if (array_key_exists('reasoning', $message) && is_string($message['reasoning'])) {
            return $message['reasoning'];
        }

        return null;
    }

    /**
     * @param list<ToolCall> $toolCalls
     *
     * @return list<array<string, mixed>>
     */
    protected function encodeToolCalls(array $toolCalls): array
    {
        $encoded = [];
        foreach ($toolCalls as $toolCall) {
            $item = [
                'id' => $toolCall->id,
                'type' => 'function',
                'function' => [
                    'name' => $toolCall->name,
                    'arguments' => is_array($toolCall->arguments)
                        ? json_encode($toolCall->arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                        : $toolCall->arguments,
                ],
            ];
            if ($toolCall->passback !== []) {
                $item = array_replace($item, $toolCall->passback);
                $item['type'] = 'function';
            }
            $encoded[] = $item;
        }

        return $encoded;
    }

    /**
     * Raise when the wire payload carries a provider error object.
     *
     * Gateways return these both as a non-2xx body and as a `200` body or a mid-stream SSE frame,
     * so buffered and streaming parsing must both go through here.
     *
     * @param array<string, mixed> $decoded
     */
    protected function raiseProviderError(array $decoded): void
    {
        if (!isset($decoded['error']) || !is_array($decoded['error'])) {
            return;
        }

        $message = is_string($decoded['error']['message'] ?? null)
            ? $decoded['error']['message']
            : 'Unknown provider error.';
        $code = is_string($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : null;
        $this->raiseContextOverflow($code, $message);

        ProviderErrorException::raise('LLM provider error: {message}', ['message' => $message]);
    }

    /**
     * @param mixed $usage
     */
    protected function parseUsage(mixed $usage): ?TokenUsage
    {
        if (!is_array($usage)) {
            return null;
        }

        $input = is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null;
        $output = is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : null;
        $reasoning = $this->usageDetailInt($usage, 'completion_tokens_details', 'reasoning_tokens');
        $cacheRead = $this->usageDetailInt($usage, 'prompt_tokens_details', 'cached_tokens');
        $cacheWrite = $this->usageDetailInt($usage, 'prompt_tokens_details', 'cache_write_tokens');

        if (
            $input === null
            && $output === null
            && $reasoning === null
            && $cacheRead === null
            && $cacheWrite === null
        ) {
            return null;
        }

        return new TokenUsage(
            input: $input ?? 0,
            output: $output ?? 0,
            cacheRead: $cacheRead ?? 0,
            cacheWrite: $cacheWrite ?? 0,
            reasoning: $reasoning ?? 0,
        );
    }

    /**
     * @param array<string, mixed> $usage
     */
    protected function usageDetailInt(array $usage, string $detailsKey, string $field): ?int
    {
        $details = $usage[$detailsKey] ?? null;
        if (!is_array($details)) {
            return null;
        }

        return is_int($details[$field] ?? null) ? $details[$field] : null;
    }

    /**
     * @param mixed $toolCalls
     *
     * @return list<ToolCall>
     */
    protected function parseToolCalls(mixed $toolCalls): array
    {
        if (!is_array($toolCalls)) {
            return [];
        }

        $parsed = [];
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                InvalidResponseException::raise('LLM tool call entry must decode to an object.');
            }

            $parsed[] = $this->decodeWireToolCall($toolCall);
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @throws InvalidToolCallException when the wire call is missing its function object, id, or name
     */
    protected function decodeWireToolCall(array $item): ToolCall
    {
        $function = $item['function'] ?? null;
        if (!is_array($function)) {
            InvalidToolCallException::raise('LLM tool call is missing its function object.', ['missing' => 'function']);
        }

        $id = is_string($item['id'] ?? null) ? $item['id'] : '';
        $name = is_string($function['name'] ?? null) ? $function['name'] : '';
        $json = is_string($function['arguments'] ?? null) ? $function['arguments'] : '';

        if ($id === '') {
            InvalidToolCallException::raise('LLM tool call is missing its id.', ['missing' => 'id']);
        }
        if ($name === '') {
            InvalidToolCallException::raise('LLM tool call is missing its function name.', ['missing' => 'function.name']);
        }

        $payload = $this->decodeToolArguments($json);
        if ($payload === null) {
            throw new InvalidToolArgumentsException(
                'Tool "{name}" arguments must decode to a JSON object.',
                new ToolCall($id, $name),
                $json,
                ['name' => $name],
            );
        }

        return new ToolCall(
            $id,
            $name,
            $payload,
            passback: $this->parseToolCallPassback($item),
        );
    }

    /**
     * @param mixed $toolCalls
     *
     * @return list<ToolCallDelta>
     */
    protected function parseToolCallDeltaDeltas(mixed $toolCalls): array
    {
        if (!is_array($toolCalls)) {
            return [];
        }

        $parsed = [];
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $function = $toolCall['function'] ?? null;
            $function = is_array($function) ? $function : [];

            $id = is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '';
            $name = is_string($function['name'] ?? null) ? $function['name'] : '';
            $arguments = is_string($function['arguments'] ?? null) ? $function['arguments'] : '';
            $passback = $this->parseToolCallPassback($toolCall);

            $index = $passback['index'] ?? null;
            if (!is_int($index) && $id === '' && $name === '' && $arguments === '') {
                continue;
            }

            $parsed[] = new ToolCallDelta(
                $id,
                $name,
                $arguments,
                $passback,
                $this->clock->microtime(),
            );
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $toolCall
     *
     * @return array<string, mixed>
     */
    protected function parseToolCallPassback(array $toolCall): array
    {
        unset($toolCall['id'], $toolCall['function']);

        return $toolCall;
    }
}
