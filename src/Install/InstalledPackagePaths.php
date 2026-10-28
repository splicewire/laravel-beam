<?php

namespace Splicewire\Beam\Install;

use Splicewire\Beam\Codegen\PackageTypeRoots;

/**
 * Composer's record of what a host has installed and where each package lives, read from
 * `vendor/composer/installed.json` rather than by walking `vendor/`.
 *
 * Extracted from {@see PackageStubs} so {@see PackageTypeRoots} reads install paths the same way. Paths are
 * absolutized textually and never through `realpath()`, so a co-dev symlink stays at `vendor/<name>`.
 */
final class InstalledPackagePaths
{
    /**
     * Name => absolute install path, ordered by name. Empty when the manifest is absent or unreadable: a
     * package repo running its own testbench has no `vendor/composer/installed.json` describing a HOST, and
     * reporting nothing there is correct.
     *
     * @param  string  $hostRoot  the app root holding `vendor/composer/installed.json`
     * @return array<string, string>
     */
    public static function forHost(string $hostRoot): array
    {
        $vendor = rtrim($hostRoot, '/').'/vendor';
        $file = $vendor.'/composer/installed.json';

        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        if (! is_array($decoded)) {
            return [];
        }

        $raw = $decoded['packages'] ?? $decoded; // composer v2 wraps in "packages"; v1 is a bare list.

        if (! is_array($raw)) {
            return [];
        }

        $paths = [];

        foreach ($raw as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            // composer v2 records install-path relative to vendor/composer/; v1 has none.
            $paths[$package['name']] = is_string($package['install-path'] ?? null)
                ? self::absolutize($vendor.'/composer/'.$package['install-path'])
                : $vendor.'/'.$package['name'];
        }

        ksort($paths);

        return $paths;
    }

    /** Collapse `a/b/../c` textually — `realpath()` is not used because it resolves the co-dev symlink away. */
    public static function absolutize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}
