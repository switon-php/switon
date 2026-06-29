<?php

declare(strict_types=1);

namespace Switon\Core;

/**
 * Process-level runtime flags and project root detection.
 *
 * Use for environment/bootstrap state shared by the whole process.
 *
 * @see \Switon\Kernel\KernelInterface::start()
 * @see \Switon\Kernel\Kernel::defineConstants()
 * @see \Switon\ComposerExtra\ComposerExtra
 */
class Runtime
{
    /** Whether coroutine mode is enabled. */
    protected static bool $coroutineEnabled = false;

    /** Project root path cache. */
    protected static ?string $root = null;

    /** Enable or disable coroutine mode. */
    public static function setCoroutineEnabled(bool $enabled): void
    {
        self::$coroutineEnabled = $enabled;
    }

    /** Check whether coroutine mode is enabled. */
    public static function isCoroutineEnabled(): bool
    {
        return self::$coroutineEnabled;
    }

    /**
     * Get project root path.
     *
     * Derived from the Composer <code>autoload.php</code> location, which resolves to the
     * directory holding <code>vendor/</code>. Call `setRoot()` when the layout is not standard.
     *
     * @throws Exception\ProjectRootDetectionException
     */
    public static function getRoot(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        $includedFiles = get_included_files();
        foreach ($includedFiles as $file) {
            // Match Composer's own entry point only: `<root>/vendor/autoload.php`.
            if (DIRECTORY_SEPARATOR === '\\') {
                $file = strtr($file, '\\', '/');
            }

            if (str_ends_with($file, '/vendor/autoload.php')) {
                return self::$root = rtrim(strtr(dirname($file, 2), '\\', '/'), '/');
            }
        }

        Exception\ProjectRootDetectionException::raise(
            'Cannot automatically detect project root directory: autoload.php not found in included files. ' .
            'Call Runtime::setRoot() during bootstrap.'
        );
    }

    /** Override project root path manually. */
    public static function setRoot(string $root): void
    {
        self::$root = rtrim(strtr($root, '\\', '/'), '/');
    }
}
