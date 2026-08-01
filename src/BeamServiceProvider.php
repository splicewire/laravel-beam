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
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Realm\RealmDefinition;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Console\FrameCacheCommand;
use Splicewire\Beam\Console\FrameClearCommand;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Frame\AdminResourceRegistry;
use Splicewire\Beam\Frame\FrameResourceManifest;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\Middleware\HoneypotMiddleware;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Realm\ConfigTenantResolver;
use Splicewire\Beam\Realm\Contracts\TenantResolver;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\GateWriteGate;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The beam-core provider. beam is the schema-driven-CMS core: it composes the open schema
 * foundation (schemastud/laravel-data-schemas) and the open versioning foundation
 * (rushing/laravel-versioning) — depending DOWN on both (ADR-0092's allowed direction;
 * frame → beam → data-schemas is a diamond, not a cycle) — so that a schema-typed,
 * snapshot-versioned, migrate-on-read BeamParticle is what you get out of the box. It still
 * boots headless (frame, the editor rung, depends on beam, never the reverse — ADR-0082) and
 * publishes a config file plus the substrate migrations.
 *
 * The generic model traits (BeamParticle / PersistsBeamParticle, the revision trait, media traits)
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
 * Layering law (ADR-0156 inverted ADR-0082): beam -> frame, never frame -> beam. Beam is the paid
 * CMS engine and MAY reference the agnostic `Schemastud\Frame\*` foundation directly (it owns the
 * resource DECLARATION — `#[AdminResource]` + the registry — and feeds frame's generic manifest
 * machinery through frame's ResourceRegistry port). Frame must never reference `Splicewire\Beam\*`.
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
        // Route beam's two BASE tables through the ONE table-prefix seam (beam-particle-rename T03):
        //
        //  - `beam_particles` — the BeamParticle model resolves its own table via Beam::table('particles')
        //    (its getTable() override), so nothing to bind here.
        //  - `beam_versions` — the durable version store lives in the versioning FOUNDATION, which holds
        //    no beam opinion and depends on nothing upward. beam depends DOWN on versioning, so beam is
        //    the allowed layer to repoint versioning's config table seam at its prefixed name. The
        //    foundation default stays `versions`; only a beam-composing host gets `beam_versions`.
        $this->app['config']->set('versioning.table', Beam::table('versions'));

        // The durable particle morph alias (beam-particle-rename T03). `beam_versions.versionable_type`
        // stores this token, and BeamParticle::getMorphClass() returns it — so the class rename (T07)
        // leaves existing version rows resolvable. ADDITIVE (Relation::morphMap), NEVER
        // enforceMorphMap: a beam-composing host (splicewire-app) has many models on class-string morphs,
        // and global enforcement would orphan every one of them. Additive registration is idempotent and
        // composes with whatever map the host already declared.
        Relation::morphMap(['beam_particle' => BeamParticle::class]);

        // beam-core's DEFAULT schema-migration wiring (ADR-0138): so a headless beam app
        // gets a working migrate-on-read BeamParticle out of the box. A richer host (e.g.
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
        // (ticket 04). The ParticleWriter composes the gate, the shipped target resolver, the acceptance
        // gate, and the event dispatcher into the one validate → authorize → persist → emit path.
        $this->app->bind(
            WriteGate::class,
            fn ($app) => new GateWriteGate($app->make(Gate::class)),
        );

        $this->app->bind(ParticleWriter::class, fn ($app) => new ParticleWriter(
            $app->make(WriteGate::class),
            $app->make(SchemaTargetResolver::class),
            new AcceptanceGate,
            $app->make(Dispatcher::class),
        ));

        // The READ seam mirroring the write pipeline (ticket 13, DESIGN §9). The DEFAULT hydrator is the
        // degenerate payload reader — NO data-filters dependency. It resolves a record → its projection
        // Data class straight off beam's own AdminResourceRegistry (ADR-0156: the SchemaDataResolver port
        // was retired; beam owns the registry, so it reads it directly rather than deferring to a host
        // binding). A host that wants query-composing list reads binds its own DataFilterRecordHydrator
        // over the same ParticleHydrator port (port-in-base / binding-in-host).
        $this->app->bind(ParticleHydrator::class, PayloadParticleReader::class);

        // Beam's resource DECLARATION registry (ADR-0156: "frame has no concept of admin"). It is bound
        // onto frame's agnostic ResourceRegistry port as a SINGLETON so boot-time #[AdminResource] discovery
        // (packageBooted) persists across the request and both names resolve one shared instance. Frame's
        // manifest machinery reads the port; it never imports this beam type (arrow points DOWN, beam → frame).
        $this->app->singleton(AdminResourceRegistry::class, fn () => new AdminResourceRegistry);
        $this->app->alias(AdminResourceRegistry::class, ResourceRegistry::class);

        // The build-time #[AdminResource] manifest cache (mirrors bootstrap/cache/packages.php). When it
        // exists, discoverResources() reads the cached class-strings instead of re-walking the filesystem
        // each boot; `beam:frame-cache` writes it, `beam:frame-clear` / `optimize:clear` remove it.
        $this->app->singleton(FrameResourceManifest::class, fn ($app) => new FrameResourceManifest($app));

        // Tenant resolvability (realm-architecture ticket 08): the re-home of the retired
        // RealmDefinition::$tenancy flag. Default resolves the `tenant` realm when config('frame.tenancy')
        // is on; a satellite / differently-keyed host binds its own resolver.
        $this->app->bind(TenantResolver::class, ConfigTenantResolver::class);

        // The realm registry is a SINGLETON (realm-architecture ticket 08 slice D): its ctor registers the
        // three imperative base realms (admin·tenant·user), and boot-time attributed-realm discovery
        // (packageBooted) augments THAT instance additively — so a resolved registry carries both the base
        // realms and any `#[Realm]`-declared ones. A capability package registering a realm from its own
        // provider mutates the same shared instance.
        $this->app->singleton(RealmRegistry::class, fn () => new RealmRegistry);

        // The generic particle REST surface (promoted from splicewire-app, ADR-0116). The two declaration
        // registries are container singletons so inline `Route::particleResource()` / `Route::particleOp()`
        // declarations survive across the request; the DEFAULT response seam is the neutral
        // {@see ArrayResponseEnvelope} (a plain `{ data: … }` JsonResponse). A richer host BINDS its own
        // envelope adapter over its response DTO — and, when it subclasses the registries for its own
        // container FQN, re-aliases these singletons to the same instance (port-in-base / binding-in-host).
        $this->app->singleton(ParticleResourceRegistry::class);
        $this->app->singleton(ParticleOperationRegistry::class);
        $this->app->bind(ResponseEnvelope::class, ArrayResponseEnvelope::class);

        // The beam-install self-registration manifest (ticket 08): a singleton every beam-* package
        // pushes its own install step into, from its own provider. beam-core never learns consumer names.
        $this->app->singleton(BeamInstallManifest::class);

        // The beam-doctor aggregation manifest (beam-ux-prototype-extract ticket 08): the doctor-side
        // twin of the install manifest — a singleton consumers push their own audits into, so one
        // `beam:doctor` run aggregates the whole family. beam-core's own audits stay hardcoded (coexist).
        $this->app->singleton(BeamDoctorManifest::class);
    }

    public function packageBooted(): void
    {
        // Base-tier readiness command. Moat-free (never touches the satellite); the
        // frame/schema-forms/data-schemas checks it runs are advisory + presence-conditional.
        if ($this->app->runningInConsole()) {
            $this->commands([
                BeamDoctorCommand::class,
                BeamInstallCommand::class,
                FrameCacheCommand::class,
                FrameClearCommand::class,
            ]);

            // Wire the manifest into Laravel's `optimize` / `optimize:clear` so it builds and clears
            // alongside the framework's own caches (the supported ServiceProvider hook).
            $this->optimizes(
                optimize: 'beam:frame-cache',
                clear: 'beam:frame-clear',
                key: 'beam-frame-resources',
            );
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

        // Attributed-realm registration (realm-architecture ticket 08 slice D). Realms are ~4
        // (admin·tenant·user·docs), so a filesystem scan is overkill — register the configured
        // realm-marker classes EXPLICITLY. Each is reflected for its `#[Realm]`-family attribute,
        // projected into a RealmDefinition, and self-registered onto the singleton RealmRegistry —
        // ADDITIVE onto the three imperative base realms (last-wins by key). Vocabulary lives in beam.
        $this->registerRealms();

        // Resource DECLARATION discovery (ADR-0156). Reflect the configured #[AdminResource] classes +
        // scan the configured discover-paths into beam's singleton AdminResourceRegistry, which frame's
        // manifest machinery reads through the ResourceRegistry port. This is the discovery wiring that used
        // to live in frame's FrameServiceProvider — moved here because the #[AdminResource] opinion is beam's.
        $this->discoverResources();
    }

    /**
     * Explicit attributed-realm registration: reflect each configured realm-marker class and register its
     * projected {@see RealmDefinition} onto the singleton {@see RealmRegistry}. No filesystem scan — realms
     * are a small fixed set, so the host lists its marker classes in `beam.core.realms.classes`.
     */
    protected function registerRealms(): void
    {
        $registry = $this->app->make(RealmRegistry::class);

        foreach (config('beam.core.realms.classes', []) as $markerClass) {
            $registry->registerClass($markerClass);
        }
    }

    /**
     * Boot-time #[AdminResource] discovery into beam's singleton {@see AdminResourceRegistry}.
     *
     * The explicit `resources.classes` list is ALWAYS honoured (it is cheap). The discover-path SCAN is
     * where the cost lives, so it is cached: when the {@see FrameResourceManifest} exists (a host ran
     * `beam:frame-cache`), boot registers the cached class-strings directly — no `Finder` walk. Only when
     * the cache is absent (dev) does it fall back to a live scan. Reads `beam.core.resources.classes` /
     * `.discover_paths`, falling back to frame's legacy `frame.resources` / `frame.discover_paths` keys.
     */
    protected function discoverResources(): void
    {
        $registry = $this->app->make(AdminResourceRegistry::class);

        // The explicit list is always registered, cache or no cache.
        $explicit = config('beam.core.resources.classes', config('frame.resources', []));

        foreach ($explicit as $class) {
            $registry->registerClass($class);
        }

        $cached = $this->app->make(FrameResourceManifest::class)->read();

        if ($cached !== null) {
            // Fast path: the cached manifest already resolved the scan — register directly, no filesystem walk.
            foreach ($cached as $class) {
                $registry->registerClass($class);
            }

            return;
        }

        // Dev fallback: no manifest — live-scan the discover-paths.
        foreach ($registry->scanPaths(
            config('beam.core.resources.discover_paths', config('frame.discover_paths', [])),
        ) as $class) {
            $registry->registerClass($class);
        }
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
