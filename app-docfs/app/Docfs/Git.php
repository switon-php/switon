<?php

declare(strict_types=1);

namespace App\Docfs;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\PathAliasInterface;

/**
 * Thin git CLI wrapper for book repos (proc_open + common verbs).
 * <code>$dir</code> may be an alias path (e.g. <code>@books/demo</code>).
 */
final class Git
{
    /** Short blob/commit id length (git-style); enough for typical book size. */
    public const SHA_ABBREV = 7;

    #[Autowired] protected PathAliasInterface $pathAlias;

    /** @param list<string> $args */
    public function run(string $dir, array $args, bool $throw = true): string
    {
        $dir = $this->resolve($dir);
        $cmd = array_merge(['git', '-C', $dir], $args);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptor, $pipes, null, null);
        if (!is_resource($proc)) {
            RuntimeException::raise('Failed to run git');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($throw && $code !== 0) {
            RuntimeException::raise('git failed: {error}', ['error' => trim($stderr ?: $stdout)]);
        }

        return $stdout;
    }

    /** Git blob id (`git hash-object` / same as ls-tree blob sha). */
    public function hashObject(string $bytes): string
    {
        return sha1('blob ' . strlen($bytes) . "\0" . $bytes);
    }

    /** Shorten a full hex sha for tool results. */
    public function abbrev(string $sha): string
    {
        return substr($sha, 0, self::SHA_ABBREV);
    }

    /**
     * Whether `$given` matches `$full` (full or unique prefix, min {@see SHA_ABBREV}).
     */
    public function shaMatches(string $full, string $given): bool
    {
        $given = strtolower($given);
        if (!preg_match('/^[0-9a-f]{' . self::SHA_ABBREV . ',40}$/', $given)) {
            return false;
        }

        return hash_equals(substr(strtolower($full), 0, strlen($given)), $given);
    }

    public function assertSafeRev(string $rev): void
    {
        if (str_contains($rev, '..') || str_contains($rev, ':') || str_contains($rev, "\0")) {
            InvalidArgumentException::raise('Invalid rev');
        }
    }

    public function head(string $dir): ?string
    {
        $dir = $this->resolve($dir);
        if (!is_dir($dir . '/.git')) {
            return null;
        }
        $sha = trim($this->run($dir, ['rev-parse', 'HEAD'], false));

        return $sha !== '' && !str_contains($sha, 'fatal') ? $sha : null;
    }

    public function resolveRev(string $dir, string $rev): string
    {
        $sha = trim($this->run($dir, ['rev-parse', $rev], false));
        if ($sha === '' || str_contains($sha, 'fatal')) {
            InvalidArgumentException::raise('Unknown rev: {rev}', ['rev' => $rev]);
        }

        return $sha;
    }

    public function showFile(string $dir, string $rev, string $rel): string
    {
        $dir = $this->resolve($dir);
        if (!is_dir($dir . '/.git')) {
            InvalidArgumentException::raise('Not a git repo: {dir}', ['dir' => $dir]);
        }
        $this->assertSafeRev($rev);

        $spec = $rev . ':' . $rel;
        $cmd = ['git', '-C', $dir, 'cat-file', '-e', $spec];
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptor, $pipes, null, null);
        if (!is_resource($proc)) {
            RuntimeException::raise('Failed to run git');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            FileNotFoundException::raise('File not in rev: {rel} @ {rev}', ['rel' => $rel, 'rev' => $rev]);
        }

        return $this->run($dir, ['show', $spec], true);
    }

    /** Init repo + initial commit (mkbook only). */
    public function init(string $dir, string $message, string $user, string $email): void
    {
        $this->run($dir, ['init', '-b', 'main']);
        $this->run($dir, ['add', '-A']);
        $this->run($dir, [
            '-c', 'user.name=' . $user,
            '-c', 'user.email=' . $email,
            'commit', '-m', $message,
        ]);
    }

    /** Stage all and commit if dirty. Requires existing .git. */
    public function commit(string $dir, string $message, string $user, string $email): void
    {
        $dir = $this->resolve($dir);
        if (!is_dir($dir . '/.git')) {
            InvalidArgumentException::raise('Not a git repo: {dir}', ['dir' => $dir]);
        }
        $this->run($dir, ['add', '-A']);
        $status = $this->run($dir, ['status', '--porcelain'], false);
        if (trim($status) === '') {
            return;
        }
        $this->run($dir, [
            '-c', 'user.name=' . $user,
            '-c', 'user.email=' . $email,
            'commit', '-m', $message,
        ]);
    }

    protected function resolve(string $dir): string
    {
        return str_starts_with($dir, '@') ? $this->pathAlias->resolve($dir) : $dir;
    }
}
