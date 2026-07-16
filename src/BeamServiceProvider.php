<?php

namespace Schemastud\Beam;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The beam substrate provider. beam is the runtime an app stands on with or without an editor —
 * so this provider boots headless: it publishes a config file and the substrate migrations
 * (schema_records + beam_submissions), and nothing that reaches up into the editor rung.
 *
 * The generic model traits (SchemaRecord / PersistsSchemaRecord, the revision trait, media traits)
 * and the host-hook registries land here via the leaf-extraction tickets; this provider is their
 * boot destination.
 *
 * The substrate migrations (schema_records + beam_submissions) are publish-only `.stub` files: a
 * single-tenant host publishes them (`vendor:publish --tag=laravel-beam-migrations`) and a
 * multi-tenant host (splicewire-app) owns tenant-guarded copies in BOTH its central and per-tenant
 * migration sets, so submissions land in the tenant schema rather than falling through to central.
 *
 * Layering law (ADR-0082): frame -> beam, never beam -> frame. Nothing in this package may
 * reference the editor rung.
 */
class BeamServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam')
            ->hasConfigFile('beam')
            ->hasMigration('create_schema_records_table')
            ->hasMigration('create_beam_submissions_table');
    }
}
