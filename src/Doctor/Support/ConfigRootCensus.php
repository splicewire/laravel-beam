<?php

namespace Splicewire\Beam\Doctor\Support;

use Composer\InstalledVersions;
use Splicewire\Beam\Config\ConfigSectionRegistry;

/**
 * The census of declared config roots and who owns each (config-sections ticket 13, Part 3, taken report-only under
 * coord clamp (a), LANE-B-2330; population corrected under LANE-B-2370).
 *
 * **Scope and unit.** The unit is ONE config root. The scope is the whole install — EVERY installed package plus the
 * host root — because a root that is unowned by design can belong to a third-party package (Spatie, Horizon, Sanctum)
 * or to the host's own `config/`, and a census that only saw the beam family would silently omit exactly those roots.
 * This is deliberately WIDER than env-prefix discovery ({@see EnvPrefixCensus::installedPackages()}, which stays scoped
 * to {@see FacadeConformanceScope::FAMILY_VENDORS}); the two scopes are kept separate on purpose.
 *
 * **Two probes**, as the ticket requires:
 *
 * - the REGISTRATIONS — {@see ConfigSectionRegistry}, which holds one entry per declared root with its owner (a composer
 *   name, or `host`) and the package file a provider's `mergeConfigFrom()` merged;
 * - the config/ FILE TREE — every `config/<path>.php` an installed package (or the host root) ships, its root read off
 *   the path (`/` → `.`, so `config/beam/core.php` → `beam.core`), the shape a root takes before any section declares it.
 *
 * A root a registration owns is classified from the registry (source `registry`); a root only the file tree found is
 * classified from the package whose tree holds it (source `config-tree`). The owner's FAMILY — whether a Config Section
 * is expected of it — is decided from the owning package's DECLARED REQUIRES, read from Composer's `installed.json`, not
 * from its vendor prefix or an exact-name list: the host and framework/app-native roots are `host`; a package whose
 * requires reach `splicewire/laravel-beam` (transitively), and beam itself, are `beam`; a family-vendor package that
 * does NOT require beam is `beam-free` (it ships config but does not sit on the beam ladder — the sitemap/mdx/provision
 * shape); any other vendor is `third-party`. A root whose family is `beam-free` or `third-party` and that no section
 * declares is **unowned by design**: ticket 13's coverage gate must pass it, never fail it. This census only classifies
 * and lists; it gates nothing (clamp (a)).
 */
class ConfigRootCensus
{
    /** The core package whose (transitive) requirers sit on the beam config ladder. */
    private const BEAM = 'splicewire/laravel-beam';

    /**
     * The Laravel framework package. Its config roots (app, auth, cache, database, logging, mail, queue, session, …) are
     * the host's own framework config — published into the host `config/` tree — so they are `host`, not a third-party
     * dependency (coord ruling: "framework and app-native roots are host"). This is the ONE package named by identity:
     * "the framework" cannot be derived from a requires relation, and it is a single definitional anchor, not a
     * maintained allow-list of packages (which is the mechanism LANE-B-2365 rejected for beam-free).
     */
    private const FRAMEWORK = 'laravel/framework';

    /**
     * The installed packages this census scans, each `{name, path, requires, isRoot}`: `null` reads the whole install
     * from Composer's `installed.json` plus the host root. A test passes fixture entries to drive classification through
     * the same code the production read feeds.
     *
     * @var list<array{name: string, path: string, requires?: list<string>, isRoot?: bool}>|null
     */
    private ?array $installed;

    /** @var array<string, true>|null memoised set of package names on the beam ladder (requires beam, transitively). */
    private ?array $ladder = null;

    /**
     * @param  list<array{name: string, path: string, requires?: list<string>, isRoot?: bool}>|null  $installed  null
     *         reads the production install ({@see self::readInstalled()}); a test passes fixture entries.
     */
    public function __construct(
        private ConfigSectionRegistry $registry,
        ?array $installed = null,
    ) {
        $this->installed = $installed;
    }

    /**
     * Every census root, keyed by root, each carrying its owner, the probe that decided the owner, the owner's family,
     * and whether a section declares it. Sorted by root.
     *
     * @return array<string, array{root: string, owner: string, source: string, family: string, declared: bool}>
     */
    public function roots(): array
    {
        $census = [];

        // Probe 1 — the registrations. Authoritative for a declared root: the registry carries the owner.
        foreach ($this->registry->all() as $registered) {
            $owner = $registered->owner;
            $census[$registered->root()] = [
                'root' => $registered->root(),
                'owner' => $owner,
                'source' => 'registry',
                'family' => $this->familyOf($owner),
                'declared' => true,
            ];
        }

        // Probe 2 — the config/ file tree of every installed package, then the host root LAST (so a root a vendor
        // package ships is attributed to that vendor, and only a root the host alone holds is attributed to host). A
        // root a registration already owns stays as probe 1 decided; a root only a tree found is owned by the package
        // whose tree holds it, and no section declares it yet.
        foreach ($this->installed() as $entry) {
            $owner = ($entry['isRoot'] ?? false) ? 'host' : $entry['name'];

            foreach (self::rootsInTree($entry['path']) as $configRoot) {
                if (isset($census[$configRoot])) {
                    continue;
                }

                $census[$configRoot] = [
                    'root' => $configRoot,
                    'owner' => $owner,
                    'source' => 'config-tree',
                    'family' => $this->familyOf($owner),
                    'declared' => false,
                ];
            }
        }

        ksort($census);

        return $census;
    }

    /**
     * The census roots that are unowned by design: no section declares them, and their owner's family is one a Config
     * Section is not expected of (`beam-free` or `third-party`). Ticket 13's gate passes these; here they are listed.
     *
     * @return array<string, array{root: string, owner: string, source: string, family: string, declared: bool}>
     */
    public function unownedByDesign(): array
    {
        return array_filter(
            $this->roots(),
            static fn (array $entry): bool => ! $entry['declared'] && in_array($entry['family'], ['beam-free', 'third-party'], true),
        );
    }

    /**
     * The owner's family, decided from the owning package's declared requires (not its vendor prefix or a name list):
     * `host` for the host and framework/app-native roots; `beam` for beam itself and any package whose requires reach
     * beam transitively; `beam-free` for a family-vendor package that does not require beam; `third-party` otherwise.
     */
    public function familyOf(string $owner): string
    {
        if ($owner === 'host' || $owner === self::FRAMEWORK) {
            return 'host';
        }

        if (isset($this->ladder()[$owner])) {
            return 'beam';
        }

        $vendor = strstr($owner, '/', true);

        if ($vendor !== false && in_array($vendor, FacadeConformanceScope::FAMILY_VENDORS, true)) {
            return 'beam-free';
        }

        return 'third-party';
    }

    /**
     * The set of package names on the beam ladder: {@see self::BEAM} itself and every installed package whose declared
     * requires reach it transitively. The host root never sits on the ladder (it is always `host`).
     *
     * @return array<string, true>
     */
    private function ladder(): array
    {
        if ($this->ladder !== null) {
            return $this->ladder;
        }

        $requires = [];

        foreach ($this->installed() as $entry) {
            if ($entry['isRoot'] ?? false) {
                continue;
            }

            $requires[$entry['name']] = $entry['requires'] ?? [];
        }

        $ladder = [self::BEAM => true];

        do {
            $changed = false;

            foreach ($requires as $name => $deps) {
                if (isset($ladder[$name])) {
                    continue;
                }

                foreach ($deps as $dep) {
                    if (isset($ladder[$dep])) {
                        $ladder[$name] = true;
                        $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        return $this->ladder = $ladder;
    }

    /**
     * @return list<array{name: string, path: string, requires?: list<string>, isRoot?: bool}>
     */
    private function installed(): array
    {
        return $this->installed ??= self::readInstalled();
    }

    /**
     * The whole install from Composer's `installed.json` (name, absolute path, package-only requires) plus the host
     * root appended LAST. `installed.json` is read beside the loaded `Composer\InstalledVersions`, so the same read
     * serves a host install and a package checkout; its `require` map is the authoritative source for the beam ladder
     * (the runtime {@see InstalledVersions} API does not expose requires).
     *
     * @return list<array{name: string, path: string, requires: list<string>, isRoot: bool}>
     */
    private static function readInstalled(): array
    {
        $entries = [];

        $file = dirname((new \ReflectionClass(InstalledVersions::class))->getFileName()).'/installed.json';

        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            $vendorComposer = dirname($file);

            foreach ((is_array($data) ? ($data['packages'] ?? []) : []) as $package) {
                $name = $package['name'] ?? null;
                $installPath = $package['install-path'] ?? null;

                if (! is_string($name) || ! is_string($installPath)) {
                    continue;
                }

                $path = realpath($vendorComposer.'/'.$installPath);

                if ($path === false || ! is_dir($path)) {
                    continue;
                }

                $entries[] = [
                    'name' => $name,
                    'path' => $path,
                    'requires' => array_values(array_filter(
                        array_keys(is_array($package['require'] ?? null) ? $package['require'] : []),
                        static fn (string $dep): bool => str_contains($dep, '/'),
                    )),
                    'isRoot' => false,
                ];
            }
        }

        $root = InstalledVersions::getRootPackage();
        $rootPath = isset($root['install_path']) && is_string($root['install_path']) ? realpath($root['install_path']) : false;

        if ($rootPath !== false && is_dir($rootPath)) {
            $entries[] = [
                'name' => is_string($root['name'] ?? null) ? $root['name'] : 'host',
                'path' => $rootPath,
                'requires' => [],
                'isRoot' => true,
            ];
        }

        return $entries;
    }

    /**
     * The config roots a package's `config/` tree declares, by the path convention (`config/beam/core.php` →
     * `beam.core`), sorted and distinct.
     *
     * @return list<string>
     */
    private static function rootsInTree(string $packageRoot): array
    {
        $dir = $packageRoot.'/config';

        if (! is_dir($dir)) {
            return [];
        }

        $roots = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if (! $entry->isFile() || ! str_ends_with($entry->getFilename(), '.php')) {
                continue;
            }

            $relative = ltrim(substr($entry->getPathname(), strlen($dir)), '/');
            $roots[preg_replace('/\.php$/', '', str_replace('/', '.', $relative))] = true;
        }

        $roots = array_keys($roots);
        sort($roots);

        return $roots;
    }
}
