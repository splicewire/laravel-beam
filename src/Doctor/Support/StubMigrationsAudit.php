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
 * `loadMigrationsFrom(` over the package's OWN vendor source, warns when `->hasMigrations(` is absent
 * *while migration files are present*, fails on a real (non-`.stub`) `.php` migration file sitting
 * alongside the stubs.
 *
 * A package that ships NO migrations at all PASSES (beam-facade ticket 77). The convention is about how
 * migrations ship, so a package with none satisfies it vacuously and has nothing for an operator to
 * check. The absence of `->hasMigrations(` on its own is not the defect — the defect is stub files that
 * no registration will ever publish, and only the filesystem can tell those two apart. Before this split
 * both printed the same warn, so the check could not distinguish "nothing to register" from "registered
 * nothing"; splitting them makes the warn strictly more informative, and it fires in exactly the cases
 * that warned before. `splicewire/laravel-beam-notifications` is the family's first package with no
 * migrations of its own — it delegated durability to `rushing/laravel-notification-status` and deleted
 * the outbox stub it had adopted after that delegation was ruled.
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

        // Every predicate below reads CODE, so comments come out first — see withoutComments().
        $source = $this->withoutComments($source);

        foreach ($this->offendingLoadMigrationsFromCalls($source) as $call) {
            return [Finding::fail(
                $check,
                "{$this->serviceProviderClass()} calls loadMigrationsFrom({$call}) on its own package source — ".
                'migrations auto-run at runtime instead of shipping publish-only, per the estate-wide stub '.
                'convention.',
            )];
        }

        $migrationsDir = dirname($providerFile).'/../database/migrations';

        if (! str_contains($source, 'hasMigrations(')) {
            $present = $this->migrationFiles($migrationsDir);

            // Nothing to register is not a defect — see the class docblock. Package-tools registers the
            // `<short-name>-migrations` publish tag per `->hasMigrations([...])` entry, so a package with
            // no migration files has no tag either, and there is no convention left to violate.
            if ($present === []) {
                return [Finding::inconclusive(
                    $check,
                    "{$this->serviceProviderClass()} ships no migrations of its own — the publish-only stub ".
                    'convention has no subject here.',
                )];
            }

            return [Finding::warn(
                $check,
                "{$this->serviceProviderClass()} registers no ->hasMigrations([...]) in configurePackage() but ".
                count($present).' migration file(s) sit in database/migrations — they will never publish: '.
                implode(', ', array_map('basename', $present)),
            )];
        }

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
     * The provider's source with every comment and docblock removed, via `token_get_all()` — still
     * purely mechanical, no semantic judgment.
     *
     * Without this the audit reads PROSE as behaviour: a provider that merely NAMES
     * `->hasMigrations([...])` in a docblock satisfies the registration predicate, so a package could
     * pass by describing a call it does not make. Found the hard way in beam-facade 77 — the one
     * package in the family that ships no migrations could not write down *why* without the comment
     * flipping its own audit green. A check a package can satisfy by talking about itself is worse
     * than no check, because it reads as evidence.
     *
     * Verified non-regressive when this landed: all 17 subclassing packages carry a real
     * `->hasMigrations([` call site, so none was passing on prose alone.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * Every migration-shaped file the package ships — `.php` and `.php.stub`, flat and one directory
     * deep (the `shared/` + `tenant/` convention), matching the depth the real-`.php` check globs at.
     * A non-migration file beside them (a README, a `.gitkeep`) is deliberately not counted: it must not
     * be what turns "ships no migrations" into a warn.
     *
     * @return list<string>
     */
    private function migrationFiles(string $migrationsDir): array
    {
        if (! is_dir($migrationsDir)) {
            return [];
        }

        return array_values(array_merge(
            glob($migrationsDir.'/*.php') ?: [],
            glob($migrationsDir.'/*/*.php') ?: [],
            glob($migrationsDir.'/*.php.stub') ?: [],
            glob($migrationsDir.'/*/*.php.stub') ?: [],
        ));
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
