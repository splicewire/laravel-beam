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
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Operation\CallbackConformanceManifest;
use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\Operation\Operation;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Rushing\Versioning\Contracts\RecordReconciler;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\Frame\Contracts\FrameFilterProvider;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Realm\RealmDefinition;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\GuardActorPort;
use Splicewire\Beam\Capabilities\CapabilityRegistry;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Console\BeamSeedCommand;
use Splicewire\Beam\Console\DocblockCommand;
use Splicewire\Beam\Console\FrameCacheCommand;
use Splicewire\Beam\Console\FrameClearCommand;
use Splicewire\Beam\Console\GenerateAssetsCommand;
use Splicewire\Beam\Console\GenerateClientSdkCommand;
use Splicewire\Beam\Console\HouseStyleCommand;
use Splicewire\Beam\Console\MakeParticleOpCommand;
use Splicewire\Beam\Console\MakeParticleResourceCommand;
use Splicewire\Beam\Console\ManifestIndexCommand;
use Splicewire\Beam\Console\UndeclaredSurfaceCommand;
use Splicewire\Beam\Doctor\AgentsMdConventionAudit;
use Splicewire\Beam\Doctor\BeamCoreMigrationsAudit;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Entitlements\EntitlementGate;
use Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver;
use Splicewire\Beam\Frame\FrameResourceManifest;
use Splicewire\Beam\Frame\NullFrameFilterProvider;
use Splicewire\Beam\Frame\ParticleResourceRegistryPort;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\Middleware\HoneypotMiddleware;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;
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
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Source\Contracts\ForeignSourceProjector;
use Splicewire\Beam\Source\LadderForeignSourceProjector;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Source\ParticleShadower;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\ClientRuntimeContractAudit;
use Splicewire\Beam\Surgeon\DocblockTierAudit;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Splicewire\Beam\Surgeon\ParticleControllerRedundancyAudit;
use Splicewire\Beam\Surgeon\ParticleOperationBypassAudit;
use Splicewire\Beam\Surgeon\SdkEndpointDriftAudit;
use Splicewire\Beam\Surgeon\SdkHookMigrationAudit;
use Splicewire\Beam\Surgeon\SdkHookMigrationBridge;
use Splicewire\Beam\Surgeon\SdkNameConventionAudit;
use Splicewire\Beam\Surgeon\SdkReturnsCoverageAudit;
use Splicewire\Beam\Surgeon\SdkReturnsTypeScriptResolutionAudit;
use Splicewire\Beam\Surgeon\StatusChannelLiteralDriftAudit;
use Splicewire\Beam\Surgeon\TypeScriptShortNameCollisionAudit;
use Splicewire\Beam\Surgeon\TypeScriptUnknownResolutionAudit;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;
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
 * ship as PUBLISH-ONLY spatie/laravel-package-tools stubs — the idiomatic pattern for a
 * PackageServiceProvider. `runsMigrations` stays FALSE, so beam-core never loads them at runtime;
 * `vendor:publish --tag=beam-migrations` (or `splicewire:beam:install`) re-stamps + sequences
 * timestamped copies into the HOST at install time, which runs them. Each table is ubiquitous
 * (central + every tenant — the estate's "everything is shared by default" convention), so it
 * publishes to the SINGLE `database/migrations/shared/` destination — one file, not duplicated DDL —
 * registered via `->hasMigrations([...])` in {@see self::configurePackage()}. beam-tenancy's
 * `registerSharedMigrationsPath()` is the HOST-side half: it runs that one directory in BOTH the
 * central `migrate` pass and Stancl's tenant pass.
 *
 * Registered via `->hasMigrations([...])`, NOT `->discoversMigrations()` — its `->files()` is
 * non-recursive and would miss the `shared/` subdir. The `.stub` extension keeps the framework
 * migrator from ever loading them in place; publish re-stamps each stub to the install moment via
 * package-tools' `generateMigrationName` (auto-timestamp + sequence) — the estate-wide publish-only
 * stub pattern every beam package follows.
 *
 * Layering law (ADR-0156 inverted ADR-0082): beam -> frame, never frame -> beam. Beam is the paid
 * CMS engine and MAY reference the agnostic `Schemastud\Frame\*` foundation directly (it owns the
 * resource DECLARATION — `#[ParticleResource]` + the registry — and feeds frame's generic manifest
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
            ->hasConfigFile(['beam/core', 'beam/client'])
            // beam's base tables ship as PUBLISH-ONLY spatie/laravel-package-tools stubs — the idiomatic
            // pattern for a PackageServiceProvider. `runsMigrations` stays FALSE (the package-tools
            // default), so beam-core does NOT auto-load these at runtime — `vendor:publish
            // --tag=beam-migrations` re-stamps + sequences timestamped copies into the HOST and the host
            // runs them.
            //
            // Every table is UBIQUITOUS (central + every tenant — "everything is shared by default"),
            // so each publishes to the SINGLE `shared/…` destination, not a duplicated flat+tenant
            // pair. `hasMigrations([...])` (NOT `discoversMigrations()`, whose ->files() is
            // non-recursive and would miss the `shared/` subdir) routes the destination via
            // package-tools' generateMigrationName (dirname of the entry name). beam-tenancy's
            // registerSharedMigrationsPath() is what actually runs `database/migrations/shared/` in
            // both the central `migrate` pass and Stancl's tenant pass — beam-core just publishes here.
            ->hasMigrations([
                'shared/create_beam_particles_table',
                'shared/create_beam_versions_table',
                'shared/create_beam_submissions_table',
                'shared/create_beam_ownership_edges_table',
                'shared/create_beam_schemas_table',
            ]);
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
        // Data class straight off beam's own ParticleResourceRegistry (ADR-0156: the SchemaDataResolver port
        // was retired; beam owns the registry, so it reads it directly rather than deferring to a host
        // binding). A host that wants query-composing list reads binds its own DataFilterRecordHydrator
        // over the same ParticleHydrator port (port-in-base / binding-in-host).
        $this->app->bind(ParticleHydrator::class, PayloadParticleReader::class);

        // Frame's agnostic ResourceRegistry port (ADR-0156: "frame has no concept of admin") is bound onto
        // the genuinely stateless {@see ParticleResourceRegistryPort} forwarder — it exists only because
        // PHP has no overloading (ParticleResourceRegistry's REST-facing `get(): ParticleResource` and the
        // port's `get(): ResourceDefinition` can't share a method name on one class), so every call passes
        // straight through to ParticleResourceRegistry's differently-named projection methods. A SINGLETON
        // so boot-time #[ParticleResource] discovery (packageBooted) persists across the request. Frame's
        // manifest machinery reads the port; it never imports this beam type (arrow points DOWN, beam → frame).
        $this->app->singleton(ParticleResourceRegistryPort::class);
        $this->app->alias(ParticleResourceRegistryPort::class, ResourceRegistry::class);

        // The build-time #[ParticleResource] manifest cache (mirrors bootstrap/cache/packages.php). When it
        // exists, discoverResources() reads the cached class-strings instead of re-walking the filesystem
        // each boot; `splicewire:beam:frame:cache` writes it, `splicewire:beam:frame:clear` / `optimize:clear` remove it.
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

        // The realm OVERLAY registry (Frame OS ticket 14, ADR-0014 §A2) — the seam a capability/satellite
        // tier registers an ADDITIVE realm overlay through. A SINGLETON so a package registering an overlay
        // from its own provider mutates the shared instance the RealmManifestProjector reads BEFORE it
        // emits the manifest. beam knows only "overlays" here (keyed by realm key), never "satellites", so
        // the frame stays satellite-agnostic. Ships EMPTY ⇒ INERT: no overlay ⇒ the manifest is byte-for-
        // byte. Unlike RealmRegistry it has no realm-creation verb — an overlay can only enrich a realm
        // RealmRegistry already ships, never conjure a new realm/tile.
        $this->app->singleton(RealmOverlayRegistry::class, fn () => new RealmOverlayRegistry);

        // The per-realm presentation-override registry (RDU-03) — the overlay layer behind the `?realm`
        // seam. A SINGLETON hydrated from `frame.realm_resource_overrides` at resolution (the default
        // config ships EMPTY, so it is INERT — identity projection in every realm — until RDU-05 seeds
        // real overlays). Beam's ParticleResourceRegistry applies it when it projects a declaration for a
        // realm; frame never sees it (the realm concept lives entirely in beam). A host may also register
        // overlays imperatively via `->override($key, $realm, new RealmResourceOverride(...))`.
        $this->app->singleton(RealmResourceRegistry::class, fn ($app) => (new RealmResourceRegistry(
            $app->make(RealmRegistry::class),
        ))->loadConfig((array) config('frame.realm_resource_overrides', [])));

        // The generic particle REST surface (promoted from splicewire-app, ADR-0116). The two declaration
        // registries are container singletons so the `Route::particleResource()` / `Route::particleOp()`
        // route macros (defined in packageBooted below) survive across the request; the DEFAULT response
        // seam is the neutral {@see ArrayResponseEnvelope} (a plain `{ data: … }` JsonResponse). A richer
        // host BINDS its own envelope adapter over its response DTO — and, when it subclasses the registries
        // for its own container FQN, re-aliases these singletons to the same instance (port-in-base /
        // binding-in-host).
        $this->app->singleton(ParticleResourceRegistry::class, fn ($app) => (new ParticleResourceRegistry(
            $app->make(RealmResourceRegistry::class),
        ))->loadRealmMap((array) config('frame.realms', [])));
        $this->app->singleton(ParticleOperationRegistry::class);
        $this->app->bind(ResponseEnvelope::class, ArrayResponseEnvelope::class);

        // particle-doctrine-convergence ticket 09: the cross-transport ability resolver + its ACTOR port.
        // `bindIf` on the port, because "who is acting" is the transport's answer to give: HTTP has the
        // guard (the default bound here — the only place ambient auth is read), while a transport with no
        // ambient user (MCP over stdio) binds its own. The resolver itself is transport-blind and stateless.
        $this->app->bindIf(ActorPort::class, GuardActorPort::class);
        $this->app->bind(AbilityResolver::class);

        // The client-SDK codegen's default tenant source: reads mounted particle routes off the live route
        // table (the `router` singleton) + resolves each route's output DTO via the resource registry (a CRUD
        // verb) or the operation registry (a named op's declared `output:` slot).
        $this->app->bind(ParticleRouteManifestSource::class, fn ($app) => new ParticleRouteManifestSource(
            $app->make('router'),
            $app->make(ParticleResourceRegistry::class),
            $app->make(ParticleOperationRegistry::class),
        ));

        // The schema-SOURCE factory registry (ADR-0192 §5, JN-15): a singleton any package's
        // provider pushes `(key, factory)` tiers into, so a package contributes a SchemaRegistry
        // tier without the host being edited. beam-core seeds the `fleet` tier itself — the
        // committed fleet-wide conformance artifacts (vocabularies), ordered ahead of `db` by the
        // default source list so a tenant registration can never shadow them (first-hit-wins).
        $this->app->singleton(SchemaSources::class, function () {
            $fleet = (string) config('beam.core.schema.fleet_path', 'schemas/fleet');
            $fleetDir = str_starts_with($fleet, '/') ? $fleet : resource_path($fleet);

            return (new SchemaSources)->register(
                'fleet',
                fn () => new FilesystemSchemaRegistry($fleetDir),
            );
        });

        // The beam-install self-registration manifest (ticket 08): a singleton every beam-* package
        // pushes its own install step into, from its own provider. beam-core never learns consumer names.
        $this->app->singleton(BeamInstallManifest::class);

        // The beam-doctor aggregation manifest (beam-ux-prototype-extract ticket 08): the doctor-side
        // twin of the install manifest — a singleton consumers push their own audits into, so one
        // `beam:doctor` run aggregates the whole family. beam-core's own audits stay hardcoded (coexist).
        $this->app->singleton(BeamDoctorManifest::class);

        // The beam-seed self-registration manifest: the seed-side twin of the install/doctor manifests — a
        // singleton every beam-* package pushes its own SeedStep into, so one `splicewire:beam:seed` run
        // seeds the whole family's package-owned data. beam-core never learns a consumer's name; each step
        // may carry a config gate so a demo-only seeder fires only where its gate is on.
        $this->app->singleton(BeamSeedManifest::class);

        // The index of indexes (beam-manifest-index): the same self-registration singleton pattern turned
        // on the registries themselves. Every registry/manifest describes itself in, so `beam:manifests`
        // lists the estate's injection points. beam-core self-describes its own set in packageBooted().
        $this->app->singleton(ManifestIndex::class);

        $this->registerSurgeonAudits();
        $this->registerConformanceManifest();
    }

    /**
     * Surgeon's conformance-sweep discovery port (ticket 07, decision 5) — generalized to a
     * zero-config beam default (beam-surgeon-rollout #01). A host used to hand-write this exact
     * binding in its own AppServiceProvider (splicewire-app did, before migrating onto this
     * default in #02); now any host that installs `rushing/laravel-surgeon` gets `surgeon:audit`
     * discovery of beam's registered audits for free. Guarded exactly like `registerSurgeonAudits()`
     * — a host without surgeon installed pays nothing and gets no binding.
     */
    protected function registerConformanceManifest(): void
    {
        if (! interface_exists(ConformanceManifest::class)) {
            return;
        }

        $this->app->bind(
            ConformanceManifest::class,
            fn ($app) => new CallbackConformanceManifest(
                fn () => $app->make(BeamDoctorManifest::class)->registrations(),
            ),
        );
    }

    /**
     * The estate-POLICY surgeon audits beam owns (ADR-0092 direction: beam depends DOWN on surgeon, the
     * foundation byte-splice engine). Each nominates a generic surgeon operation via the
     * Finding→Operation bridge (`Rushing\Surgeon\Operation\SuggestsOperations`) and self-registers into
     * {@see BeamDoctorManifest} so surgeon's conformance sweep (`surgeon:audit`) discovers them through
     * the host's `ConformanceManifest` port — exactly like any other consumer audit.
     *
     * Guarded on surgeon actually being installed (it is a dev-only dependency): a host that composes
     * beam WITHOUT rushing/laravel-surgeon autoloads nothing here and pays nothing — beam degrades
     * gracefully, mirroring the host provider's `interface_exists(ConformanceManifest::class)` guard.
     */
    protected function registerSurgeonAudits(): void
    {
        if (! interface_exists(SuggestsOperations::class)) {
            return;
        }

        // Bind each audit with its default host-scoped construction so the manifest can resolve it by
        // class-string (the sweep does `$app->make($audit)`): the house-style scan defaults to the app
        // base path; the SDK-drift audit points at the co-dev splicewire/laravel-connector checkout; the docblock
        // tier-audit scans the app base path and places FQNs via the surgeon PackageGraph.
        $this->app->bind(HouseStyleAudit::class, fn ($app) => new HouseStyleAudit([$app->basePath()]));
        $this->app->bind(SdkEndpointDriftAudit::class, fn () => SdkEndpointDriftAudit::forClientPackage());
        $this->app->bind(SdkNameConventionAudit::class, fn () => SdkNameConventionAudit::forClientPackage());
        $this->app->bind(ParticleControllerRedundancyAudit::class, fn () => ParticleControllerRedundancyAudit::forRoutes());
        $this->app->bind(ParticleOperationBypassAudit::class, fn () => ParticleOperationBypassAudit::forRoutes());
        $this->app->bind(DocblockTierAudit::class, function ($app) {
            $root = $app->basePath();
            $graph = PackageGraph::fromRoots([$root]);

            return new DocblockTierAudit($this->phpFilesUnder($root), $graph->packageForRoot($root) ?? 'app', $graph);
        });
        $this->app->singleton(SdkHookMigrationBridge::class, fn () => new SdkHookMigrationBridge(
            script: config('beam.client.surgeon.sdk_hook_migration.bridge.script'),
            node: (string) config('beam.client.surgeon.sdk_hook_migration.bridge.node', 'node'),
            timeout: (int) config('beam.client.surgeon.sdk_hook_migration.bridge.timeout', 60),
        ));
        $this->app->bind(SdkHookMigrationAudit::class, fn ($app) => SdkHookMigrationAudit::forApp($app->make(SdkHookMigrationBridge::class)));
        $this->app->bind(SdkReturnsCoverageAudit::class, fn () => SdkReturnsCoverageAudit::forApp());
        $this->app->bind(SdkReturnsTypeScriptResolutionAudit::class, fn () => SdkReturnsTypeScriptResolutionAudit::forApp());
        $this->app->bind(TypeScriptShortNameCollisionAudit::class, fn () => TypeScriptShortNameCollisionAudit::forApp());
        $this->app->bind(StatusChannelLiteralDriftAudit::class, fn () => StatusChannelLiteralDriftAudit::forApp());
        $this->app->bind(TypeScriptUnknownResolutionAudit::class, fn () => TypeScriptUnknownResolutionAudit::forApp());
        // The negative-space detector reads the two particle registries and the LIVE route table — no
        // host-scoped file paths, so it needs no `forApp()` seam of its own.
        $this->app->bind(UndeclaredSurfaceAudit::class, fn ($app) => new UndeclaredSurfaceAudit(
            $app->make(ParticleResourceRegistry::class),
            $app->make(ParticleOperationRegistry::class),
        ));
        // The Inertia leg of the same detector. Unlike the HTTP leg above it IS host-scoped and
        // filesystem-bound — `Inertia::render` props live in host source, not in any registry or route table —
        // which is exactly why it is a sibling audit rather than a row-producer inside that one.
        $this->app->bind(InertiaPropShapeAudit::class, fn () => InertiaPropShapeAudit::forApp());
        // The central-pin census scans the host's own code AND the family packages it composes — almost
        // every pin in the estate lives in a package, so an `app/`-only scan would report a comfortable zero
        // from inside every host while the backlog sat one directory over. See the audit's `forApp()`.
        $this->app->bind(CentralPinJustificationAudit::class, fn () => CentralPinJustificationAudit::forApp());
        // The meta-audit reads the LIVE index to derive its own scan scope, so it is bound off the container
        // rather than given a static path list — the governed set is whatever has described itself by boot.
        $this->app->bind(UndescribedRegistryAudit::class, fn ($app) => UndescribedRegistryAudit::forIndex(
            $app->make(ManifestIndex::class),
        ));

        $manifest = $this->app->make(BeamDoctorManifest::class);
        $manifest->register('splicewire/laravel-beam', HouseStyleAudit::class);
        $manifest->register('splicewire/laravel-beam', SdkEndpointDriftAudit::class);
        $manifest->register('splicewire/laravel-beam', SdkNameConventionAudit::class);
        $manifest->register('splicewire/laravel-beam', ParticleControllerRedundancyAudit::class);
        $manifest->register('splicewire/laravel-beam', ParticleOperationBypassAudit::class);
        $manifest->register('splicewire/laravel-beam', DocblockTierAudit::class);
        $manifest->register('splicewire/laravel-beam', SdkHookMigrationAudit::class);
        $manifest->register('splicewire/laravel-beam', SdkReturnsCoverageAudit::class);
        $manifest->register('splicewire/laravel-beam', SdkReturnsTypeScriptResolutionAudit::class);
        $manifest->register('splicewire/laravel-beam', TypeScriptShortNameCollisionAudit::class);
        $manifest->register('splicewire/laravel-beam', StatusChannelLiteralDriftAudit::class);
        $manifest->register('splicewire/laravel-beam', TypeScriptUnknownResolutionAudit::class);
        // Advisory: the estate's undeclared surface is a burn-down, and a several-hundred-endpoint backlog
        // that fails the build is just a blocked build. It ratchets via its committed artifact, not the gate.
        $manifest->register('splicewire/laravel-beam', UndeclaredSurfaceAudit::class);
        // Advisory, matching both other legs of the detector for the same reason: converting an inline prop
        // array into a declared page-props Data object is an API-contract commitment on the client boundary,
        // so it is a burn-down work-list, not a gate.
        $manifest->register('splicewire/laravel-beam', InertiaPropShapeAudit::class);
        // Advisory, permanently: 10 of the estate's 23 central pins cite nothing and 7 are clearly not floor,
        // so the output is a DOCUMENTATION backlog. A documentation backlog that fails the build is just a
        // blocked build. Reporting a pin is also not a claim it is wrong — the Role/Permission contradiction
        // it surfaces is ADR-sized and deliberately unresolved here.
        $manifest->register('splicewire/laravel-beam', CentralPinJustificationAudit::class);
        // GATE — the ONLY gating registration in the particle-doctrine-convergence effort. Everything else
        // here is a burn-down backlog; this one protects the discoverability surface the rest of the effort
        // depends on. `splicewire:beam:manifests --json` is what an agent is told to run to answer "where do
        // I register this", so an undescribed registry is not a stale document, it is an agent building a
        // parallel mechanism next to one that already exists. It is also the cheapest possible fix (one
        // `describe(...)` call), which is what makes blocking proportionate here and nowhere else. Its
        // findings are Fail, so it blocks at the runner's DEFAULT floor.
        $manifest->register('splicewire/laravel-beam', UndescribedRegistryAudit::class, gate: true);
    }

    /**
     * Absolute paths of every `.php` file under a root (skipping `vendor`/`node_modules`/`.git`) — the
     * file set {@see DocblockTierAudit} scans. Mirrors the moved `surgeon:docblock` command's own walk.
     *
     * @return list<string>
     */
    protected function phpFilesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), ['vendor', 'node_modules', '.git'], true);
                    }

                    return $current->getExtension() === 'php';
                },
            ),
        );

        $files = [];
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function packageBooted(): void
    {
        // The `Route::particleResource()` / `Route::particleOp()` route macros — the declarative mount for
        // the generic particle REST + operation surface, so a host stops hand-mounting the generic
        // controllers against the RESOURCE/NAME route defaults.
        $this->bootParticleRouteMacros();

        // SINGLE-TENANT shared-migrations fallback (beam-install-turnkey trap 1). Every beam-* package
        // publishes its ubiquitous tables into ONE destination, `database/migrations/shared/` — a SUBDIR
        // the stock framework migrator never recurses into. beam-tenancy's registerSharedMigrationsPath()
        // is the HOST-side half that runs it (central + tenant passes), but a SINGLE-TENANT host without
        // beam-tenancy would get those stubs published yet SILENTLY NOT migrated (`migrate` reports
        // "Nothing to migrate"). So when the tenancy package is ABSENT, beam-core itself registers the
        // shared directory for the central migrate pass — making even a host that never runs
        // `splicewire:beam:install` safe. GUARDED on the tenancy provider not being present so it NEVER
        // double-registers on a multi-tenant host (that package owns the path). `loadMigrationsFrom` over
        // an empty/missing directory is a harmless no-op, so this is safe before the first publish too.
        if (! $this->sharedMigrationsOwnedByTenancy()) {
            $this->loadMigrationsFrom(database_path('migrations/shared'));
        }

        // Base-tier readiness command. Moat-free (never touches the satellite); the
        // frame/schema-forms/data-schemas checks it runs are advisory + presence-conditional.
        if ($this->app->runningInConsole()) {
            $this->commands([
                BeamDoctorCommand::class,
                BeamInstallCommand::class,
                BeamSeedCommand::class,
                FrameCacheCommand::class,
                FrameClearCommand::class,
                // The client-SDK codegen (promoted from the platform app) + its umbrella. Source-agnostic:
                // a host binds a RouteManifestSource per realm into `beam.client.sources`. The default
                // tenant binding is the particle-route source (below), so a fresh satellite generates from
                // its mounted `#[ParticleResource]` routes with no further wiring.
                GenerateClientSdkCommand::class,
                GenerateAssetsCommand::class,
                // The index of indexes: `splicewire:beam:manifests` lists every registry/manifest that has
                // described itself in, with its injection-point shape and how to register into it.
                ManifestIndexCommand::class,
                UndeclaredSurfaceCommand::class,
                // The particle scaffolders (particle-doctrine-convergence ticket 08). The estate had NO
                // generator of any kind, and that absence is the mechanical reason deviation propagates: an
                // agent adding a surface reverse-engineers the pattern from whatever example it opened and
                // inherits that example's deviations. These mint the shape slots ALREADY FILLED, so the
                // declaration exists before the logic does and a fresh surface is born conformance-clean.
                MakeParticleResourceCommand::class,
                MakeParticleOpCommand::class,
            ]);

            // The estate-POLICY surgeon commands relocated DOWN from surgeon (they hard-code estate
            // opinions over surgeon's generic byte-splice mechanisms — the mechanism stays in surgeon,
            // policy + its runner move to beam): `surgeon:house-style` (the "no strict_types/final/readonly"
            // strip) and `surgeon:docblock` (the "no up-tier {@see}" tier-audit; it still imports the
            // surgeon-resident DocblockDerefOperation — the legal downward edge). Guarded on surgeon being
            // installed — the commands construct surgeon-resident classes, so a host without surgeon
            // simply doesn't get them.
            if (interface_exists(Operation::class)) {
                $this->commands([
                    HouseStyleCommand::class,
                    DocblockCommand::class,
                ]);
            }

            // Wire the manifest into Laravel's `optimize` / `optimize:clear` so it builds and clears
            // alongside the framework's own caches (the supported ServiceProvider hook).
            $this->optimizes(
                optimize: 'splicewire:beam:frame:cache',
                clear: 'splicewire:beam:frame:clear',
                key: 'beam-frame-resources',
            );

            // The particle scaffolders' stubs, publishable so a host can customize what its own surfaces are
            // born as. `ParticleGeneratorCommand::resolveStubPath()` prefers the host copy at the SAME
            // relative path, which is the framework's own `stub:publish` convention — publish once, edit in
            // place, every later generate picks it up with no flag. Deliberately NOT registered as a
            // `splicewire:beam:install` step: a host that never customizes its stubs should carry no copy of
            // them, and an unpublished stub is not a missing file, it is the default.
            $this->publishes([
                dirname(__DIR__).'/stubs' => base_path('stubs'),
            ], 'beam-stubs');

            // The client-runtime reference implementation (particle-doctrine-followups #12): the two
            // host-owned modules the generated client imports (`beam.client.{client_import,routes_import}`).
            // Published to the satellite alias root (`resources/js/lib`) — a platform-layout host copies
            // them under its own alias root instead. NOT an install step: `vendor:publish` never
            // overwrites an existing file, but a host that hand-rolled its runtime shouldn't gain a
            // second copy in a layout it doesn't use; `ClientRuntimeContractAudit` names this tag when
            // either module is missing. Contract: docs/client-runtime-contract.md.
            $this->publishes([
                dirname(__DIR__).'/stubs/client-runtime/api.ts' => resource_path('js/lib/api.ts'),
                dirname(__DIR__).'/stubs/client-runtime/routes.ts' => resource_path('js/lib/routes.ts'),
            ], 'beam-client-runtime');
        }

        // beam-core registers ITS OWN install step, core-first (order 0), like any consumer — the config
        // AND the publish-only substrate migrations. Consumers self-register their steps from their own
        // providers (ticket 08). The publish tags are package-tools' auto-generated group names, which
        // STRIP the `laravel-` prefix (Package::shortName() → `beam`): the config group is `beam-config`
        // and the migrations group is `beam-migrations` (NOT `laravel-beam-*`). `beam-migrations` publishes
        // the base-table stubs into the host, re-stamped to the install moment (flat → database/migrations/,
        // tenant/ → database/migrations/tenant/); `migrates: true` then runs a single `migrate` at the end
        // so the freshly-published copies apply.
        $this->app->make(BeamInstallManifest::class)->register(
            package: 'splicewire/laravel-beam (core)',
            publishTags: ['beam-config', 'beam-migrations'],
            migrates: true,
            order: 0,
        );

        // beam-core is itself an "operator" of the estate-wide publish-only stub migrations convention
        // (BeamServiceProvider docblock above) — self-registers the doctor/operator check on ITS OWN
        // migrations, same as every other beam-* package registers it on theirs.
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            BeamCoreMigrationsAudit::class,
        );

        // splicewire-ecosystem ticket 15: beam-core self-registers the fleet AGENTS.md/CLAUDE.md
        // convention drift check on EVERY host that installs it — a host repo never has to opt in.
        // Advisory (gate defaults to false): this is drift-checking, not a hard failure.
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            AgentsMdConventionAudit::class,
        );

        // particle-doctrine-followups #12: the client-runtime contract check. Advisory, and registered
        // UNCONDITIONALLY (a plain DoctorAudit — deliberately NOT behind the surgeon-installed guard,
        // matching the ux-prototype precedent): a missing or non-conforming `@/lib/api`/`@/lib/routes`
        // breaks the generated client at compile time in any host, surgeon or no surgeon. The fix is
        // host-owned (`vendor:publish --tag=beam-client-runtime`, or write the module), so this is a
        // nudge with a named fix, not a gate.
        $this->app->bind(ClientRuntimeContractAudit::class, fn () => ClientRuntimeContractAudit::forApp());
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            ClientRuntimeContractAudit::class,
        );

        // beam-core self-describes its own registries into the index of indexes (beam-manifest-index),
        // foundation-first, exactly as it self-registers its install step and doctor audits. Consumers add
        // their own `describe(...)` from their providers; the host app describes its app-local registries.
        $this->describeCoreManifests();

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

        // Resource DECLARATION discovery (ADR-0156). Reflect the configured #[ParticleResource] classes +
        // scan the configured discover-paths into beam's singleton ParticleResourceRegistry, which frame's
        // manifest machinery reads through the ResourceRegistry port (via ParticleResourceRegistryPort). This
        // is the discovery wiring that used to live in frame's FrameServiceProvider — moved here because the
        // #[ParticleResource] opinion is beam's.
        $this->discoverResources();

        // Attributed REST/op discovery (ADR-0116/0160): the runtime twin of #[ParticleResource] discovery.
        // Reflect the configured #[ParticleResource] / #[ParticleOp] Data classes (+ discover-paths) into
        // the two particle registries, so a host declares a REST resource / named op ON its Data class
        // instead of hand-registering it from a provider. Closures the attribute can't carry are resolved
        // from `public static` convention methods on the annotated class.
        $this->discoverParticleAttributes();

        // Frame OS ticket 08 (ADR-0013 §2): beam is the authority that unifies the two authorization
        // planes. Register a Laravel Gate ability per KNOWN feature key (`entitlement:{key}`) delegating to
        // the entitlement gate (which consults the bound kernel EntitlementResolver) — so the feature plane
        // (`Gate::allows('entitlement:workbench.enter')`) and the per-action plane (`Gate::allows('song',
        // $song)`) answer one uniform predicate. Inert by default: with no `app.entitlements` configured
        // and the null resolver, no abilities register that would ever pass.
        $this->registerEntitlementAbilities();
    }

    /**
     * Whether the tenancy substrate package owns the `database/migrations/shared/` path (it registers that
     * directory into BOTH the central and Stancl tenant migrate passes from its own provider). When present,
     * beam-core must NOT also register it (double-registration would run each shared migration twice); when
     * ABSENT (a single-tenant host), beam-core registers it for the central pass so the shared tables migrate.
     * Detected by the provider class existing — the tenancy package ships it, and nothing else defines it.
     * Kept as a string (not an imported FQCN) because beam-core does NOT depend on beam-tenancy — this is an
     * OPTIONAL-package probe, and a `use` import would misread as a hard dependency edge.
     */
    protected function sharedMigrationsOwnedByTenancy(): bool
    {
        return class_exists('Splicewire\Beam\Tenancy\BeamMultiTenancyServiceProvider');
    }

    /**
     * Frame OS ticket 08 (ADR-0013 §2/§4): define an `entitlement:{key}` Gate ability per known feature
     * key, each delegating to the {@see EntitlementGate}. The key universe is
     * `array_keys(config('app.entitlements'))` ∪ any beam-known feature keys the host lists under
     * `beam.core.entitlements.keys`. The ability ignores the resolved user argument and asks the gate for
     * the ambient principal ($user) so both a request-time user and a null/tenant principal route through
     * the one resolver. Skipped entirely when the kernel resolver contract is unbound (a bare install
     * without permission-cascade) — the gate stays byte-for-byte inert.
     */
    protected function registerEntitlementAbilities(): void
    {
        if (! $this->app->bound(EntitlementResolver::class)) {
            return;
        }

        $gate = $this->app->make(Gate::class);

        $keys = array_values(array_unique([
            ...array_keys((array) config('app.entitlements', [])),
            ...(array) config('beam.core.entitlements.keys', []),
        ]));

        foreach ($keys as $key) {
            $gate->define(
                'entitlement:'.$key,
                fn ($user = null) => $this->app->make(EntitlementGate::class)
                    ->allows($key, $user),
            );
        }
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
     *        - 'controller'       route through a dedicated ParticleController subclass instead of the
     *                             generic {@see ParticleController} (still stamped with the `_particle`
     *                             default, so it keeps the auto-`@group`); default: the generic controller
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
            // 'controller' — route THROUGH a dedicated ParticleController subclass (e.g. SiloController) so it
            // gets the `_particle` default + auto-`@group` like the generic surface, instead of hand-rolled
            // explicit routes. Defaults to the generic controller (fully backward-compatible).
            $controller = $options['controller'] ?? ParticleController::class;

            $withId = function (RouteInstance $route) use ($idConstraint): RouteInstance {
                return $idConstraint === 'uuid' ? $route->whereUuid('id') : $route;
            };

            $stamp = function (RouteInstance $route, string $verb) use ($resourceKey, $name): RouteInstance {
                return $route
                    ->defaults(ParticleController::RESOURCE, $resourceKey)
                    ->name("{$name}.{$verb}");
            };

            if (in_array('index', $only, true)) {
                $stamp($this->get($uri, [$controller, 'index']), 'index');
            }

            if (in_array('show', $only, true)) {
                $stamp($withId($this->get("{$uri}/{id}", [$controller, 'show'])), 'show');
            }

            if (in_array('store', $only, true)) {
                $stamp($this->post($uri, [$controller, 'store']), 'store');
            }

            if (in_array('update', $only, true)) {
                $verbs = ($options['legacyPostUpdate'] ?? false) ? ['put', 'patch', 'post'] : ['put', 'patch'];
                $stamp($withId($this->match($verbs, "{$uri}/{id}", [$controller, 'update'])), 'update');
            }

            if (in_array('destroy', $only, true)) {
                $stamp($withId($this->delete("{$uri}/{id}", [$controller, 'destroy'])), 'destroy');
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

            // A Stream-kind op (ADR-0160) has no single resolved response — it emits a sequence of
            // typed SSE events instead. `particleOp` mounts through the generic controller, so there's
            // no per-route call site to chain `->streams()` onto directly; an `'streams'` option lets
            // the caller declare it here (surgeon-audit-viability ticket 28).
            if ($options['streams'] ?? null) {
                $route->streams($options['streams']);
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
     * Self-describe beam-core's own registries into the {@see ManifestIndex} (beam-manifest-index), the one
     * catalogue `splicewire:beam:manifests` renders. Foundation-first order; a consumer package or the host
     * app appends its own `describe(...)` from its provider (the index never reaches up to discover them).
     */
    protected function describeCoreManifests(): void
    {
        $index = $this->app->make(ManifestIndex::class);
        $pkg = 'splicewire/laravel-beam';

        foreach ([
            new ManifestDescriptor(
                name: 'ManifestIndex',
                of: 'the registries themselves — this index of indexes',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::RunAll,
                registerHint: 'app(ManifestIndex::class)->describe(new ManifestDescriptor(...)) from your provider',
                where: ManifestIndex::class,
                package: $pkg, order: 0,
            ),
            new ManifestDescriptor(
                name: 'BeamInstallManifest',
                of: 'package install steps (publish tags + migrate flag), run core-first',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::RunAll,
                registerHint: 'app(BeamInstallManifest::class)->register(package:, publishTags:, migrates:, order:)',
                where: BeamInstallManifest::class,
                package: $pkg, order: 1,
            ),
            new ManifestDescriptor(
                name: 'BeamDoctorManifest',
                of: 'per-package readiness audits aggregated by splicewire:beam:doctor',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::RunAll,
                registerHint: 'app(BeamDoctorManifest::class)->register(package:, audit:, gate:, order:)',
                where: BeamDoctorManifest::class,
                package: $pkg, order: 2,
            ),
            new ManifestDescriptor(
                name: 'BeamSeedManifest',
                of: 'per-package seed steps (seeder class + config gate) run by splicewire:beam:seed, core-first',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::RunAll,
                registerHint: 'app(BeamSeedManifest::class)->register(package:, seederClass:, order:, configGate:)',
                where: BeamSeedManifest::class,
                package: $pkg, order: 3,
            ),
            new ManifestDescriptor(
                name: 'RouteManifestSource',
                of: 'client-SDK route manifests, one implementation bound per realm',
                seam: ManifestSeam::ConfigSource,
                arity: ManifestArity::RunAll,
                registerHint: 'bind your RouteManifestSource class-string per realm key in beam.client.sources',
                where: 'config beam.client.sources',
                package: $pkg, order: 10,
            ),
            new ManifestDescriptor(
                name: 'BeamSchemaRegistry',
                of: 'JSON schemas by $id, resolved across ordered sources (fleet → db → file), first match wins',
                seam: ManifestSeam::ChainedLookup,
                arity: ManifestArity::PickOne,
                registerHint: 'order the source list in beam.core.schema.sources; writes go to the first source',
                where: 'config beam.core.schema.sources',
                package: $pkg, order: 11,
            ),
            new ManifestDescriptor(
                name: 'SchemaSources',
                of: 'package-contributed schema-source tier factories composed into BeamSchemaRegistry (JN-15)',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::RunAll,
                registerHint: "app(SchemaSources::class)->register('key', fn () => new FilesystemSchemaRegistry(...)) from your provider",
                where: SchemaSources::class,
                package: $pkg, order: 11,
            ),
            new ManifestDescriptor(
                name: 'ParticleResourceRegistry',
                of: 'Frame resource definitions (model→Data projections) driving reads + the editor',
                seam: ManifestSeam::AttributeScan,
                arity: ManifestArity::PickOne,
                registerHint: 'annotate a Data class #[ParticleResource] (or add its dir to beam.core.resources.discover_paths)',
                where: '#[ParticleResource] → '.ParticleResourceRegistry::class,
                package: $pkg, order: 12,
            ),
            new ManifestDescriptor(
                name: 'ParticleOperationRegistry',
                of: 'named particle operations (custom actions) mounted on the generic op controller',
                seam: ManifestSeam::AttributeScan,
                arity: ManifestArity::PickOne,
                registerHint: 'annotate a Data class #[ParticleOp] (discovered alongside #[ParticleResource])',
                where: '#[ParticleOp] → '.ParticleOperationRegistry::class,
                package: $pkg, order: 13,
            ),
            new ManifestDescriptor(
                name: 'RealmRegistry',
                of: 'authorization realms (admin·tenant·user·docs) governing resource access',
                seam: ManifestSeam::AttributeScan,
                arity: ManifestArity::PickOne,
                registerHint: 'declare a #[Realm]-marker class and list it in beam.core.realms.classes',
                where: '#[Realm] → '.RealmRegistry::class,
                package: $pkg, order: 14,
            ),
            // Found undescribed by UndescribedRegistryAudit (ticket 13) — the two realm registries that sit
            // BESIDE RealmRegistry. Both were exactly the failure the meta-audit exists to catch: a host
            // reading the index would find the realm registry, conclude that is where realm shape is
            // registered, and never learn that presentation overrides and additive overlays are two further
            // seams with different injection points.
            new ManifestDescriptor(
                name: 'RealmOverlayRegistry',
                of: 'additive realm OVERLAYS folded onto an EXISTING realm descriptor before the manifest emits',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::ComposeMany,
                // Not RunAll: a read is keyed by realm and the overlays for that realm are FOLDED in
                // registration order, each transforming the descriptor the previous one produced (last write
                // at a JSONPath target wins). That is a chain, not an enumeration.
                registerHint: 'resolve the singleton and register(new RealmOverlay(realmKey: ...)) from your provider — enriches only, never creates a realm',
                where: RealmOverlayRegistry::class,
                package: $pkg, order: 17,
            ),
            new ManifestDescriptor(
                name: 'RealmResourceRegistry',
                of: 'per-realm presentation OVERRIDES merged into a resource declaration as it is projected for a realm',
                seam: ManifestSeam::ConfigSource,
                arity: ManifestArity::ComposeMany,
                // ConfigSource over SingletonAccumulator: the binding hydrates it from
                // `frame.realm_resource_overrides` at resolution, so config is the seam a host reaches for;
                // `->override()` is the fluent escape hatch the class docblock calls it. ComposeMany because
                // apply() walks the realm's `[...stack, self]` chain bottom→top and merges each overlay in
                // turn — the requested realm's own overlay wins by being last, not by being picked.
                registerHint: 'add `<realm>.<resourceKey>.<field>` entries to frame.realm_resource_overrides (or ->override($key, $realm, $override) imperatively)',
                where: 'config frame.realm_resource_overrides → '.RealmResourceRegistry::class,
                package: $pkg, order: 18,
            ),
            new ManifestDescriptor(
                name: 'CapabilityRegistry',
                of: 'external capabilities by key + entitlement (web search, schema-LLM migration, node types)',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::PickOne,
                registerHint: 'resolve the singleton and register(ExternalCapability) from your provider',
                where: CapabilityRegistry::class,
                package: $pkg, order: 15,
            ),
            new ManifestDescriptor(
                name: 'ParticleWriter (write chain)',
                of: 'the write pipeline: authorize → validate → persist → emit',
                seam: ManifestSeam::PipelineChain,
                arity: ManifestArity::ComposeMany,
                registerHint: 'construct ParticleWriter with an ordered $stages list to insert a WriteStage pipe',
                where: ParticleWriter::class,
                package: $pkg, order: 20,
            ),
            new ManifestDescriptor(
                name: 'PayloadParticleReader (read chain)',
                of: 'the read/projection pipeline (default single ProjectStage); coarser seam is the ParticleHydrator port',
                seam: ManifestSeam::PipelineChain,
                arity: ManifestArity::ComposeMany,
                registerHint: 'construct PayloadParticleReader with an ordered $stages list to insert a ReadStage pipe',
                where: PayloadParticleReader::class,
                package: $pkg, order: 21,
            ),
        ] as $descriptor) {
            $index->describe($descriptor);
        }
    }

    /**
     * Boot-time #[ParticleResource] discovery into beam's singleton {@see ParticleResourceRegistry}.
     *
     * The explicit `resources.classes` list is ALWAYS honoured (it is cheap). The discover-path SCAN is
     * where the cost lives, so it is cached: when the {@see FrameResourceManifest} exists (a host ran
     * `splicewire:beam:frame:cache`), boot registers the cached class-strings directly — no `Finder` walk. Only when
     * the cache is absent (dev) does it fall back to a live scan. Reads `beam.core.resources.classes` /
     * `.discover_paths`, falling back to frame's legacy `frame.resources` / `frame.discover_paths` keys.
     */
    protected function discoverResources(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);

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
