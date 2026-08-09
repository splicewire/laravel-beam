<?php

namespace Splicewire\Beam\Doctor\Support;

use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;

/**
 * The doctor/operator self-check for the estate's publish-only migrations convention: a beam package
 * ships its migrations as timestamp-less `.php.stub` files, registered via spatie/laravel-package-tools'
 * `->hasMigrations([...])`, never auto-loaded at runtime (`loadMigrationsFrom()`). `vendor:publish
 * --tag=<pkg>-migrations` re-stamps + sequences them into the host at install time.
 *
 * A subclass names its own package + {@see PackageServiceProvider} — the
 * "operator" a {@see BeamDoctorManifest} registration renders under. Purely
 * mechanical (source-text + filesystem inspection), zero semantic judgment: fails on a stray
 * `loadMigrationsFrom(` over the package's OWN vendor source, warns when `->hasMigrations(` is absent,
 * fails on a real (non-`.stub`) `.php` migration file sitting alongside the stubs.
 *
 * ONE narrow exception: `loadMigrationsFrom(database_path(...))` is HOST-side glue, not a package
 * auto-loading its own un-published migrations — it registers an ALREADY-published directory (e.g.
 * the estate's `database/migrations/shared/` convention for ubiquitous central+tenant tables) into
 * Laravel's own migration discovery, exactly like any app declaring a custom migrations path. Only
 * `database_path(`-targeted calls are exempt; anything else still fails.
 */
abstract class StubMigrationsAudit implements DoctorAudit
{
    abstract protected function packageName(): string;

    /**
     * @return class-string
     */
    abstract protected function serviceProviderClass(): string;

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $check = "migrations are publish-only stubs ({$this->packageName()})";

        $providerFile = (new ReflectionClass($this->serviceProviderClass()))->getFileName();

        if ($providerFile === false) {
            return [Finding::warn($check, "could not resolve a source file for {$this->serviceProviderClass()}.")];
        }

        $source = file_get_contents($providerFile);

        if ($source === false) {
            return [Finding::warn($check, "could not read source for {$this->serviceProviderClass()}.")];
        }

        foreach ($this->offendingLoadMigrationsFromCalls($source) as $call) {
            return [Finding::fail(
                $check,
                "{$this->serviceProviderClass()} calls loadMigrationsFrom({$call}) on its own package source — ".
                'migrations auto-run at runtime instead of shipping publish-only, per the estate-wide stub '.
                'convention.',
            )];
        }

        if (! str_contains($source, 'hasMigrations(')) {
            return [Finding::warn(
                $check,
                "{$this->serviceProviderClass()} registers no ->hasMigrations([...]) in configurePackage() — ".
                'migrations may not be publish-only stubs (the estate convention: timestamp-less .php.stub, '.
                're-stamped on vendor:publish via package-tools).',
            )];
        }

        $migrationsDir = dirname($providerFile).'/../database/migrations';

        if (is_dir($migrationsDir)) {
            $realPhp = array_merge(
                glob($migrationsDir.'/*.php') ?: [],
                glob($migrationsDir.'/*/*.php') ?: [],
            );

            if ($realPhp !== []) {
                return [Finding::fail(
                    $check,
                    'real (non-.stub) .php migration file(s) found — should be timestamp-less .php.stub, '.
                    're-stamped into the host on publish: '.implode(', ', array_map('basename', $realPhp)),
                )];
            }
        }

        return [Finding::pass(
            $check,
            "{$this->serviceProviderClass()} ships publish-only .stub migrations via ->hasMigrations([...]), ".
            'no loadMigrationsFrom() over its own source.',
        )];
    }

    /**
     * Every `loadMigrationsFrom(...)` call site in $source whose argument does NOT resolve to
     * `database_path(...)` — i.e. every call that isn't the one sanctioned host-glue exception. The
     * argument may be inline (`loadMigrationsFrom(database_path('migrations/shared'))`) or a variable
     * assigned from `database_path(...)` earlier in the same file (the common, more readable shape) —
     * still a mechanical text trace, not semantic evaluation: it only follows a direct
     * `$var = database_path(...)` assignment, nothing more.
     *
     * @return list<string> the raw argument text of each offending call, for the Finding detail
     */
    private function offendingLoadMigrationsFromCalls(string $source): array
    {
        // Require the `->` call syntax so a docblock/comment merely NAMING loadMigrationsFrom() in
        // prose (this class's own docblock does exactly that) never counts as a call site.
        if (! preg_match_all('/->loadMigrationsFrom\(([^)]*)\)/', $source, $matches)) {
            return [];
        }

        return array_values(array_filter(
            $matches[1],
            fn (string $argument): bool => ! $this->resolvesToDatabasePath($argument, $source),
        ));
    }

    private function resolvesToDatabasePath(string $argument, string $source): bool
    {
        if (str_contains($argument, 'database_path(')) {
            return true;
        }

        $variable = trim($argument);

        if (! preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $variable)) {
            return false;
        }

        return (bool) preg_match(
            '/'.preg_quote($variable, '/').'\s*=\s*database_path\(/',
            $source,
        );
    }
}
