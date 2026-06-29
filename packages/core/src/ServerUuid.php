<?php

declare(strict_types=1);

namespace Switon\Core;

use Switon\Core\Attribute\Autowired;

use function trim;

/**
 * Persists one UUID per runtime directory (`@runtime/server-uuid`).
 *
 * Cloning or copying the runtime directory copies the id; delete the file to mint a new one.
 *
 * @see \Switon\Core\ServerUuidInterface
 */
class ServerUuid implements ServerUuidInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;

    #[Autowired] protected string $path = '@runtime/server-uuid';

    protected ?string $id = null;

    /**
     * {@inheritDoc}
     */
    public function get(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        if ($this->filesystem->exists($this->path)) {
            $id = trim($this->filesystem->read($this->path));
            if ($id !== '') {
                return $this->id = $id;
            }
        }

        $id = Strings::uuid();
        $this->filesystem->write($this->path, $id . "\n");

        return $this->id = $id;
    }
}
