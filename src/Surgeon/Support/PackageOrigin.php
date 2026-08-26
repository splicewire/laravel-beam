<?php

namespace Splicewire\Beam\Surgeon\Support;

/**
 * **Which package a file belongs to, and whether that package is a dev-only dependency** — read off
 * composer's own `installed.json` rather than guessed from the path.
 *
 * ## Why this exists
 * A ratchet artifact is a number two machines compare. `.beam/undeclared-surface.json` could not be
 * compared, for two independent reasons that both live here (beam-facade ticket 140):
 *
 *  1. **The number moved with the environment.** `barryvdh/laravel-debugbar` and `spatie/laravel-ignition`
 *     are `require-dev` at `~/Herd/splicewire-app` and they mount routes, so the count changed with
 *     `--no-dev`, with `APP_ENV`, and with whether a developer had debugbar switched on. A gate whose
 *     number depends on the environment it ran in cannot be compared across two machines, which is the
 *     one thing a ratchet exists to do.
 *  2. **The rows named absolute machine paths.** Every row's `location` was a full path, and because the
 *     estate co-dev-links family packages into `vendor/`, most of them resolved into
 *     `~/Workspaces/php/packages/...` rather than `vendor/`. Committing that produces a file that is a
 *     diff on every machine and carries the author's home directory into the repository.
 *
 * ## Why composer's manifest and not the path
 * The obvious implementation reads `/vendor/<vendor>/<name>/` out of the path. It is wrong here and the
 * estate is the reason: a co-dev overlay symlinks family packages, so `splicewire/tower`'s controllers
 * resolve to `~/Workspaces/php/packages/splicewire/tower/src/...` with no `vendor/` segment anywhere. A
 * path-shaped heuristic attributes the estate's single largest block of undeclared surface — 226 routes
 * at the flagship — to nothing at all, or to a `packages/` guess that happens to work only because of how
 * one machine's directories are named.
 *
 * `vendor/composer/installed.json` answers both questions authoritatively: `packages[].install-path` is
 * where the package ACTUALLY resolved, symlink and all, and `dev-package-names` is the full transitive
 * dev set — not just what the host's `composer.json` names under `require-dev`, which would miss a
 * dev dependency's own dependencies.
 *
 * Longest-prefix wins when matching, because a path-repo install can nest.
 */
class PackageOrigin
{
    public const APP = 'app';

    public const UNKNOWN = 'unknown';

    /** @var array<string, string> realpath of install dir => package name, longest first */
    protected array $roots = [];

    /** @var array<string, true> */
    protected array $devPackages = [];

    protected string $basePath;

    /**
     * @param  array<string, string>  $roots
     * @param  list<string>  $devPackages
     */
    public function __construct(string $basePath, array $roots = [], array $devPackages = [])
    {
        // Everything is realpath'd on the way in, and every lookup realpaths on the way out, so the two
        // sides always agree. Not defensive padding: macOS resolves the temp dir through a symlink
        // (`/var` → `/private/var`), and the estate's whole co-dev overlay is symlinks, so a comparison
        // between an un-resolved root and a resolved file silently matches nothing and reports `unknown`
        // for the entire population — a resolver that answers "I don't know" for everything looks exactly
        // like one that is working.
        $this->basePath = rtrim(realpath($basePath) ?: $basePath, DIRECTORY_SEPARATOR);
        $this->devPackages = array_fill_keys($devPackages, true);

        foreach ($roots as $root => $name) {
            $this->roots[rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR)] = $name;
        }

        // Longest install path first, so a package installed inside another package's tree wins over it.
        uksort($this->roots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * Read composer's manifest for this host.
     *
     * A host with no readable `installed.json` gets an origin resolver that answers `app` for anything
     * under its base path and `unknown` for the rest — degraded, and honest about it, rather than absent.
     * The artifact records how many rows resolved to `unknown` for exactly that reason.
     */
    public static function forBasePath(string $basePath): self
    {
        $manifest = rtrim($basePath, DIRECTORY_SEPARATOR).'/vendor/composer/installed.json';

        if (! is_file($manifest)) {
            return new self($basePath);
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($decoded)) {
            return new self($basePath);
        }

        $roots = [];

        foreach ($decoded['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            $install = $package['install-path'] ?? null;

            if (! is_string($name) || ! is_string($install)) {
                continue;
            }

            // `install-path` is relative to `vendor/composer/`, and for a path repository it is a
            // relative walk out to the linked checkout — realpath is what collapses both.
            $resolved = realpath(dirname($manifest).'/'.$install);

            if ($resolved !== false) {
                $roots[$resolved] = $name;
            }
        }

        return new self($basePath, $roots, array_values(array_filter(
            (array) ($decoded['dev-package-names'] ?? []),
            'is_string',
        )));
    }

    /** The package that ships a file: a composer package name, {@see APP}, or {@see UNKNOWN}. */
    public function packageFor(string $file): string
    {
        $resolved = realpath($file);
        $file = $resolved === false ? $file : $resolved;

        foreach ($this->roots as $root => $name) {
            if (str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                return $name;
            }
        }

        return str_starts_with($file, $this->basePath.DIRECTORY_SEPARATOR) ? self::APP : self::UNKNOWN;
    }

    /**
     * Whether a package is installed only for development.
     *
     * Read from `dev-package-names`, which is the full transitive set composer computed — a host's own
     * `require-dev` block names only the roots of it, so `spatie/laravel-ignition` arriving under
     * `laravel/framework`'s dev tree would read as production from `composer.json` alone.
     */
    public function isDev(string $package): bool
    {
        return isset($this->devPackages[$package]);
    }

    /**
     * A committable form of a path: relative to the host root when inside it, else
     * `<package>/<path within the package>`, else the basename.
     *
     * The package-relative form is what makes a co-dev-linked family package's row identical on a machine
     * that resolved the same package out of `vendor/`. That is the whole point — the row must describe the
     * CODE, not where this machine happens to keep it.
     */
    public function relativize(string $file): string
    {
        $resolved = realpath($file);
        $file = $resolved === false ? $file : $resolved;

        if (str_starts_with($file, $this->basePath.DIRECTORY_SEPARATOR)) {
            $inside = substr($file, strlen($this->basePath) + 1);

            // A vendor path inside the host root still gets the package form, so a package resolved
            // through `vendor/` and the same package resolved through a symlink agree.
            return str_starts_with($inside, 'vendor'.DIRECTORY_SEPARATOR) ? $this->packageRelative($file) ?? $inside : $inside;
        }

        return $this->packageRelative($file) ?? basename($file);
    }

    protected function packageRelative(string $file): ?string
    {
        foreach ($this->roots as $root => $name) {
            if (str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                return $name.'/'.substr($file, strlen($root) + 1);
            }
        }

        return null;
    }
}
