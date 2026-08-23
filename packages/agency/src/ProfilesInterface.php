<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\Profile;

/**
 * Profile registry for agent DI.
 *
 * Guidance: Use `get()` for agent DI; `all()` is rows from {@see ProfileDiscoveryInterface}
 * plus runtime `set()`.
 * Profile `as_tool` is the complete wire id (convention `agent_*`);
 * {@see \Switon\Agency\Toolkit\AsToolToolkit} resolves it lazily at tool exposure.
 *
 * Road-signs:
 * - Discovery: {@see \Switon\Agency\ProfileDiscoveryInterface::discover()}
 * - Execute as-tool: {@see \Switon\Agency\Toolkit\AsToolToolkit::call()}
 * - Advertisement: {@see Profile::$skills}; assembly is {@see \Switon\Agency\Charters}
 *
 * @see \Switon\Agency\Profiles
 * @see \Switon\Agency\Toolkit\AsToolToolkit
 */
interface ProfilesInterface
{
    /**
     * Row stored by {@see set()}. Omitted `model` and `maxSteps` are filled there.
     * Resolved by id via {@see \Switon\Agency\Agent::switchProfile()}.
     */
    public function get(string $id): Profile;

    /**
     * Set a runtime profile under its id — last write wins, same resolution rules as a
     * disk row. Process-local: not persisted, so cross-process resume requires a disk id.
     */
    public function set(Profile $profile): void;

    /**
     * Load one profile markdown file; null when it has no readable frontmatter.
     *
     * Guidance: a profile is a file unit. `$path` is that `.md` file. An omitted `id` is the
     * file name. The returned row carries the file back as {@see Profile::$path}. Defaults are
     * applied when the row is {@see set()}.
     */
    public function load(string $path): ?Profile;

    public function has(string $id): bool;

    /**
     * @return array<string, Profile>
     */
    public function all(): array;
}
