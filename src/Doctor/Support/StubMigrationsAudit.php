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
 * `loadMigrationsFrom(`, warns when `->hasMigrations(` is absent, fails on a real (non-`.stub`) `.php`
 * migration file sitting alongside the stubs.
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

        if (str_contains($source, 'loadMigrationsFrom(')) {
            return [Finding::fail(
                $check,
                "{$this->serviceProviderClass()} calls loadMigrationsFrom() — migrations auto-run at runtime ".
                'instead of shipping publish-only, per the estate-wide stub convention.',
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
            'no loadMigrationsFrom().',
        )];
    }
}
