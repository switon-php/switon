<?php

declare(strict_types=1);

namespace App\Controller;

use App\Docfs\AssetStore;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\FilesystemInterface;
use Switon\Http\Request\FileInterface;
use Switon\Http\ResponseInterface;
use Switon\Routing\Attribute\DeleteMapping;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

use function is_string;
use function pathinfo;
use function strtolower;

/**
 * HTTP blob API for book assets (same JWT as MCP: Bearer / access_key).
 *
 * MCP does not transfer asset bytes; agents link returned `file` paths in Markdown.
 */
#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/books')]
class AssetController extends Controller
{
    #[Autowired] protected AssetStore $assets;
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * Upload: multipart field `file` + `git_message` (optional `name` override).
     *
     * Auth: `Authorization: Bearer <jwt>` or `?access_key=` (same as MCP).
     *
     * @return array{file: string, original: string, size: int, mtime: int, sha: string}
     */
    #[PostMapping('{book}/assets')]
    public function uploadAction(string $book): array
    {
        $upload = $this->request->file('file');
        if (!$upload instanceof FileInterface) {
            InvalidArgumentException::raise('multipart field "file" required');
        }

        $gitMessage = $this->request->post('git_message', $this->request->query('git_message'));
        if (!is_string($gitMessage)) {
            InvalidArgumentException::raise('git_message required');
        }

        $original = $this->request->post('name', $this->request->query('name'));
        if (!is_string($original) || $original === '') {
            $original = $upload->getName();
        }

        return $this->assets->put($book, $original, $this->filesystem->read($upload->getTempName()), $gitMessage);
    }

    #[GetMapping('{book}/assets/{name}')]
    public function downloadAction(string $book, string $name): ResponseInterface
    {
        $entry = $this->assets->get($book, $name);

        return $this->response->raw($entry['content'], $this->mimeFor($entry['file']));
    }

    /**
     * @return array{file: string, deleted: true}
     */
    #[DeleteMapping('{book}/assets/{name}')]
    public function deleteAction(string $book, string $name): array
    {
        $sha = $this->request->query('sha', $this->request->post('sha'));
        $gitMessage = $this->request->query('git_message', $this->request->post('git_message'));
        if (!is_string($gitMessage)) {
            InvalidArgumentException::raise('git_message required');
        }

        return $this->assets->rm(
            $book,
            $name,
            is_string($sha) ? $sha : null,
            $gitMessage,
        );
    }

    private function mimeFor(string $rel): string
    {
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'json' => 'application/json',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}
