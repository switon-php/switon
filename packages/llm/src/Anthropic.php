<?php

declare(strict_types=1);

namespace Switon\Llm;

use stdClass;
use Switon\Ai\Completion;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\Message;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\DeveloperMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\ContentPart;
use Switon\Ai\Part\MediaPart;
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
use function preg_match;
use function rtrim;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Anthropic Messages API `POST /messages` application protocol.
 */
class Anthropic extends AbstractLlm
{
    public const string Protocol = 'anthropic';

    /** Stored on {@see AssistantMessage::$passback} for thinking block round-trip. */
    public const string THINKING_SIGNATURE = 'thinking_signature';

    protected const string HEADER_CONTENT_TYPE = 'Content-Type';
    protected const string HEADER_ACCEPT = 'Accept';
    protected const string HEADER_API_KEY = 'x-api-key';
    protected const string HEADER_ANTHROPIC_VERSION = 'anthropic-version';
    protected const string ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Normalize Anthropic base URLs from local tool configs (Claude Code, MiniMax, etc.).
     *
     * Our wire path is `{baseUrl}/messages`; official Anthropic uses `.../v1/messages`.
     * MiniMax docs use `https://api.minimaxi.com/anthropic` (SDK adds `/v1`), while some
     * Claude Code presets use `.../v1` without the `/anthropic` segment.
     */
    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return $baseUrl;
        }

        if (preg_match('#^https://api\.minimax(?:i)?\.(?:com|io)/v1$#', $baseUrl) === 1) {
            return preg_replace('#/v1$#', '/anthropic/v1', $baseUrl) ?? $baseUrl;
        }

        if (preg_match('#^https://api\.minimax(?:i)?\.(?:com|io)/anthropic$#', $baseUrl) === 1) {
            return $baseUrl . '/v1';
        }

        return $baseUrl;
    }

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
        [$system, $messages] = $this->encodeWireMessages(
            $requesting->thread,
            $requesting->charter->prompt,
            $requesting->completion,
        );

        $payload = [
            'model' => $this->model->model,
            'messages' => $messages,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($requesting->stream) {
            $payload['stream'] = true;
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
            self::HEADER_ACCEPT => $requesting->stream ? 'text/event-stream' : 'application/json',
            self::HEADER_ANTHROPIC_VERSION => self::ANTHROPIC_VERSION,
        ];

        if ($this->apiKey !== '') {
            $headers[self::HEADER_API_KEY] = $this->apiKey;
        }

        return [
            rtrim(self::normalizeBaseUrl($this->baseUrl), '/') . '/messages',
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

        $contentBlocks = $decoded['content'] ?? null;
        if (!is_array($contentBlocks)) {
            InvalidResponseException::raise('LLM response is missing content.');
        }

        [$content, $toolCalls, $reasoning, $passback] = $this->parseContentBlocks($contentBlocks);

        $completion->output = new AssistantMessage(
            $content,
            $toolCalls,
            $reasoning,
            passback: $passback,
        );
        $completion->finishReason = $this->parseStopReason($decoded['stop_reason'] ?? null);
        $completion->usage = $this->parseUsage($decoded['usage'] ?? null);
    }

    /** @param array<string, mixed> $decoded */
    protected function streamDeltaFromFrame(array $decoded): ?StreamDelta
    {
        $this->raiseProviderError($decoded);

        $eventType = is_string($decoded['type'] ?? null) ? $decoded['type'] : '';
        $delta = new StreamDelta();

        if ($eventType === 'message_start') {
            $message = is_array($decoded['message'] ?? null) ? $decoded['message'] : [];
            $delta->id = is_string($message['id'] ?? null) ? $message['id'] : '';
            $delta->usage = $this->parseUsage($message['usage'] ?? null);

            return $delta;
        }

        if ($eventType === 'content_block_start') {
            $block = $decoded['content_block'] ?? null;
            if (!is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                return null;
            }

            $index = $decoded['index'] ?? null;
            $delta->toolCallDeltas = [new ToolCallDelta(
                is_string($block['id'] ?? null) ? $block['id'] : '',
                is_string($block['name'] ?? null) ? $block['name'] : '',
                passback: is_int($index) ? ['index' => $index] : [],
                announcedAt: $this->clock->microtime(),
            )];

            return $delta;
        }

        if ($eventType === 'content_block_delta') {
            $frame = $decoded['delta'] ?? null;
            if (!is_array($frame)) {
                return null;
            }

            $deltaType = is_string($frame['type'] ?? null) ? $frame['type'] : '';
            $index = $decoded['index'] ?? null;
            $passback = is_int($index) ? ['index' => $index] : [];

            if ($deltaType === 'text_delta') {
                $delta->content = is_string($frame['text'] ?? null) ? $frame['text'] : '';

                return $delta;
            }

            if ($deltaType === 'thinking_delta') {
                $delta->reasoning = is_string($frame['thinking'] ?? null) ? $frame['thinking'] : '';

                return $delta;
            }

            if ($deltaType === 'signature_delta') {
                $signature = is_string($frame['signature'] ?? null) ? $frame['signature'] : '';
                if ($signature === '') {
                    return null;
                }
                $delta->passback = [self::THINKING_SIGNATURE => $signature];

                return $delta;
            }

            if ($deltaType === 'input_json_delta') {
                $delta->toolCallDeltas = [new ToolCallDelta(
                    partialJson: is_string($frame['partial_json'] ?? null) ? $frame['partial_json'] : '',
                    passback: $passback,
                    announcedAt: $this->clock->microtime(),
                )];

                return $delta;
            }

            return null;
        }

        if ($eventType === 'message_delta') {
            $frame = is_array($decoded['delta'] ?? null) ? $decoded['delta'] : [];
            $delta->finishReason = $this->parseStopReason($frame['stop_reason'] ?? null);
            $delta->usage = $this->parseUsage($decoded['usage'] ?? null);

            return $delta;
        }

        return null;
    }

    /**
     * Some OpenAI-compat / proxy layers emit empty `stop_reason` mid-stream.
     * Empty string must not abort the curl write loop ({@see HttpClient::shouldStopStream}).
     */
    protected function parseStopReason(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    protected function encodeWireMessages(Thread $thread, string $prompt, Completion $working): array
    {
        $systemParts = [];
        $messages = [];

        $prompt = trim($prompt);
        if ($prompt !== '') {
            $this->appendWireMessage($systemParts, $messages, SystemMessage::of($prompt), $thread);
        }

        foreach ($thread->turns as $turn) {
            $passback = $this->passbackReasoningFor($turn);
            foreach ($turn->completions as $completion) {
                if ($completion === $working) {
                    continue;
                }
                $this->appendWireMessage(
                    $systemParts,
                    $messages,
                    $completion->input,
                    $thread,
                    attachMedia: false,
                );

                if ($completion->output !== null) {
                    $this->appendWireMessage(
                        $systemParts,
                        $messages,
                        $completion->output,
                        $thread,
                        $passback,
                        attachMedia: false,
                    );
                }
            }
        }

        $this->appendWireMessage(
            $systemParts,
            $messages,
            $working->input,
            $thread,
            attachMedia: true,
        );

        return [implode("\n\n", $systemParts), $messages];
    }

    /**
     * @param list<string> $systemParts
     * @param list<array<string, mixed>> $messages
     */
    protected function appendWireMessage(
        array &$systemParts,
        array &$messages,
        Message $message,
        Thread $thread,
        bool $passbackReasoning = false,
        bool $attachMedia = true,
    ): void {
        if ($message instanceof SystemMessage || $message instanceof DeveloperMessage) {
            $part = $message->wire()[0]['text'];
            if ($part !== '') {
                $systemParts[] = $part;
            }

            return;
        }

        if ($message instanceof ToolMessage) {
            foreach ($message->results as $result) {
                $this->appendToolResult($messages, $thread, $result, $attachMedia);
            }

            return;
        }

        if ($message instanceof AssistantMessage) {
            $encoded = $this->encodeNativeAssistantMessage($message, $passbackReasoning);
            // Synthetic closer after terminal tools: empty content, no tool_use — omit from wire.
            if (($encoded['content'] ?? null) === [] || $encoded['content'] === '') {
                return;
            }
            $messages[] = $encoded;

            return;
        }

        $messages[] = $this->encodeNativeMessage($message, $thread);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    protected function appendToolResult(
        array &$messages,
        Thread $thread,
        ToolResult $result,
        bool $attachMedia = true,
    ): void {
        // Anthropic tool_result allows text/image(/document); video stays a sibling after tool_results.
        [$toolContent, $siblings] = $this->splitToolResultContent(
            $this->encodePartsContent(
                $thread,
                $result->wire(),
                $result->parts,
                $attachMedia,
            ),
        );
        $item = [
            'type' => 'tool_result',
            'tool_use_id' => $result->id,
            'content' => $toolContent,
        ];
        if ($result->isError) {
            $item['is_error'] = true;
        }

        $lastIndex = count($messages) - 1;
        $last = $messages[$lastIndex] ?? null;

        if (
            is_array($last)
            && ($last['role'] ?? '') === UserMessage::Role
            && is_array($last['content'] ?? null)
        ) {
            $messages[$lastIndex]['content'][] = $item;
            foreach ($siblings as $block) {
                $messages[$lastIndex]['content'][] = $block;
            }

            return;
        }

        $messages[] = [
            'role' => UserMessage::Role,
            'content' => array_merge([$item], $siblings),
        ];
    }

    /**
     * Pull {@code video} blocks out of tool_result content (siblings after tool_results in the user turn).
     *
     * @param string|list<array<string, mixed>> $content
     *
     * @return array{0: string|list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    protected function splitToolResultContent(string|array $content): array
    {
        if (is_string($content)) {
            return [$content, []];
        }

        $inside = [];
        $siblings = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'video') {
                $siblings[] = $block;
                continue;
            }
            $inside[] = $block;
        }

        if ($inside === [] && $siblings !== []) {
            $inside[] = ['type' => 'text', 'text' => 'Loaded media.'];
        }

        return [$this->compactContentBlocks($inside), $siblings];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return string|list<array<string, mixed>>
     */
    protected function compactContentBlocks(array $blocks): string|array
    {
        if ($blocks === []) {
            return '';
        }

        return $this->withLeadingText('', $blocks);
    }

    /**
     * @return array<string, mixed>
     */
    protected function encodeNativeMessage(
        Message $message,
        Thread $thread,
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

        return [
            'role' => $message->role,
            'content' => $message->wire()[0]['text'],
        ];
    }

    /**
     * Encode {@see ToolResult::$content}/{@see ToolResult::$parts} (or user parts) to Anthropic `content`.
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
     * @return array{type: 'image'|'video', source: array<string, mixed>}|array{type: 'text', text: string}
     */
    protected function encodeMediaPart(MediaPart $part, string $datadir): array
    {
        $mime = $part->mime ?? '';
        $isVideo = str_starts_with($mime, 'video/');
        $isImage = str_starts_with($mime, 'image/');
        if (!$isVideo && !$isImage) {
            return ['type' => 'text', 'text' => $this->unloadedMediaNotice($part->ref)];
        }

        // Anthropic media `source` is fixed-shape; do not merge MediaPart::$extra (e.g. fps) onto it.
        $resolved = $this->refReader->resolve($part, $datadir);
        if ($resolved === null || $resolved === '') {
            return ['type' => 'text', 'text' => $this->unloadedMediaNotice($part->ref)];
        }

        $type = $isVideo ? 'video' : 'image';
        if ($resolved === $part->ref) {
            return [
                'type' => $type,
                'source' => $this->anthropicMediaSourceUrl($resolved),
            ];
        }

        return [
            'type' => $type,
            'source' => $this->anthropicMediaSourceBase64($mime, $resolved),
        ];
    }

    /**
     * @return array{type: 'url', url: string}
     */
    protected function anthropicMediaSourceUrl(string $url): array
    {
        return [
            'type' => 'url',
            'url' => $url,
        ];
    }

    /**
     * @return array{type: 'base64', media_type: string, data: string}
     */
    protected function anthropicMediaSourceBase64(string $mime, string $bytes): array
    {
        return [
            'type' => 'base64',
            'media_type' => $mime,
            'data' => base64_encode($bytes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function encodeNativeAssistantMessage(
        AssistantMessage $message,
        bool $passbackReasoning = false,
    ): array {
        $blocks = [];
        $content = $message->wire()[0]['text'] ?? '';

        if ($passbackReasoning && $message->reasoning !== null) {
            $thinking = [
                'type' => 'thinking',
                'thinking' => $message->reasoning,
            ];
            $signature = $message->passback[self::THINKING_SIGNATURE] ?? null;
            if (is_string($signature) && $signature !== '') {
                $thinking['signature'] = $signature;
            }
            $blocks[] = $thinking;
        }

        if ($content !== '') {
            $blocks[] = [
                'type' => 'text',
                'text' => $content,
            ];
        }

        foreach ($message->toolCalls as $toolCall) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $this->parseToolCallInput($toolCall->arguments),
            ];
        }

        $item = [
            'role' => AssistantMessage::Role,
            'content' => $blocks,
        ];

        $passback = $message->passback;
        unset($passback[self::THINKING_SIGNATURE]);
        if ($passback !== []) {
            $item = array_replace($item, $passback);
        }

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
            $item = ['name' => $name];

            $description = (string) ($tool->definition['description'] ?? '');
            if ($description !== '') {
                $item['description'] = $description;
            }

            $item['input_schema'] = $tool->jsonSchema();
            $encoded[] = $item;
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function encodeToolChoice(?string $toolChoice): ?array
    {
        if ($toolChoice === null) {
            return null;
        }

        if ($toolChoice === 'auto') {
            return ['type' => 'auto'];
        }

        if ($toolChoice === 'none') {
            return ['type' => 'none'];
        }

        if ($toolChoice === 'required') {
            return ['type' => 'any'];
        }

        return ['type' => 'tool', 'name' => $toolChoice];
    }

    /**
     * Anthropic reasoning level lives inside `output_config`.
     *
     * @return array<string, mixed>
     */
    protected function effortPayload(?string $effort): array
    {
        return $effort === null ? [] : ['output_config' => ['effort' => $effort]];
    }

    /**
     * @param array<array-key, mixed> $blocks
     *
     * @return array{0: string, 1: list<ToolCall>, 2: ?string, 3: array<string, mixed>} reasoning in
     *                                                                                 slot 2; passback in slot 3
     */
    protected function parseContentBlocks(array $blocks): array
    {
        $content = '';
        $reasoning = null;
        $reasoningBuf = '';
        $toolCalls = [];
        $passback = [];
        $thinkingSignature = '';

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';

            if ($type === 'thinking') {
                $reasoningBuf .= is_string($block['thinking'] ?? null) ? $block['thinking'] : '';
                $reasoning = $reasoningBuf;
                if (is_string($block['signature'] ?? null) && $block['signature'] !== '') {
                    $thinkingSignature = $block['signature'];
                }
                continue;
            }

            if ($type === 'text') {
                $content .= is_string($block['text'] ?? null) ? $block['text'] : '';
                continue;
            }

            if ($type === 'tool_use') {
                $id = is_string($block['id'] ?? null) ? $block['id'] : '';
                $name = is_string($block['name'] ?? null) ? $block['name'] : '';
                if ($id === '') {
                    InvalidToolCallException::raise('LLM tool call is missing its id.', ['missing' => 'id']);
                }
                if ($name === '') {
                    InvalidToolCallException::raise('LLM tool call is missing its name.', ['missing' => 'name']);
                }
                $input = $block['input'] ?? new stdClass();

                $toolCalls[] = new ToolCall(
                    $id,
                    $name,
                    $this->inputToArray($input),
                    passback: ['type' => 'tool_use'],
                );
            }
        }

        if ($thinkingSignature !== '') {
            $passback[self::THINKING_SIGNATURE] = $thinkingSignature;
        }

        return [$content, $toolCalls, $reasoning, $passback];
    }

    /**
     * Raise when the wire payload carries a provider error object.
     *
     * Anthropic reports these both as a response body and as an `error` SSE frame,
     * so buffered and streaming parsing must both go through here.
     *
     * @param array<string, mixed> $decoded
     */
    protected function raiseProviderError(array $decoded): void
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
        if ($error === null && ($decoded['type'] ?? null) !== 'error') {
            return;
        }

        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : 'Unknown provider error.';
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $this->raiseContextOverflow($code, $message);

        ProviderErrorException::raise('LLM provider error: {message}', ['message' => $message]);
    }

    /**
     * @param string|array<string, mixed> $arguments
     *
     * @return array<string, mixed>|stdClass
     */
    protected function parseToolCallInput(string|array $arguments): array|stdClass
    {
        if (is_array($arguments)) {
            return $arguments === [] ? new stdClass() : $arguments;
        }

        if ($arguments === '') {
            return new stdClass();
        }

        try {
            $parsed = Json::parse($arguments);
            if (is_array($parsed)) {
                return $parsed === [] ? new stdClass() : $parsed;
            }
        } catch (Throwable) {
        }

        throw new InvalidToolArgumentsException(
            'Tool arguments must decode to a JSON object.',
            new ToolCall('', ''),
            $arguments,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function inputToArray(mixed $input): array
    {
        if (is_array($input)) {
            return $input;
        }

        if ($input instanceof stdClass) {
            return (array) $input;
        }

        return [];
    }

    /**
     * @param mixed $usage
     */
    protected function parseUsage(mixed $usage): ?TokenUsage
    {
        if (!is_array($usage)) {
            return null;
        }

        $uncached = is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null;
        $cacheRead = is_int($usage['cache_read_input_tokens'] ?? null) ? $usage['cache_read_input_tokens'] : null;
        $cacheWrite = is_int($usage['cache_creation_input_tokens'] ?? null) ? $usage['cache_creation_input_tokens'] : null;
        $output = is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null;

        if ($uncached === null && $cacheRead === null && $cacheWrite === null && $output === null) {
            return null;
        }

        return new TokenUsage(
            input: ($uncached ?? 0) + ($cacheRead ?? 0) + ($cacheWrite ?? 0),
            output: $output ?? 0,
            cacheRead: $cacheRead ?? 0,
            cacheWrite: $cacheWrite ?? 0,
        );
    }
}
