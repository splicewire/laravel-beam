<?php

namespace Splicewire\Beam\Doctor\Support;

use Composer\InstalledVersions;
use Splicewire\Beam\Config\ConfigEnvBinding;
use Splicewire\Beam\Config\ConfigFileScanner;

/**
 * The `env()` calls in family packages' config, and the naming rule they are held to (env-inventory 02; the
 * [env entries] convention, ecosystem `docs/conventions/env-entries.md`). Shared by {@see \Splicewire\Beam\Doctor\EnvPrefixAudit},
 * which reads, and {@see \Splicewire\Beam\Console\EnvPrefixBaselineCommand}, which writes the baseline.
 *
 * - **The rule.** A package's config file `config/<path>.php` owns the prefix `<PATH>_`: the path uppercased, with `/`,
 *   `-` and `.` as `_` (`config/beam/ux.php` → `BEAM_UX_`, `config/popcorn-bubble.php` → `POPCORN_BUBBLE_`). Each name
 *   an `env()` call in that file reads starts with that prefix, or is a third-party name on {@see THIRD_PARTY}, or is in
 *   the package's baseline.
 * - **The population.** Every installed package of a family vendor ({@see FacadeConformanceScope::FAMILY_VENDORS}),
 *   from Composer's runtime record, so the same code reads a package (the root package of a package checkout, its
 *   family dependencies beside it) and a host (every family package it installed, overlay links and pinned copies
 *   alike). A host's own config is out of scope (the ticket): the root package is skipped when it is an application
 *   (it has `artisan`).
 * - **The walk** is {@see ConfigFileScanner::envReads()}, ADR-0245's syntax-tree walk, so there is no second scanner.
 */
class EnvPrefixCensus
{
    /** The baseline file at a package root: the package's grandfathered names, one per line. */
    public const BASELINE = 'env-prefix-baseline.txt';

    /**
     * Names a third party owns, which keep that party's spelling (the convention's rule 5). The declared list: the
     * external names the 2026-11-13 census found in family package config, and nothing broader. A new one joins here,
     * in review, never by a prefix wildcard.
     */
    public const THIRD_PARTY = [
        'APP_DOMAIN', 'APP_URL',
        'COHERE_RERANK_MODEL', 'FAL_VIDEO_MODEL', 'VOYAGEAI_RERANK_MODEL',
        'GCP_ALLOW_UNAUTHENTICATED', 'GCP_BEAM_PROVISION_SERVICE_ACCOUNT', 'GCP_CLOUD_SQL_AUTHORIZED_NETWORKS', 'GCP_NETWORK',
        'GCP_PROJECT', 'GCP_REGION', 'GCP_SUBNETWORK', 'GCP_WORKLOAD_IDENTITY_PROVIDER',
        'GOOGLE_ANALYTICS_ID',
        'LARAVEL_CLOUD_API_TOKEN', 'LARAVEL_CLOUD_CLI_BIN', 'LARAVEL_CLOUD_DB_REGION', 'LARAVEL_CLOUD_DB_TYPE',
        'PG_DUMP_BIN', 'PSQL_BIN',
        'SCALAR_API_KEY',
        'STRIPE_SECRET', 'STRIPE_WEBHOOK_PATH', 'STRIPE_WEBHOOK_SECRET',
    ];

    /**
     * The installed family packages: composer name => absolute root (realpath), sorted by name.
     *
     * @return array<string, string>
     */
    public static function installedPackages(): array
    {
        $root = InstalledVersions::getRootPackage()['name'];
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            if (! in_array(strstr($name, '/', true), FacadeConformanceScope::FAMILY_VENDORS, true)) {
                continue;
            }

            $path = InstalledVersions::getInstallPath($name);
            $path = $path === null ? false : realpath($path);

            if ($path === false || ! is_dir($path) || ($name === $root && is_file($path.'/artisan'))) {
                continue;
            }

            $packages[$name] = $path;
        }

        ksort($packages);

        return $packages;
    }

    /** The prefix a config file owns, from its path below `config/` (`beam/ux.php` → `BEAM_UX_`). */
    public static function prefixOf(string $configRelative): string
    {
        $path = preg_replace('/\.php$/', '', str_replace('\\', '/', $configRelative)) ?? $configRelative;

        return strtoupper((string) preg_replace('/[\/\-.]+/', '_', $path)).'_';
    }

    /**
     * Every `env()` call in one package's `config/**\/*.php`, with the prefix its file owns. `prefixes` are the prefixes
     * all its config files own (a file without an `env()` call owns one too); `dynamic` counts calls with a computed
     * name; `unreadable` lists files that did not parse.
     *
     * @return array{reads: list<array{package: string, file: string, prefix: string, binding: ConfigEnvBinding}>, prefixes: list<string>, files: int, dynamic: int, unreadable: list<string>}
     */
    public static function readsOf(string $package, string $root): array
    {
        $scanner = new ConfigFileScanner;
        $reads = [];
        $dynamic = 0;
        $unreadable = [];
        $files = self::configFiles($root);

        foreach ($files as $relative) {
            $scan = $scanner->envReads($root.'/config/'.$relative);

            if ($scan === null) {
                $unreadable[] = "config/{$relative}";

                continue;
            }

            $dynamic += $scan['dynamic'];

            foreach ($scan['reads'] as $binding) {
                $reads[] = ['package' => $package, 'file' => "config/{$relative}", 'prefix' => self::prefixOf($relative), 'binding' => $binding];
            }
        }

        return ['reads' => $reads, 'prefixes' => array_values(array_unique(array_map(self::prefixOf(...), $files))), 'files' => count($files), 'dynamic' => $dynamic, 'unreadable' => $unreadable];
    }

    /** Whether a read follows the rule without the baseline: its file's prefix, or a declared third-party name. */
    public static function conforms(string $name, string $prefix): bool
    {
        return str_starts_with($name, $prefix) || in_array($name, self::THIRD_PARTY, true);
    }

    /**
     * The names in a package's committed baseline, or null when it has none. Blank lines and `#` comments are skipped.
     *
     * @return list<string>|null
     */
    public static function baseline(string $root): ?array
    {
        $file = $root.'/'.self::BASELINE;

        if (! is_file($file)) {
            return null;
        }

        $names = [];

        foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                $names[] = $line;
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> paths below `config/`, sorted */
    private static function configFiles(string $root): array
    {
        $dir = $root.'/config';

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
                $files[] = ltrim(substr($entry->getPathname(), strlen($dir)), '/');
            }
        }

        sort($files);

        return $files;
    }
}
