<?php

declare(strict_types=1);

namespace Switon\Llm\Command;

use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Switon\Llm\AbstractLlm;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProvidersInterface;
use Throwable;

use function implode;
use function is_string;
use function parse_url;
use function pathinfo;
use function strtolower;
use function trim;

/**
 * Catalog inspection and a one-shot completion.
 *
 * Media is a {@see MediaPart}. Each client encodes it for its own wire (OpenAI URL objects, Anthropic source blocks).
 *
 * @see \Switon\Llm\LlmProvidersInterface
 * @see \Switon\Ai\LlmInterface
 */
class LlmCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected LlmProvidersInterface $providers;

    /**
     * Prints the catalog. Alias keys are columns, not rows.
     *
     * @param bool $json One JSON array instead of a table
     */
    public function listAction(bool $json = false): int
    {
        $rows = $this->catalog();
        if ($rows === []) {
            return $this->console->error('No LLM models. Configure ~/.switon/llm.yml or bind providers on {service}.', [
                'service' => LlmDiscoveryInterface::class,
            ]);
        }
        if ($json) {
            $this->console->writeLn(Json::stringify($rows));

            return 0;
        }

        $this->console->table(
            ['id', 'model', 'class', 'baseUrl', 'image', 'video', 'efforts', 'aliases'],
            array_map($this->tableRow(...), $rows),
        );

        return 0;
    }

    /**
     * Prints one client. An empty name is the first catalog client.
     *
     * @param string $name Route id or alias
     * @param bool $json One JSON object instead of lines
     */
    public function showAction(string $name = '', bool $json = false): int
    {
        try {
            $llm = $this->providers->get($name);
        } catch (Throwable $e) {
            return $this->console->error($e->getMessage());
        }

        $row = $this->rowFor($llm);
        $row['extra'] = $llm->model()->extra;
        if ($json) {
            $this->console->writeLn(Json::stringify($row));

            return 0;
        }

        $this->console->writeLn('id:          ' . $row['id']);
        $this->console->writeLn('model:       ' . $row['model']);
        $this->console->writeLn('class:       ' . $row['class']);
        $this->console->writeLn('baseUrl:     ' . $row['baseUrl']);
        $this->console->writeLn('window:      ' . $row['window']);
        $this->console->writeLn('image:       ' . ($row['image'] ? 'yes' : 'no'));
        $this->console->writeLn('video:       ' . ($row['video'] ? 'yes' : 'no'));
        $this->console->writeLn('efforts:     ' . ($row['efforts'] === null ? '' : implode(',', $row['efforts'])));
        $this->console->writeLn('aliases:     ' . implode(',', $row['aliases']));
        $this->console->writeLn('description: ' . $row['description']);
        $this->console->writeLn('extra:       ' . Json::stringify($row['extra']));

        return 0;
    }

    /**
     * Sends one completion. Image and video URLs are refused when the catalog flag is off.
     *
     * @param string $name Route id or alias; empty is the first catalog client
     * @param string $message User text; empty asks for a short sentence, or a description when media is set
     * @param string $image Image URL
     * @param string $video Video URL
     */
    public function pingAction(string $name = '', string $message = '', string $image = '', string $video = ''): int
    {
        try {
            $llm = $this->providers->get($name);
        } catch (Throwable $e) {
            return $this->console->error($e->getMessage());
        }

        $model = $llm->model();
        if ($image !== '' && !$model->image) {
            return $this->console->error('{id} does not accept images.', ['id' => $model->id]);
        }
        if ($video !== '' && !$model->video) {
            return $this->console->error('{id} does not accept video.', ['id' => $model->id]);
        }

        $parts = [];
        if ($image !== '') {
            $parts[] = $this->media($image, 'image');
        }
        if ($video !== '') {
            $parts[] = $this->media($video, 'video');
        }
        if ($message === '') {
            $message = match (true) {
                $image !== '' && $video !== '' => 'Describe the image and the video in one short sentence each.',
                $image !== '' => 'What is in this picture? One short sentence.',
                $video !== '' => 'What happens in this video? One short sentence.',
                default => 'Reply with one short sentence.',
            };
        }

        $slot = Completion::of(UserMessage::of($message, $parts));
        $thread = new Thread('llm-ping');
        $thread->turns[] = Turn::of('t', [$slot], TurnStatus::Running, $model->id);

        try {
            $completion = $llm->complete($thread, Charter::of());
        } catch (HttpStatusException $e) {
            return $this->console->error($e->interruptMessage());
        } catch (Throwable $e) {
            return $this->console->error($e->getMessage());
        }

        $output = $completion->output;
        $reasoning = $output instanceof AssistantMessage ? trim((string)$output->reasoning) : '';
        $content = $output instanceof AssistantMessage ? trim((string)$output->content) : '';
        if ($reasoning === '' && $content === '') {
            return $this->console->error('Empty reply from {id}.', ['id' => $model->id]);
        }
        if ($reasoning !== '') {
            $this->console->writeLn($reasoning);
        }
        if ($content !== '' && $content !== $reasoning) {
            if ($reasoning !== '') {
                $this->console->writeLn('');
            }
            $this->console->writeLn($content);
        }

        return 0;
    }

    /**
     * @return list<array{id: string, model: string, class: string, baseUrl: string, window: int, image: bool, video: bool, efforts: list<string>|null, aliases: list<string>, description: string}>
     */
    protected function catalog(): array
    {
        /** @var array<string, LlmInterface> $clients */
        $clients = [];
        /** @var array<string, list<string>> $aliases */
        $aliases = [];
        foreach ($this->providers->all() as $name => $llm) {
            $id = $llm->model()->id;
            if ($name === $id) {
                $clients[$id] = $llm;
                continue;
            }
            $aliases[$id][] = $name;
        }

        $rows = [];
        foreach ($clients as $id => $llm) {
            $rows[] = $this->row($llm, $aliases[$id] ?? []);
        }

        return $rows;
    }

    /**
     * @return array{id: string, model: string, class: string, baseUrl: string, window: int, image: bool, video: bool, efforts: list<string>|null, aliases: list<string>, description: string}
     */
    protected function rowFor(LlmInterface $llm): array
    {
        foreach ($this->catalog() as $row) {
            if ($row['id'] === $llm->model()->id) {
                return $row;
            }
        }

        return $this->row($llm, []);
    }

    /**
     * @param list<string> $aliases
     *
     * @return array{id: string, model: string, class: string, baseUrl: string, window: int, image: bool, video: bool, efforts: list<string>|null, aliases: list<string>, description: string}
     */
    protected function row(LlmInterface $llm, array $aliases): array
    {
        $model = $llm->model();

        return [
            'id' => $model->id,
            'model' => $model->model,
            'class' => $llm::class,
            'baseUrl' => $llm instanceof AbstractLlm ? $llm->baseUrl() : '',
            'window' => $llm->window(),
            'image' => $model->image,
            'video' => $model->video,
            'efforts' => $model->efforts,
            'aliases' => $aliases,
            'description' => $model->description,
        ];
    }

    /**
     * @param array{id: string, model: string, class: string, baseUrl: string, window: int, image: bool, video: bool, efforts: list<string>|null, aliases: list<string>, description: string} $row
     *
     * @return list<string>
     */
    protected function tableRow(array $row): array
    {
        return [
            $row['id'],
            $row['model'],
            $row['class'],
            $row['baseUrl'],
            $row['image'] ? 'yes' : 'no',
            $row['video'] ? 'yes' : 'no',
            $row['efforts'] === null ? '' : implode(',', $row['efforts']),
            implode(',', $row['aliases']),
        ];
    }

    protected function media(string $url, string $kind): MediaPart
    {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        $mime = match (true) {
            $kind === 'image' && $ext === 'png' => 'image/png',
            $kind === 'image' && $ext === 'gif' => 'image/gif',
            $kind === 'image' && $ext === 'webp' => 'image/webp',
            $kind === 'image' => 'image/jpeg',
            $kind === 'video' && $ext === 'webm' => 'video/webm',
            $kind === 'video' && $ext === 'mov' => 'video/quicktime',
            default => 'video/mp4',
        };

        return MediaPart::of($url, $mime);
    }
}
