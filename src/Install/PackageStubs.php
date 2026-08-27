<?php

namespace Splicewire\Beam\Install;

use Splicewire\Beam\Doctor\PackageStubConflictAudit;

/**
 * Every migration TEMPLATE an installed package ships — the `.php.stub` files a `vendor:publish` would
 * stamp into this host's `database/migrations/` (beam-facade ticket 182).
 *
 * The opposite population to {@see MigrationFiles}, which enumerates what the next `migrate` will RUN.
 * Both are needed and neither substitutes: a host that overrides a package's shape with its own
 * published copy has the stub OUT of `MigrationFiles` forever, which is precisely the blind spot
 * {@see PackageStubConflictAudit} exists to close.
 *
 * ## Provenance comes from `installed.json`, never from walking `vendor/`
 *
 * In this estate `vendor/<vendor>/<pkg>` is routinely a symlink into a co-dev checkout that carries its
 * OWN `vendor/`, so a symlink-following recursive walk does not terminate in useful time — measured at
 * the flagship host, >120s with no result, which is the ecosystem `AGENTS.md` rule *"do not sweep
 * `vendor/`"*. Composer's own record already holds each package's real install path, so the scan is one
 * bounded glob per installed package. The same reasoning
 * `Rushing\Surgeon\Conformance\PublishedMigrationDriftAudit` reached, arrived at independently here
 * because beam does not depend on surgeon and must not learn to.
 *
 * **Depth is two** — `database/migrations/*` and `database/migrations/<dir>/*` — which covers the
 * publish-destination subdirectories the estate actually declares (`shared/`, `tenant/`) without a
 * recursive walk that could descend into a nested `vendor/`.
 *
 * **`.php.stub` only.** A package shipping a pre-stamped `.php` migration publishes it verbatim, and the
 * estate's census for beam-facade ticket 30 found that population empty: 154 `.php.stub` templates and
 * zero undated `.php`. Including `.php` would also re-admit every published copy a co-dev symlink makes
 * reachable, which is the other audit's population.
 *
 * Deduplicated by **realpath**, because a host reaches the same package source twice whenever two
 * vendor entries symlink one checkout, and because `~/Herd/beam` is itself a symlink onto its starter.
 * Path-keyed deduplication has over-counted a family population in this estate before.
 */
class PackageStubs
{
    /**
     * Every stub, ordered by package then file so a re-run with no change produces a byte-identical
     * finding list.
     *
     * @param  string  $hostRoot  the app root holding `vendor/composer/installed.json`
     * @return list<array{package: string, file: string, stem: string}>
     */
    public static function forHost(string $hostRoot): array
    {
        $stubs = [];
        $seen = [];

        foreach (static::installPaths($hostRoot) as $package => $path) {
            $base = rtrim($path, '/').'/database/migrations';

            if (! is_dir($base)) {
                continue;
            }

            $files = array_merge(
                (array) glob($base.'/*.php.stub'),
                (array) glob($base.'/*/*.php.stub'),
            );

            sort($files);

            foreach ($files as $file) {
                $real = realpath((string) $file) ?: (string) $file;

                if (isset($seen[$real])) {
                    continue;
                }

                $seen[$real] = true;

                $stubs[] = [
                    'package' => $package,
                    'file' => $real,
                    'stem' => basename($real, '.php.stub'),
                ];
            }
        }

        return $stubs;
    }

    /**
     * Composer's record of what is installed here and where it really lives, as name => absolute path.
     * Empty when the manifest is absent or unreadable — a package repo running its own testbench has no
     * `vendor/composer/installed.json` describing a HOST, and reporting nothing there is correct.
     *
     * @return array<string, string>
     */
    protected static function installPaths(string $hostRoot): array
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
                ? static::absolutize($vendor.'/composer/'.$package['install-path'])
                : $vendor.'/'.$package['name'];
        }

        ksort($paths);

        return $paths;
    }

    /** Collapse `a/b/../c` textually — `realpath()` is not used because it resolves the co-dev symlink away. */
    protected static function absolutize(string $path): string
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
