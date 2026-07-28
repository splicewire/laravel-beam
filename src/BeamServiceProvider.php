<?php

namespace Splicewire\Beam;

use Rushing\Versioning\Contracts\RecordReconciler;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;

/**
 * The beam-core provider. beam is the schema-driven-CMS core: it composes the open schema
 * foundation (schemastud/laravel-data-schemas) and the open versioning foundation
 * (rushing/laravel-versioning) — depending DOWN on both (ADR-0092's allowed direction;
 * frame → beam → data-schemas is a diamond, not a cycle) — so that a schema-typed,
 * snapshot-versioned, migrate-on-read SchemaRecord is what you get out of the box. It still
 * boots headless (frame, the editor rung, depends on beam, never the reverse — ADR-0082) and
 * publishes a config file plus the substrate migrations.
 *
 * The generic model traits (SchemaRecord / PersistsSchemaRecord, the revision trait, media traits)
 * and the host-hook registries land here via the leaf-extraction tickets; this provider is their
 * boot destination. The schema-migration adapter (SchemaLadderMigrator) + the SchemaId grammar
 * live under Splicewire\Beam\Schema; the host binds its own SchemaTargetResolver policy behind the
 * beam port.
 *
 * The substrate migrations (schema_records + versions) are publish-only `.stub` files: a
 * single-tenant host publishes them (`vendor:publish --tag=laravel-beam-migrations`) and a
 * multi-tenant host (splicewire-app) owns tenant-guarded copies in BOTH its central and per-tenant
 * migration sets, so records land in the tenant schema rather than falling through to central.
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
            ->hasMigration('create_versions_table');
    }

    public function packageRegistered(): void
    {
        // beam-core's DEFAULT schema-migration wiring (ADR-0138): so a headless beam app
        // gets a working migrate-on-read SchemaRecord out of the box. A richer host (e.g.
        // splicewire-app) OVERRIDES both bindings — its app providers register after the
        // package's, so its system-vs-tenant TargetSchemaResolver + LLM-armed adapter win.

        // The default target resolver: pure registry-latest, no host policy.
        $this->app->bind(
            SchemaTargetResolver::class,
            fn ($app) => new RegistrySchemaTargetResolver($app->make(SchemaRegistry::class)),
        );

        // The default reconciler: the schema-ladder adapter over the container's
        // data-schemas registry + generator, bound to the default target resolver. No
        // TransformRegistry and no LLM factory — a headless beam app's read path is cheap
        // rungs only (a host arms the eager drain).
        $this->app->bind(RecordReconciler::class, fn ($app) => new SchemaLadderMigrator(
            $app->make(SchemaRegistry::class),
            new JsonSchemaGenerator((array) config('data-schemas', [])),
            targetResolver: $app->make(SchemaTargetResolver::class),
        ));
    }

    public function packageBooted(): void
    {
        // Base-tier readiness command. Moat-free (never touches the satellite); the
        // frame/schema-forms/data-schemas checks it runs are advisory + presence-conditional.
        if ($this->app->runningInConsole()) {
            $this->commands([
                BeamDoctorCommand::class,
            ]);
        }
    }
}
