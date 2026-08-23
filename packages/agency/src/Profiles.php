<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\Profile;
use Switon\Core\Attribute\Autowired;
use Switon\Yaml\FrontmatterReaderInterface;

use function basename;

/**
 * Default {@see ProfilesInterface}: in-memory profile registry.
 *
 * Guidance: rows arrive from {@see ProfileDiscoveryInterface::discover()} and {@see set()}.
 * This class has no paths of its own. {@see set()} fills omitted `model` and `maxSteps`.
 * Segment refs stay as declared; {@see Charters} resolves a desk's own segment at assembly.
 *
 * @see \Switon\Agency\ProfilesInterface
 * @see \Switon\Agency\ProfileDiscoveryInterface
 */
class Profiles implements ProfilesInterface
{
    #[Autowired] protected FrontmatterReaderInterface $frontmatterReader;

    /** Default when a profile omits {@see Profile::$model}. */
    #[Autowired] protected string $model = 'main';
    /** Default when a profile omits max_steps in frontmatter. */
    #[Autowired] protected int $maxSteps = 10;

    /** @var array<string, Profile> */
    protected array $profiles = [];

    public function set(Profile $profile): void
    {
        if ($profile->model === '') {
            $profile->model = $this->model;
        }
        $profile->maxSteps ??= $this->maxSteps;
        $this->profiles[$profile->id] = $profile;
    }

    public function get(string $id): Profile
    {
        if (!isset($this->profiles[$id])) {
            Exception::raise('Profile "{id}" not found.', ['id' => $id]);
        }

        return $this->profiles[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->profiles[$id]);
    }

    public function all(): array
    {
        return $this->profiles;
    }

    public function load(string $path): ?Profile
    {
        $doc = $this->frontmatterReader->read($path);
        if ($doc === null) {
            return null;
        }

        /** @var array<string, mixed> $meta */
        $meta = $doc->meta;
        $meta['prompt'] = $doc->body;
        if (empty($meta['id'])) {
            $meta['id'] = basename($path, '.md');
        }

        $profile = Profile::decode($meta);
        $profile->path = $path;

        return $profile;
    }
}
