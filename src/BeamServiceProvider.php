<?php

namespace Splicewire\Beam;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rushing\Versioning\Contracts\RecordReconciler;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\Frame\Contracts\FrameFilterProvider;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Realm\RealmDefinition;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Console\FrameCacheCommand;
use Splicewire\Beam\Console\FrameClearCommand;
use Splicewire\Beam\Console\GenerateAssetsCommand;
use Splicewire\Beam\Console\GenerateClientSdkCommand;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Frame\AdminResourceRegistry;
use Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver;
use Splicewire\Beam\Frame\FrameResourceManifest;
use Splicewire\Beam\Frame\NullFrameFilterProvider;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\Middleware\HoneypotMiddleware;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ownership\Contracts\OwnershipEdgeStore;
use Splicewire\Beam\Ownership\EloquentOwnershipEdgeStore;
use Splicewire\Beam\Ownership\OwnershipGraph;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\ParticleOperation;
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
use Splicewire\Beam\Source\Contracts\ForeignSourceProjector;
use Splicewire\Beam\Source\LadderForeignSourceProjector;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Source\ParticleShadower;
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
 * The generic model traits (BeamParticle / PersistsBeamParticle, the revision trait) and the
 * host-hook registries land here via the leaf-extraction tickets; this provider is their
 * boot destination. The schema-migration adapter (SchemaLadderMigrator) + the SchemaId grammar
 * live under Splicewire\Beam\Schema; the host binds its own SchemaTargetResolver policy behind the
 * beam port.
 *
 * The substrate migrations (beam_particles, beam_versions, beam_ownership_edges, beam_submissions)
 * are PUBLISH-ONLY. `runsMigrations` stays FALSE, so beam-core never loads them at runtime;
 * `vendor:publish --tag=beam-migrations` (or `splicewire:beam:install`) drops copies into the HOST,
 * which runs them. Each table is ubiquitous (central + every tenant), so it ships as two
 * real-timestamped files — a flat one (publishes to database/migrations/, central pass) and a
 * `tenant/` twin (publishes to database/migrations/tenant/, Stancl tenant pass).
 *
 * beam-core STAYS a spatie/laravel-package-tools PackageServiceProvider (for its config file etc.),
 * but migrations are NOT registered via package-tools' `->hasMigrations([...])` — that re-stamps each
 * file to now on publish, which drifts the natural dev-sequence dates and breaks uniformity with the
 * estate's other eight packages. Instead they ship as real-timestamped `.php` and publish VERBATIM via
 * Laravel's native `ServiceProvider::publishesMigrations()` (see {@see self::bootMigrations()}), so the
 * natural timestamps survive — the one keep-natural pattern all nine packages now share.
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
            // `beam/core.php` → `beam.core.*`; `beam/client.php` → `beam.client.*` (the promoted client-SDK
            // codegen's config: out_dir, emit_stores, the http-client/route module specifiers, sources).
            ->hasConfigFile(['beam/core', 'beam/client']);

        // Migrations are DELIBERATELY not registered via package-tools' `->hasMigrations([...])`: that
        // re-stamps each file to now on publish (breaking the natural dev-sequence dates + estate
        // uniformity). beam-core keeps its natural timestamps by publishing verbatim through Laravel's
        // native `publishesMigrations` instead — see {@see self::bootMigrations()}. `runsMigrations`
        // stays FALSE (the package never loads them at runtime).
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

        // The source projection seam (sourced-particles ticket 06): the default projector maps a foreign
        // payload onto a local target schema through ticket-04's ladder entry (the "ladder IS the wheel"
        // verdict). No TransformRegistry by default — a headless beam app has no custom-transform rung;
        // a host arms one by binding its own projector. Unschematized target → throws (ephemeral-only floor).
        $this->app->bind(ForeignSourceProjector::class, fn () => new LadderForeignSourceProjector);

        // The two-intensity shadow writer (ticket 06): projects → stamps provenance/tier → writes through
        // the ParticleWriter. It writes under a SourceGrant + SourceWriteGate at the call site (the host
        // passes the grant), so it composes the DEFAULT ParticleWriter here; only the tier chooses light vs
        // full intensity. A source can never write the local tier (guarded on the grant).
        $this->app->bind(ParticleShadower::class, fn ($app) => new ParticleShadower(
            $app->make(ParticleWriter::class),
            $app->make(SchemaTargetResolver::class),
            $app->make(ForeignSourceProjector::class),
        ));

        // The ownership / GC graph (ticket 08, ADR-0161 Position 3). The edge-store PORT binds to the
        // shipping Eloquent store whose cascade is a LIVE recursive CTE (NOT graphine's snapshot driver —
        // documented in OwnershipGraph). beam-core home: beam→graphine is legal and the graph is about
        // particles, but the cascade takes NO graphine dep (live CTE over beam's own table), so nothing is
        // added to beam's composer. NOT audit-lineage — the Lineage log (tower-core) is untouched.
        $this->app->bind(OwnershipEdgeStore::class, EloquentOwnershipEdgeStore::class);
        $this->app->bind(OwnershipGraph::class, fn ($app) => new OwnershipGraph(
            $app->make(OwnershipEdgeStore::class),
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
        // each boot; `splicewire:beam:frame-cache` writes it, `splicewire:beam:frame-clear` / `optimize:clear` remove it.
        $this->app->singleton(FrameResourceManifest::class, fn ($app) => new FrameResourceManifest($app));

        // Frame's two host-facing seams, promoted OOTB (beam-ux-uplift ticket 09) so a fresh host gets a
        // working operator area with NO `app/Frame/` glue — retiring the host-local
        // BeamFrameResourceHandlerResolver + NullFrameFilterProvider whose docblocks said "neither
        // laravel-frame nor laravel-beam binds this — the host does." Beam already ships the singular
        // ParticleFrameResourceHandler (ADR-0156), so the default resolver is a constant map: every
        // registered particle key → that one handler. The default filter provider answers an empty facet
        // schema (a particle resource declaring no data-filters `query` has no facets), so the ListShell's
        // filter-schema/filter-options socket mounts without erroring.
        //
        // Both are OVERRIDABLE by the host: an app provider registers AFTER beam-core's, so a host that
        // binds its own FrameResourceHandlerResolver (bespoke handlers per key) or a real FrameFilterProvider
        // (faceted lists) wins — the same override mechanism as the schema-migration defaults above.
        $this->app->bind(
            FrameResourceHandlerResolver::class,
            DefaultParticleResourceHandlerResolver::class,
        );
        $this->app->bind(FrameFilterProvider::class, NullFrameFilterProvider::class);

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
        // registries are container singletons so the `Route::particleResource()` / `Route::particleOp()`
        // route macros (defined in packageBooted below) survive across the request; the DEFAULT response
        // seam is the neutral {@see ArrayResponseEnvelope} (a plain `{ data: … }` JsonResponse). A richer
        // host BINDS its own envelope adapter over its response DTO — and, when it subclasses the registries
        // for its own container FQN, re-aliases these singletons to the same instance (port-in-base /
        // binding-in-host).
        $this->app->singleton(ParticleResourceRegistry::class);
        $this->app->singleton(ParticleOperationRegistry::class);
        $this->app->bind(ResponseEnvelope::class, ArrayResponseEnvelope::class);

        // The client-SDK codegen's default tenant source: reads mounted particle routes off the live route
        // table (the `router` singleton) + resolves each read route's output DTO via the resource registry.
        $this->app->bind(ParticleRouteManifestSource::class, fn ($app) => new ParticleRouteManifestSource(
            $app->make('router'),
            $app->make(ParticleResourceRegistry::class),
        ));

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
        // The `Route::particleResource()` / `Route::particleOp()` route macros — the declarative mount for
        // the generic particle REST + operation surface, so a host stops hand-mounting the generic
        // controllers against the RESOURCE/NAME route defaults.
        $this->bootParticleRouteMacros();

        // Base-tier readiness command. Moat-free (never touches the satellite); the
        // frame/schema-forms/data-schemas checks it runs are advisory + presence-conditional.
        if ($this->app->runningInConsole()) {
            // The publish-only substrate migrations, via native `publishesMigrations` (keep-natural
            // timestamps — the estate-wide pattern). Console-only, like every publish.
            $this->bootMigrations();

            $this->commands([
                BeamDoctorCommand::class,
                BeamInstallCommand::class,
                FrameCacheCommand::class,
                FrameClearCommand::class,
                // The client-SDK codegen (promoted from the platform app) + its umbrella. Source-agnostic:
                // a host binds a RouteManifestSource per realm into `beam.client.sources`. The default
                // tenant binding is the particle-route source (below), so a fresh satellite generates from
                // its mounted `#[ParticleResource]` routes with no further wiring.
                GenerateClientSdkCommand::class,
                GenerateAssetsCommand::class,
            ]);

            // Wire the manifest into Laravel's `optimize` / `optimize:clear` so it builds and clears
            // alongside the framework's own caches (the supported ServiceProvider hook).
            $this->optimizes(
                optimize: 'splicewire:beam:frame-cache',
                clear: 'splicewire:beam:frame-clear',
                key: 'beam-frame-resources',
            );
        }

        // beam-core registers ITS OWN install step, core-first (order 0), like any consumer — the config
        // AND the publish-only substrate migrations. Consumers self-register their steps from their own
        // providers (ticket 08). `beam-config` is package-tools' auto-generated config group (shortName
        // strips `laravel-` → `beam`); `beam-migrations` is the tag beam-core declares on its native
        // `publishesMigrations` call ({@see self::bootMigrations()}) — same tag as before, now fed by
        // native publishesMigrations rather than package-tools' hasMigrations, so the copies land VERBATIM
        // (flat → database/migrations/, tenant/ → database/migrations/tenant/) with their natural dates
        // intact. `migrates: true` then runs a single `migrate` at the end so the fresh copies apply.
        $this->app->make(BeamInstallManifest::class)->register(
            package: 'splicewire/laravel-beam (core)',
            publishTags: ['beam-config', 'beam-migrations'],
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

        // Attributed REST/op discovery (ADR-0116/0160): the runtime twin of #[AdminResource] discovery.
        // Reflect the configured #[ParticleResource] / #[ParticleOp] Data classes (+ discover-paths) into
        // the two particle registries, so a host declares a REST resource / named op ON its Data class
        // instead of hand-registering it from a provider. Closures the attribute can't carry are resolved
        // from `public static` convention methods on the annotated class.
        $this->discoverParticleAttributes();
    }

    /**
     * PUBLISH-ONLY migrations for beam-core's four **ubiquitous** substrate tables (`beam_particles`,
     * `beam_versions`, `beam_submissions`, `beam_ownership_edges`) — via Laravel's native
     * {@see ServiceProvider::publishesMigrations()}, the estate-wide keep-natural pattern (mirrors the
     * plain-provider beam-ux / beam-workflows exemplars). beam-core is a package-tools
     * PackageServiceProvider, but it does NOT hand these to `->hasMigrations([...])`: that re-stamps the
     * filename to now on publish, drifting the natural dev-sequence dates. Publishing verbatim keeps them.
     *
     * `runsMigrations` stays FALSE — the package never runs these at runtime. `vendor:publish
     * --tag=beam-migrations` drops the copies into the HOST's `database/migrations/` (central pass) +
     * `database/migrations/tenant/` (Stancl tenant pass), and the host runs each pass.
     *
     * **UBIQUITOUS (central + every tenant).** Each table ships TWICE under the SAME source dir: a flat
     * copy at `database/migrations/<ts>_<name>.php` (→ `database/migrations/`, central) and a `tenant/`
     * twin (identical DDL) at `database/migrations/tenant/<ts>_<name>.php` (→ `database/migrations/tenant/`,
     * tenant). ONE directory mapping covers both — `publishes()` copies the source dir RECURSIVELY,
     * preserving each file's relative subpath, so the flat copies land in `migrations/` and the twins in
     * `migrations/tenant/`. The tenant twins carry a `Schema::hasTable()` dup-guard so a host that migrates
     * BOTH passes into ONE schema (the shared-test-DB harness) does not re-create; production targets
     * separate schemas, so the guard is simply false there.
     *
     * The sources carry real, natural timestamp prefixes (submissions `2026_07_08`, particles
     * `2026_07_16`, versions `2026_07_21`, ownership-edges `2026_08_01`) — `beam_particles` lands BEFORE
     * beam-ux's `beam_ux_entries` (`2026_08_03_170000`, which has-a particle body). With
     * `database.migrations.update_date_on_publish` at its default (false), `publishesMigrations` copies
     * each file verbatim — one correctly-timestamped migration per file, no double-stamp, order preserved.
     */
    protected function bootMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'beam-migrations');
    }

    /**
     * Boot-time #[ParticleResource] / #[ParticleOp] discovery into the two particle registries — the
     * REST/op sibling of {@see self::discoverResources()} (which feeds the admin manifest). Reads
     * `beam.core.particle.classes` / `.discover_paths`. Absent config ⇒ no-op (every existing host that
     * hand-registers its resources from a provider is unchanged; the two seams coexist).
     */
    protected function discoverParticleAttributes(): void
    {
        $classes = config('beam.core.particle.classes', []);
        $paths = config('beam.core.particle.discover_paths', []);

        if ($classes === [] && $paths === []) {
            return;
        }

        (new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        ))->discover($classes, $paths);
    }

    /**
     * Register the two particle route macros so a host mounts the generic particle surface declaratively
     * instead of hand-wiring {@see ParticleController} / {@see ParticleOperationController} against their
     * `RESOURCE`/`NAME` route defaults:
     *
     *   Route::particleResource('timeline-projects', 'timeline_project', ['only' => ['index', 'show']])
     *      → GET       {uri}      → index    ({names}.index)
     *        GET    {uri}/{id}    → show     ({names}.show)
     *        POST      {uri}      → store    ({names}.store)
     *        PUT|PATCH {uri}/{id} → update   ({names}.update)   (+ POST when 'legacyPostUpdate' => true)
     *        DELETE {uri}/{id}    → destroy  ({names}.destroy)
     *      each stamped with `->defaults(ParticleController::RESOURCE, $resourceKey)`. Options:
     *        - 'only'             verb subset (default all five; read-only is `['index','show']`, etc.)
     *        - 'names'            route-name prefix (default: the resource key with '-' → '_')
     *        - 'legacyPostUpdate' also accept POST {uri}/{id} for update, so a hand-rolled CRUD controller
     *                             can be dissolved WITHOUT changing its public URLs
     *        - 'idConstraint'     'uuid' to constrain {id} with whereUuid (default: unconstrained)
     *
     *   Route::particleOp('timeline-projects', 'timeline_project', 'regenerate', ['name' => '...'])
     *      → POST {uri}/{id}/op/{op} → invoke  ({resourceKey}.op.{op}, or the 'name' override)
     *      stamped with the operation controller's RESOURCE + NAME defaults. Options: 'method' (default
     *      'post'), 'name' (route-name override), 'idConstraint'.
     */
    protected function bootParticleRouteMacros(): void
    {
        if (Route::hasMacro('particleResource')) {
            return;
        }

        Route::macro('particleResource', function (
            string $uri,
            string $resourceKey,
            array $options = [],
        ): void {
            /** @var Router $this */
            $only = $options['only'] ?? ['index', 'show', 'store', 'update', 'destroy'];
            $name = $options['names'] ?? str_replace('-', '_', $resourceKey);
            $idConstraint = $options['idConstraint'] ?? null;

            $withId = function (RouteInstance $route) use ($idConstraint): RouteInstance {
                return $idConstraint === 'uuid' ? $route->whereUuid('id') : $route;
            };

            $stamp = function (RouteInstance $route, string $verb) use ($resourceKey, $name): RouteInstance {
                return $route
                    ->defaults(ParticleController::RESOURCE, $resourceKey)
                    ->name("{$name}.{$verb}");
            };

            if (in_array('index', $only, true)) {
                $stamp($this->get($uri, [ParticleController::class, 'index']), 'index');
            }

            if (in_array('show', $only, true)) {
                $stamp($withId($this->get("{$uri}/{id}", [ParticleController::class, 'show'])), 'show');
            }

            if (in_array('store', $only, true)) {
                $stamp($this->post($uri, [ParticleController::class, 'store']), 'store');
            }

            if (in_array('update', $only, true)) {
                $verbs = ($options['legacyPostUpdate'] ?? false) ? ['put', 'patch', 'post'] : ['put', 'patch'];
                $stamp($withId($this->match($verbs, "{$uri}/{id}", [ParticleController::class, 'update'])), 'update');
            }

            if (in_array('destroy', $only, true)) {
                $stamp($withId($this->delete("{$uri}/{id}", [ParticleController::class, 'destroy'])), 'destroy');
            }
        });

        Route::macro('particleOp', function (
            string $uri,
            string $resourceKey,
            string $op,
            array $options = [],
        ): void {
            /** @var Router $this */
            $verb = strtolower($options['method'] ?? 'post');

            $route = $this->{$verb}("{$uri}/{id}/op/{$op}", [ParticleOperationController::class, 'invoke'])
                ->defaults(ParticleOperationController::RESOURCE, $resourceKey)
                ->defaults(ParticleOperationController::NAME, $op)
                ->name($options['name'] ?? "{$resourceKey}.op.{$op}");

            if (($options['idConstraint'] ?? null) === 'uuid') {
                $route->whereUuid('id');
            }
        });

        // `Route::particleOps` (HTTP-02) — the plural loop-collapse sibling of `particleOp`. Takes a LIST of
        // op declarations and mounts each; the `group()` (middleware/prefix) stays the CALLER's. Each entry is
        // one of three forms, the op NAME derived from the declaration (you pass the list, not restated names):
        //   'reorder'                          a bare name — already registered elsewhere, mount only.
        //   DownloadMedia::class               a #[ParticleOp] class-string — discovered (register) + mounted.
        //   new ParticleOperation(name: …, …)  an inline object — registered here + mounted (today's 3 sites).
        Route::macro('particleOps', function (
            string $uri,
            string $resourceKey,
            array $ops,
            array $options = [],
        ): void {
            /** @var Router $this */
            $discovery = app(AttributedParticleDiscovery::class);
            $operations = app(ParticleOperationRegistry::class);

            foreach ($ops as $op) {
                $name = match (true) {
                    // An inline runtime object — register it, mount by its own name.
                    $op instanceof ParticleOperation => tap($op->name, fn () => $operations->register($op)),
                    // A #[ParticleOp] class-string — discover (registers) + read the attribute's name to mount.
                    is_string($op) && class_exists($op) => tap(
                        (new \ReflectionClass($op))->getAttributes(ParticleOp::class)[0]?->newInstance()->name
                            ?? throw new \InvalidArgumentException("Class [{$op}] carries no #[ParticleOp] to mount as a particle op."),
                        fn () => $discovery->registerClass($op),
                    ),
                    // A bare name — already registered elsewhere; mount only.
                    default => $op,
                };

                Route::particleOp($uri, $resourceKey, $name, $options);
            }
        });

        // `Route::particleRelative` (HTTP-02) — the bound-relative mount (the one genuinely new capability).
        // Route-model-binds a RELATIVE (the related model an operation is scoped/associated THROUGH — parent
        // is the common flavor, but hasManyThrough / pivot / an arbitrary scope are all relatives) and pushes
        // it + its `$via` into the route defaults of everything the `$routes` callback mounts, so the generic
        // ParticleController reads them (index/find base on the relative; create goes through the relation).
        //
        //   $via 'media'   (string relation) — controller scopes `$relative->media()` and AUTO-ASSOCIATES the
        //                                        inverse on create (`->media()->make()`); covers hasMany /
        //                                        hasManyThrough / belongsToMany (Eloquent handles "through").
        //   $via fn($rel, $q) => …  (Closure)  — an arbitrary scope (computed joins, polymorphic, cross-tenant).
        //                                        CANNOT auto-associate on create — pairs with the resource's
        //                                        own `prepare` hook for the FK.
        //
        // `opts['binding']` overrides the `{param}` name (default: the model's kebab basename). Authorize the
        // bound relative via a `can:` middleware on the caller's `group()` — resolved once, children inherit.
        Route::macro('particleRelative', function (
            string $uri,
            string $model,
            string|Closure $via,
            Closure $routes,
            array $options = [],
        ): void {
            /** @var Router $this */
            $binding = $options['binding'] ?? Str::kebab(class_basename($model));

            // Route-model-bind the relative (findOrFail → 404 for a stranger id), then mount the child routes
            // under the `{$uri}/{binding}` prefix, stamping each with the binding name + its via so
            // ParticleController resolves the bound instance per-request off the route parameter.
            $this->bind($binding, fn ($value) => $model::query()->findOrFail($value));

            $before = $this->getRoutes()->getRoutes();
            $beforeIds = [];
            foreach ($before as $existing) {
                $beforeIds[spl_object_id($existing)] = true;
            }

            $this->group(['prefix' => "{$uri}/{{$binding}}"], $routes);

            foreach ($this->getRoutes()->getRoutes() as $route) {
                if (! isset($beforeIds[spl_object_id($route)])) {
                    $route->defaults(ParticleController::RELATIVE, $binding);
                    $route->defaults(ParticleController::RELATIVE_MODEL, $model);
                    $route->defaults(ParticleController::VIA, $via);
                }
            }
        });
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
     * `splicewire:beam:frame-cache`), boot registers the cached class-strings directly — no `Finder` walk. Only when
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
