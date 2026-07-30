<?php

namespace Splicewire\Beam;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Rushing\Versioning\Contracts\RecordReconciler;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Events\SchemaRecordPersisted;
use Splicewire\Beam\Http\Middleware\HoneypotMiddleware;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Read\Contracts\RecordHydrator;
use Splicewire\Beam\Read\Contracts\SchemaDataResolver;
use Splicewire\Beam\Read\NullSchemaDataResolver;
use Splicewire\Beam\Read\PayloadRecordReader;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\GateWriteGate;
use Splicewire\Beam\Write\RecordWriter;

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
 * The substrate migrations (beam_particles + beam_versions) are publish-only `.stub` files: a
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
            // Nested config namespace (beam-write-pipeline ticket 07): publishes config/beam/core.php,
            // merged + read as config('beam.core.*'). Never a flat config/beam.php — having both a
            // beam.php file and a beam/ dir is the one real Laravel footgun this move avoids.
            ->hasConfigFile('beam/core')
            ->hasMigration('create_beam_particles_table')
            ->hasMigration('create_beam_versions_table');
    }

    public function packageRegistered(): void
    {
        // Particle vocabulary aliases (beam-particle-rename ticket 01, EXPAND phase): both the legacy
        // `Record`/`SchemaRecord` names and the new `Particle`/`BeamParticle` names resolve, so call sites
        // migrate at their own cadence (T03–T06) before the contract phase (T07) flips canonicality.
        $this->registerParticleAliases();

        // Route beam's two BASE tables through the ONE table-prefix seam (beam-particle-rename T03):
        //
        //  - `beam_particles` — the SchemaRecord model resolves its own table via Beam::table('particles')
        //    (its getTable() override), so nothing to bind here.
        //  - `beam_versions` — the durable version store lives in the versioning FOUNDATION, which holds
        //    no beam opinion and depends on nothing upward. beam depends DOWN on versioning, so beam is
        //    the allowed layer to repoint versioning's config table seam at its prefixed name. The
        //    foundation default stays `versions`; only a beam-composing host gets `beam_versions`.
        $this->app['config']->set('versioning.table', Beam::table('versions'));

        // The durable particle morph alias (beam-particle-rename T03). `beam_versions.versionable_type`
        // stores this token, and SchemaRecord::getMorphClass() returns it — so the eventual class rename
        // (T07) leaves existing version rows resolvable. ADDITIVE (Relation::morphMap), NEVER
        // enforceMorphMap: a beam-composing host (splicewire-app) has many models on class-string morphs,
        // and global enforcement would orphan every one of them. Additive registration is idempotent and
        // composes with whatever map the host already declared.
        Relation::morphMap(['beam_particle' => SchemaRecord::class]);

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

        // The beam write pipeline (ADR-0150 / beam-write-pipeline ticket 03). The DEFAULT WriteGate
        // delegates to the Laravel authorization gate — deny-by-default, existing create/update policies
        // keep governing. A host that wants public intake binds a permissive gate + allow-list instead
        // (ticket 04). The RecordWriter composes the gate, the shipped target resolver, the acceptance
        // gate, and the event dispatcher into the one validate → authorize → persist → emit path.
        $this->app->bind(
            WriteGate::class,
            fn ($app) => new GateWriteGate($app->make(Gate::class)),
        );

        $this->app->bind(RecordWriter::class, fn ($app) => new RecordWriter(
            $app->make(WriteGate::class),
            $app->make(SchemaTargetResolver::class),
            new AcceptanceGate,
            $app->make(Dispatcher::class),
        ));

        // The READ seam mirroring the write pipeline (ticket 13, DESIGN §9). The record → Data-class
        // policy is host-owned (null default here); the DEFAULT hydrator is the degenerate payload reader
        // — NO data-filters dependency. A host that wants query-composing list reads binds its own
        // DataFilterRecordHydrator over the same RecordHydrator port (port-in-base / binding-in-host).
        $this->app->bind(SchemaDataResolver::class, NullSchemaDataResolver::class);
        $this->app->bind(RecordHydrator::class, PayloadRecordReader::class);

        // The beam-install self-registration manifest (ticket 08): a singleton every beam-* package
        // pushes its own install step into, from its own provider. beam-core never learns consumer names.
        $this->app->singleton(BeamInstallManifest::class);
    }

    public function packageBooted(): void
    {
        // Base-tier readiness command. Moat-free (never touches the satellite); the
        // frame/schema-forms/data-schemas checks it runs are advisory + presence-conditional.
        if ($this->app->runningInConsole()) {
            $this->commands([
                BeamDoctorCommand::class,
                BeamInstallCommand::class,
            ]);
        }

        // beam-core registers ITS OWN install step, core-first (order 0), like any consumer — the config
        // + substrate migrations. Consumers self-register their steps from their own providers (ticket 08).
        $this->app->make(BeamInstallManifest::class)->register(
            package: 'splicewire/laravel-beam (core)',
            publishTags: ['laravel-beam-config', 'laravel-beam-migrations'],
            migrates: true,
            order: 0,
        );

        // The OPTIONAL public intake door (ticket 04) — mounted only when the host opts in. Deny-default
        // still guards it (a schema must be allow-listed), so mounting alone opens nothing.
        if (config('beam.core.intake.enabled', false)) {
            $this->registerIntakeRoute();
        }
    }

    /**
     * Lazily alias the new `Particle` vocabulary onto the current `Record`/`SchemaRecord` classes +
     * interfaces (beam-particle-rename ticket 01). Registered as an autoloader so an alias resolves only
     * when first referenced (no eager load of every target). Traits can't be `class_alias`'d, so the
     * trait rename ships as a wrapping trait ({@see PersistsBeamParticle}).
     */
    protected function registerParticleAliases(): void
    {
        $aliases = [
            'Splicewire\Beam\Models\BeamParticle' => SchemaRecord::class,
            'Splicewire\Beam\Write\ParticleWriter' => RecordWriter::class,
            'Splicewire\Beam\Read\Contracts\ParticleHydrator' => RecordHydrator::class,
            'Splicewire\Beam\Read\PayloadParticleReader' => PayloadRecordReader::class,
            'Splicewire\Beam\Events\BeamParticlePersisted' => SchemaRecordPersisted::class,
        ];

        spl_autoload_register(static function (string $class) use ($aliases): void {
            if (isset($aliases[$class])) {
                class_alias($aliases[$class], $class);
            }
        });
    }

    /**
     * Mount POST /beam/intake/{schema} onto {@see PublicIntakeController}, with the configured throttle
     * and — when enabled — the opt-in honeypot. The `{schema}` param is a schema stem/ref, so it may
     * contain slashes.
     */
    protected function registerIntakeRoute(): void
    {
        $middleware = ['throttle:'.config('beam.core.intake.throttle', '5,1')];

        if (config('beam.core.intake.honeypot.enabled', false)) {
            $middleware[] = HoneypotMiddleware::class;
        }

        Route::post('beam/intake/{schema}', PublicIntakeController::class)
            ->where('schema', '.*')
            ->middleware($middleware)
            ->name('beam.intake.submit');
    }
}
