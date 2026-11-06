<?php

namespace Splicewire\Beam\Codegen;

use LogicException;
use Splicewire\Beam\Install\InstalledPackagePaths;

/**
 * The type roots installed packages declare for a host's TypeScript transformer: each package's
 * `extra.typescript-transformer.directories` in its `composer.json`, a map of package-relative directory to a
 * one-line reason no particle declaration reaches it.
 *
 * ```json
 * "extra": { "typescript-transformer": { "directories": { "src/Data": "why no declaration names these" } } }
 * ```
 *
 * - **Install paths** come from the host's `vendor/composer/installed.json` ({@see InstalledPackagePaths}),
 *   absolutized textually, so a co-dev symlink stays at `vendor/<name>`.
 * - **The declaration** is read from each package's live `composer.json` at that path, never from
 *   installed.json's copy of `extra`, so an overlay edit is seen without a `composer update`.
 * - **Order** is by package name, then declared order.
 * - **The host's root package is never read**: a host's own roots are `app_path()` plus its provider's
 *   commented deviations.
 *
 * A declaration fails loudly, naming the package and directory, when the directory is absent, lies outside the
 * package's `autoload.psr-4` roots, escapes the package root, or carries a reason that is not a non-empty
 * string. The psr-4 rule is what keeps content out mechanically: MDX pages live under `resources/` or
 * `content/` and are never autoloaded PHP, so they cannot be declared.
 */
final class PackageTypeRoots
{
    public const EXTRA_KEY = 'typescript-transformer';

    /** @param  array<string, array<string, string>>  $declared  package => [absolute directory => reason] */
    public function __construct(private array $declared) {}

    public static function forHost(string $hostRoot): self
    {
        $host = InstalledPackagePaths::absolutize($hostRoot);
        $declared = [];

        foreach (InstalledPackagePaths::forHost($hostRoot) as $package => $path) {
            if ($path === $host) {
                continue;
            }

            $directories = self::read($package, $path);

            if ($directories !== []) {
                $declared[$package] = $directories;
            }
        }

        return new self($declared);
    }

    /** @return list<string> */
    public function directories(): array
    {
        $directories = [];

        foreach ($this->declared as $roots) {
            array_push($directories, ...array_keys($roots));
        }

        return $directories;
    }

    /** @return array<string, array<string, string>> */
    public function declared(): array
    {
        return $this->declared;
    }

    /** @return array<string, string> absolute directory => reason, in declared order */
    private static function read(string $package, string $path): array
    {
        $composer = is_file($path.'/composer.json')
            ? json_decode((string) file_get_contents($path.'/composer.json'), true)
            : null;

        if (! is_array($composer) || ! isset($composer['extra'][self::EXTRA_KEY])) {
            return [];
        }

        $directories = $composer['extra'][self::EXTRA_KEY]['directories'] ?? null;

        if (! is_array($directories) || array_is_list($directories) && $directories !== []) {
            throw new LogicException("[{$package}] extra.".self::EXTRA_KEY.'.directories must map each package-relative directory to a reason.');
        }

        $psr4 = self::psr4Roots($composer);
        $roots = [];

        foreach ($directories as $directory => $reason) {
            $directory = (string) $directory;
            $where = "[{$package}] extra.".self::EXTRA_KEY.".directories[{$directory}]";

            if (! is_string($reason) || trim($reason) === '') {
                throw new LogicException("{$where} needs a non-empty reason: why no particle declaration reaches it.");
            }

            $relative = self::relative($directory);

            if ($relative === null) {
                throw new LogicException("{$where} escapes the package root.");
            }

            if (! self::insideAny($relative, $psr4)) {
                throw new LogicException("{$where} lies outside the package's autoload.psr-4 roots (".implode(', ', $psr4).').');
            }

            $absolute = $relative === '' ? $path : $path.'/'.$relative;

            if (! is_dir($absolute)) {
                throw new LogicException("{$where} does not exist at {$absolute}.");
            }

            $roots[$absolute] = $reason;
        }

        return $roots;
    }

    /** Normalize a package-relative directory; null when it is absolute or climbs out with `..`. */
    private static function relative(string $directory): ?string
    {
        if (str_starts_with($directory, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @param  array<mixed>  $composer
     * @return list<string>
     */
    private static function psr4Roots(array $composer): array
    {
        $roots = [];

        foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $paths) {
            foreach ((array) $paths as $root) {
                if (is_string($root) && ($normalized = self::relative($root)) !== null) {
                    $roots[] = $normalized;
                }
            }
        }

        return $roots;
    }

    /** @param  list<string>  $roots */
    private static function insideAny(string $directory, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($root === '' || $directory === $root || str_starts_with($directory, $root.'/')) {
                return true;
            }
        }

        return false;
    }
}
