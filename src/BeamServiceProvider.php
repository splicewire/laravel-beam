<?php

namespace Schemastud\Beam;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The beam substrate provider. beam is the runtime an app stands on with or
 * without an editor — so this provider boots headless: it publishes a config
 * file and nothing else yet. The generic model traits (SchemaRecord /
 * PersistsSchemaRecord, media traits) and the host-hook registries
 * (webhook / sitemap / doctor) land here via the leaf-extraction tickets
 * (07-10); this skeleton is their destination, not their mover.
 *
 * Layering law (ADR-0082): frame -> beam, never beam -> frame. Nothing in this
 * package may reference the editor rung.
 */
class BeamServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam')
            ->hasConfigFile('beam');
    }
}
