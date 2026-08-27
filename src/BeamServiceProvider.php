<?php

namespace Splicewire\Beam;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Rushing\DataFilters\Contracts\ResourceModelResolver;
use Rushing\Doctor\DoctorAudit;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Rushing\Popcorn\Registries\Registrars\AttributeRegistrar;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Surgeon\Audit\PackageGraph;
use Rushing\Surgeon\Operation\CallbackConformanceManifest;
use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\Operation\Operation;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Rushing\Versioning\Contracts\RecordReconciler;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\Frame\Contracts\FrameFilterProvider;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Schemastud\Frame\Contracts\ResourceContextContributor;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Realm\RealmDefinition;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\GuardActorAdapter;
use Splicewire\Beam\Concerns\BootsBeamRouteNamespace;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Console\BeamSeedCommand;
use Splicewire\Beam\Console\DocblockCommand;
use Splicewire\Beam\Console\FrameCacheCommand;
use Splicewire\Beam\Console\FrameClearCommand;
use Splicewire\Beam\Console\GenerateAssetsCommand;
use Splicewire\Beam\Console\GenerateClientSdkCommand;
use Splicewire\Beam\Console\GenerateContributedTypesCommand;
use Splicewire\Beam\Console\HouseStyleCommand;
use Splicewire\Beam\Console\MakeParticleOpCommand;
use Splicewire\Beam\Console\MakeParticleResourceCommand;
use Splicewire\Beam\Console\RegistryConformanceCommand;
use Splicewire\Beam\Console\UndeclaredSurfaceCommand;
use Splicewire\Beam\Data\BeamSchemaData;
use Splicewire\Beam\Doctor\AgentsMdConventionAudit;
use Splicewire\Beam\Doctor\BeamCoreMigrationsAudit;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\ConfigFacadeReferenceAudit;
use Splicewire\Beam\Doctor\DeadConfigKeyAudit;
use Splicewire\Beam\Doctor\KeyTypeConformanceAudit;
use Splicewire\Beam\Doctor\LedgerAheadOfRepositoryAudit;
use Splicewire\Beam\Doctor\MigrationOrderingAudit;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Doctor\RetiredMigrationAudit;
use Splicewire\Beam\Doctor\ScribeOutputContractAudit;
use Splicewire\Beam\Doctor\StubStaticReferenceAudit;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Doctor\Support\TrackerTicketStatus;
use Splicewire\Beam\Doctor\TestRunnerConformanceAudit;
use Splicewire\Beam\Doctor\UndeclaredRegistryShapeAudit;
use Splicewire\Beam\Doctor\UngatedOperationAudit;
use Splicewire\Beam\Doctor\UnguardedCreateAudit;
use Splicewire\Beam\Doctor\UnrehearsableStubAudit;
use Splicewire\Beam\Entitlements\EntitlementGate;
use Splicewire\Beam\Events\BeamEventRegistrar;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Events\HookEventRegistrar;
use Splicewire\Beam\Events\ParticlePersistedEventRegistrar;
use Splicewire\Beam\Events\ResourceKeyOracle;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver;
use Splicewire\Beam\Frame\FrameResourceManifest;
use Splicewire\Beam\Frame\NullFrameFilterProvider;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\Middleware\HoneypotMiddleware;
use Splicewire\Beam\Http\OpenApiSpecController;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\MigrationFiles;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\OpenApi\ConfiguredArtifactSpecSource;
use Splicewire\Beam\OpenApi\OpenApiSpecSource;
use Splicewire\Beam\Ownership\Contracts\OwnershipEdgeStore;
use Splicewire\Beam\Ownership\EloquentOwnershipEdgeStore;
use Splicewire\Beam\Ownership\OwnershipGraph;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Particle\Contribution\ContributionContextNodes;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\DeadResolvingHookGuard;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\Mount\ParticleMountManager;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;
use Splicewire\Beam\Particle\ParticleResourceModelResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Realm\ConfigTenantResolver;
use Splicewire\Beam\Realm\Contracts\TenantResolver;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Splicewire\Beam\Rendering\Subjects\FindOrFailSubjectResolver;
use Splicewire\Beam\Rendering\Subjects\ResolvesRenderingSubject;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Source\Contracts\ForeignSourceProjector;
use Splicewire\Beam\Source\LadderForeignSourceProjector;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Source\ParticleShadower;
use Splicewire\Beam\Source\RouteManifestSourceRegistry;
use Splicewire\Beam\Storage\GitRepoRegistrar;
use Splicewire\Beam\Surface\GroupRegistry;
use Splicewire\Beam\Surface\OpenApiSpecCorroborator;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Splicewire\Beam\Surgeon\AuditScanPaths;
use Splicewire\Beam\Surgeon\BareParticleMountAudit;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\CentralPinResolvabilityAudit;
use Splicewire\Beam\Surgeon\ClientRuntimeContractAudit;
use Splicewire\Beam\Surgeon\ComposedTableConfigAudit;
use Splicewire\Beam\Surgeon\DocblockTierAudit;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Splicewire\Beam\Surgeon\MorphAliasCoverageAudit;
use Splicewire\Beam\Surgeon\ParticleControllerRedundancyAudit;
use Splicewire\Beam\Surgeon\ParticleOperationBypassAudit;
use Splicewire\Beam\Surgeon\ParticleWriteBypassAudit;
use Splicewire\Beam\Surgeon\SchemaProjectionDriftAudit;
use Splicewire\Beam\Surgeon\SdkEndpointDriftAudit;
use Splicewire\Beam\Surgeon\SdkHookMigrationAudit;
use Splicewire\Beam\Surgeon\SdkHookMigrationBridge;
use Splicewire\Beam\Surgeon\SdkNameConventionAudit;
use Splicewire\Beam\Surgeon\SdkReturnsCoverageAudit;
use Splicewire\Beam\Surgeon\SdkReturnsTypeScriptResolutionAudit;
use Splicewire\Beam\Surgeon\StatusChannelLiteralDriftAudit;
use Splicewire\Beam\Surgeon\Support\PackageOrigin;
use Splicewire\Beam\Surgeon\TablePrefixBypassAudit;
use Splicewire\Beam\Surgeon\TypeScriptShortNameCollisionAudit;
use Splicewire\Beam\Surgeon\TypeScriptUnknownResolutionAudit;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Surgeon\UndeclaredWriteMapAudit;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;
use Splicewire\Beam\Webhooks\HookSubjectPruner;
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
class BeamServiceProvider extends PackageServiceProvider implements ChainsTraitMethodsContract
{
    use BootsBeamRouteNamespace;
    use ChainsTraitMethods;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam')
            // Nested config namespace (beam-write-pipeline ticket 07): publishes config/beam/core.php,
            // merged + read as config('beam.core.*'). Never a flat config/beam.php — having both a
            // beam.php file and a beam/ dir is the one real Laravel footgun this move avoids.
            // `beam/core.php` → `beam.core.*`; `beam/client.php` → `beam.client.*` (the promoted client-SDK
            // codegen's config: out_dir, emit_stores, the http-client/route module specifiers, sources).
            // `webhooks` is the one deliberately FLAT config beam publishes — the outbound
            // webhook edge rehomed here from tower (api-surface-coherence 37), whose keys
            // `webhooks.outbound.{tries,backoff,timeout}` are a stated contract of that
            // ticket. The beam.php-vs-beam/ footgun the nesting rule exists for cannot be
            // caused by a file named `webhooks.php`; see config/webhooks.php's own note.
            ->hasConfigFile(['beam/core', 'beam/client', 'webhooks'])
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
                // spatie/laravel-activitylog's table, squashed here from the former central-only
                // `central_activity_log` + tenant-only `activity_log` pair (the same consolidation
                // tower already did for model-status' `statuses`). Homed alongside
                // {@see \Splicewire\Beam\Models\CentralActivityLog}, which core owns because its
                // subjects span beam-accounts and beam-tenancy and its consumers span tower and
                // beam-workflows. PUBLISH ORDER MATTERS: `lunarphp/core` ships a fixed-date
                // `2026_01_01_900001` copy with an incompatible BIGINT-morph shape, so the published
                // copy must carry a `0001_01_01_*` filename to win the race — see the stub's docblock.
                'shared/create_activity_log_table',
                // The GitRepo cache table (mirror-status-ui ticket 02) — promoted here from
                // laravel-beam-ux via surgeon:move (a repo-status cache is generic infra, not a
                // beam-ux-specific concept), so it ships under the same convention as every other
                // core table.
                'shared/create_beam_git_repos_table',
                // The hook subscription table (api-surface-coherence 38, decided by 12). SHARED because
                // a hook is meaningful in both passes: the operator realm subscribes to platform events
                // centrally, a tenant subscribes to its own. Same one-file-both-passes shape as every
                // other core table, not a duplicated flat+tenant DDL pair.
                'shared/create_beam_hooks_table',
            ]);
    }

    public function packageRegistered(): void
    {
        // Core pins a LITERAL connection name on {@see \Splicewire\Beam\Models\CentralActivityLog};
        // make that name resolve everywhere before anything can resolve the model.
        // {@see self::registerCentralConnectionAlias()}
        $this->registerCentralConnectionAlias();

        // The Beam instance (beam-facade ticket 05) — the object the Splicewire\Beam\Facades\Beam facade
        // proxies to. FIRST, because this provider itself calls Beam::table(...) a few lines down and the
        // facade resolves through this binding.
        //
        // `scoped()`, not `singleton()`: the surface is lookup-only today (every accessor re-reads config
        // per call), so the two are indistinguishable right now — including in the console/queue contexts
        // beam actually runs in (`beam:install`, `beam:doctor`, `beam:seed`, codegen), which have exactly
        // one scope. It costs nothing and pre-pays for ambient state, whereas registering `singleton()` and
        // promoting later would mean touching the binding at precisely the moment the Octane/queue leak
        // would otherwise be introduced.
        $this->app->scoped(BeamManager::class, fn ($app) => new BeamManager($app));

        // The particle mount surface (api-surface-coherence ticket 49) — the object the
        // Splicewire\Beam\Facades\Particle facade proxies to, plus the single mount implementation both
        // it and the `Route::particle*()` macros drive.
        //
        // `ParticleMounter` is a `singleton` because it is behaviour plus ONE piece of state that has to
        // be shared across every mount in the app — its `bindingClaims()` ledger of the app-global route
        // bindings a relative mount registers (api-surface-coherence ticket 51 §1). The manager is
        // `scoped` on BeamManager's own reasoning, and additionally because it holds the Router — which
        // an Octane worker re-resolves per request.
        $this->app->singleton(ParticleMounter::class);

        $this->app->scoped(ParticleMountManager::class, fn ($app) => new ParticleMountManager(
            $app['router'],
            $app->make(ParticleMounter::class),
        ));

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
        //
        // `beam_schema` and `beam_submission` join it here. Both models already PINNED those tokens
        // from their own `getMorphClass()` overrides, but neither was ever registered — so the pin
        // was write-side only. That half-registration is a real gap, not a stylistic one:
        // `EloquentVersionStore` writes `versionable_type = $model->getMorphClass()` and
        // `Version::versionable()` is a `morphTo()`, so a token with no read-side entry produces a
        // row nothing can resolve back; and the reverse lookup several packages perform —
        // `array_search($class, Relation::$morphMap)` (TextEmbeddingsService, CompleteRequestLogListener,
        // ReplayNotificationStatus) — silently misses a write-side-only alias. No `*_type` column
        // stores either token today, so registering them is additive and safe; it closes the gap
        // before something does.
        // `hook` joins them (api-surface-coherence 38). It is not a nicety here: a hook carries TWO
        // polymorphic columns of its own (`subject_*`, `owner_*`), `hooks.disabled` / `hooks.verified`
        // declare `Hook::class` as their catalog subject, and `GET /hooks/events` publishes that subject
        // to every subscriber. Without an alias all three of those write and publish
        // `Splicewire\Beam\Models\Hook` — the package's internal namespace, on the wire, in a column,
        // and in a permission-token prefix (ADR-0118), which makes the class un-renameable by anything
        // short of a data migration. `splicewire:beam:doctor` reported exactly this.
        Relation::morphMap([
            'beam_particle' => BeamParticle::class,
            'beam_schema' => BeamSchema::class,
            'beam_submission' => BeamSubmission::class,
            'hook' => Hook::class,
        ]);

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
        //
        // The generator is the CONTAINER'S, not a hand-built `JsonSchemaGenerator`. That construction
        // was correct on config and blind on dispatch: `data-schemas.generators` is a LIST and the rule
        // "the first member whose canGenerate() accepts this class" lives only inside `ChainedGenerator`,
        // so at a multi-generator host (~/Herd/thingsontv) this bound the WRONG member. That matters more
        // here than anywhere else in this sweep, because this feeds SchemaLadderMigrator's migrate-on-read
        // for BeamParticle — the wrong generator does not produce a missing doc, it produces a target
        // schema the record is then diffed against and MIGRATED INTO. Silently wrong persisted data.
        //
        // Resolved lazily inside the closure, so the data-schemas provider's own binding is in place by
        // the time anything makes a RecordReconciler — this is register(), and nothing is resolved here.
        // The refusal path is guarded inside the adapter (per-class, which is the only place
        // `canGenerate()` can be asked); see the long note on `SchemaLadderMigrator::targetSchema()`.
        $this->app->bind(RecordReconciler::class, fn ($app) => new SchemaLadderMigrator(
            $app->make(SchemaRegistry::class),
            $app->make(Generator::class),
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
        // the genuinely stateless {@see ParticleResourceRegistryAdapter} forwarder — it exists only because
        // PHP has no overloading (ParticleResourceRegistry's REST-facing `get(): ParticleResource` and the
        // port's `get(): ResourceDefinition` can't share a method name on one class), so every call passes
        // straight through to ParticleResourceRegistry's differently-named projection methods. A SINGLETON
        // so boot-time #[ParticleResource] discovery (packageBooted) persists across the request. Frame's
        // manifest machinery reads the port; it never imports this beam type (arrow points DOWN, beam → frame).
        $this->app->singleton(ParticleResourceRegistryAdapter::class);
        $this->app->alias(ParticleResourceRegistryAdapter::class, ResourceRegistry::class);

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
        // The cross-package contribution registry (particle-contribution-seam ticket 04) — where a package
        // that owns a concern declares THAT concern's named slice of another package's read projection.
        // Bound in the REGISTER phase, like every other particle registry here, and that is load-bearing:
        // provider boot order is alphabetical and permanent, so a contributor (beam-commerce, position 4)
        // boots BEFORE the owner it contributes to (beam-tenancy, 13). Binding here makes `bound()` true
        // for every package at boot whatever the order, so the direct `make()->register()` idiom ticket 07
        // established is order-safe by construction. Ships EMPTY — inert until a package contributes.
        $this->app->singleton(ResourceContributionRegistry::class);

        // Frame's OPTIONAL plug port, implemented here so a host binds nothing (ticket 19 / 17 §A1).
        // It is what makes a contributed slice's `#[Column]`s reach the Frame manifest: the manifest
        // reflects ONE Data class, a slice is another, so without this the columns are declared and
        // invisible. Frame learns only THAT a key has extra participation — never that contributions
        // exist — which is what keeps the narrowing ticket 10 landed intact.
        $this->app->singleton(ResourceContextContributor::class, fn ($app) => new ContributionContextNodes(
            $app->make(ResourceContributionRegistry::class),
        ));

        $this->app->singleton(ParticleResourceRegistry::class, fn ($app) => (new ParticleResourceRegistry(
            $app->make(RealmResourceRegistry::class),
            $app->make(ResourceContributionRegistry::class),
        ))->loadRealmMap((array) config('frame.realms', [])));
        $this->app->singleton(ParticleOperationRegistry::class);
        // The relative-edge registry (api-surface-coherence ticket 50) — the op registry's twin in shape
        // as well as in role, so the two migrate as one archetype when registry-kernel's
        // per-resource-registry sweep reaches beam.
        $this->app->singleton(ParticleRelativeRegistry::class);

        // The host's API taxonomy and the chain that resolves a route into it (api-surface-coherence 17).
        // Beam ships the registry EMPTY on purpose: a taxonomy belongs to the host, and seeding this
        // estate's roots from core would ship one host's engine names to every beam site — the mistake the
        // glob map it replaces warned about in its own header. A fresh beam host still groups its reference
        // correctly, off its declared particle resources, with no taxonomy config at all.
        $this->app->singleton(GroupRegistry::class, fn ($app) => new GroupRegistry(
            $app->make(ParticleResourceRegistry::class),
        ));
        $this->app->bind(ResponseEnvelope::class, ArrayResponseEnvelope::class);

        // data-filters' model-resolver port (its ADR-0008). The foundation package declares the seam
        // and deliberately binds it NOWHERE — beam is the tier that knows ParticleResourceRegistry
        // exists, so beam supplies the adapter. What it buys: a `#[ResourceFilter]` may omit `model:`
        // and have it resolved from the `#[ParticleResource]` already declared under the same key,
        // instead of restating it. `bind`, not `singleton` — it is a stateless lookup over a registry
        // that is itself the singleton.
        $this->app->bind(ResourceModelResolver::class, ParticleResourceModelResolver::class);

        // The per-resource rendering registry `Route::resourceRenderings()` enumerates (moved from
        // laravel-composition-engine into beam core). A SINGLETON for the same reason the particle
        // registries are: the route macro (defined in packageBooted below) must see the same instance a
        // package's own provider registered a rendering into. The default subject resolver is the
        // duck-typed `findOrFail`/`with` pair; a host on anything else binds its own.
        // Constructed EMPTY: `beam.core.renderings` reaches it through a ConfigRegistrar attached in
        // beam's own boot() (registry-kernel ticket 53), not through the constructor. Seeding here would
        // put the fill at FIRST RESOLVE — the lazy-on-first-read shape ticket 07 D9 rejected, and the
        // one ticket 19 D3 says re-opens 19 if an owner is caught doing it.
        $this->app->singleton(ResourceRenderingRegistry::class, fn () => new ResourceRenderingRegistry);
        $this->app->bind(ResolvesRenderingSubject::class, FindOrFailSubjectResolver::class);

        // The publishable-event catalog (api-surface-coherence ticket 40). A SINGLETON for the same
        // reason the particle registries are: a package provider registering an event type must land in
        // the instance `GET /hooks/events` reads. Constructed EMPTY — its registrars attach in beam's own
        // boot(), after resource discovery, because every registration validates its prefix against the
        // live resource keys and a fill at first-resolve would validate against whatever was registered
        // by then (registry-kernel ticket 07 D9, ticket 19 D3).
        $this->app->singleton(ResourceKeyOracle::class, fn ($app) => new ResourceKeyOracle($app));
        $this->app->singleton(EventTypeRegistry::class, fn ($app) => new EventTypeRegistry(
            $app->make(ResourceKeyOracle::class),
        ));

        // particle-doctrine-convergence ticket 09: the cross-transport ability resolver + its ACTOR port.
        // `bindIf` on the port, because "who is acting" is the transport's answer to give: HTTP has the
        // guard (the default bound here — the only place ambient auth is read), while a transport with no
        // ambient user (MCP over stdio) binds its own. The resolver itself is transport-blind and stateless.
        $this->app->bindIf(ActorPort::class, GuardActorAdapter::class);
        $this->app->bind(AbilityResolver::class);

        // The client-SDK codegen's default tenant source: reads mounted particle routes off the live route
        // table (the `router` singleton) + resolves each route's output DTO via the resource registry (a CRUD
        // verb) or the operation registry (a named op's declared `output:` slot).
        $this->app->bind(ParticleRouteManifestSource::class, fn ($app) => new ParticleRouteManifestSource(
            $app->make('router'),
            $app->make(ParticleResourceRegistry::class),
            $app->make(ParticleOperationRegistry::class),
        ));

        // The declaration `config('beam.client.sources')` never had. `RouteManifestSource` is an
        // interface over a realm-keyed config array, so registry-kernel ticket 21 had no class to hang
        // `#[IsRegistry]` on and left this beam-core registry `deferred` — the one descriptor of
        // nineteen with no home. The adapter closes it without moving the config key or touching a
        // single consumer (registry-kernel tickets 08 D6, 25).
        $this->app->singleton(RouteManifestSourceRegistry::class);

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

        // The audit scan-path contribution registry: a singleton any family package's provider pushes
        // `(controllersDir, routesDir)` pairs into so its HTTP surface joins the bypass/redundancy/
        // house-style sweeps alongside the host dirs — SchemaSources' idiom turned on audit scope.
        // Registered UNCONDITIONALLY (outside the surgeon-installed guard below): contributors push
        // during boot regardless of whether a sweep ever runs here.
        $this->app->singleton(AuditScanPaths::class);

        // GitRepoRegistrar (mirror-status-ui ticket 02, promoted here via surgeon:move): its own
        // in-process cache (N calls for the same repo within one request skip both the DB and the
        // shell-out) only helps if the container hands back the SAME instance — an explicit singleton,
        // not reflection-resolved fresh on every `make()`.
        $this->app->singleton(GitRepoRegistrar::class);

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

        // The OpenAPI spec-source seam (ADR-0211 §3). Beam ships ONE spec, read off the configured
        // artifact — the VARIANCE lives in this binding, not in the artifact. A host that wants
        // per-capability specs pre-generates them (`scribe:generate --config=<name>`) and rebinds this
        // contract; the route, the docs page, and <ApiReference> never learn about it. A plain bind, so a
        // host's own register() — which runs after every package's — simply wins.
        $this->app->bind(OpenApiSpecSource::class, ConfiguredArtifactSpecSource::class);

        // The index of indexes has MOVED to `Rushing\Popcorn\Registries\RegistryIndex`, bound by
        // `laravel-popcorn`'s own provider (registry-kernel tickets 04, 20, 21). Beam does not bind it: the
        // kernel owns the primitive, and a second binding here would be the estate deciding a registry's
        // lifetime from outside the package that owns it.

        $this->registerSurgeonAudits();
        $this->registerFacadeConformanceAudits();
        $this->registerRegistryConformanceAudits();
        $this->registerConformanceManifest();
    }

    /**
     * Make `central` resolve at a host that never defined it, by registering it as a copy of the
     * host's DEFAULT connection.
     *
     * HOMED IN CORE, moved here from beam-accounts by beam-facade ticket 96. The ruling is about
     * package layering, not about `central`: **the tier that DECLARES a pin owns making that pin
     * resolvable**, and core declares one — {@see CentralActivityLog} pins
     * `protected $connection = 'central'` (`@central-floor auth`), for the same reason the model
     * itself lives in core rather than in any one engine (its subjects span beam-accounts and
     * beam-tenancy, its consumers span tower and beam-workflows; core is the only tier all of them
     * already depend on). So this is not an auth-shaped default pushed into the layer below auth —
     * it serves core's OWN pin, and every other pin in the family inherits it for free.
     *
     * That inheritance is total, and it is what made the accounts home look sufficient for three
     * days. The estate's 20 live pins sit in six packages, and the four beyond core and accounts all
     * reach beam-accounts TRANSITIVELY — beam-commerce → beam-tenancy → beam-accounts, beam-embed →
     * beam-commerce/beam-tenancy → beam-accounts, tower directly. 96's census measured DIRECT
     * requires only and therefore read 7 pins as uncovered; the true uncovered surface was exactly
     * ONE, this package's, because core is the only tier that requires nothing.
     *
     * That one pin was live, not hypothetical (measured 2026-08-26): `CentralActivityLog::query()`
     * threw `InvalidArgumentException: Database connection [central] not configured.` at FIVE Herd
     * hosts installing beam-core without beam-accounts — beam-pilot-gcp-cloud-run, calcucrypt,
     * entreport, stephenrushing, thingsontv. A sixth, standwell, resolved only because it hand-copies
     * a `central` block, which is the drift-prone precedent this alias exists to retire. Leaving them
     * unaliased would have meant ruling those hosts misconfigured, and beam-facade ticket 26 already
     * ruled beamless / git-resolved site shapes SUPPORTED.
     *
     * Deliberately an ALIAS registered by the package, not a host-side duplicated connection block
     * (the standwell / splicewire-app precedent): Laravel has no connection aliasing, so every
     * hand-copied block drifts independently from the default it is supposed to mirror, and adopting
     * it means a 10-root retrofit plus a permanent scaffold obligation in four starters. Also
     * deliberately NOT a `getConnectionName()` override reading config — that converts the pin from a
     * property into a method, and `CentralPinJustificationAudit`'s `FORM_PROPERTY` stops matching it,
     * manufacturing the exact "a pin that does not look like a pin" failure the audit was built for.
     *
     * A multi-tenant broker that defines its own `central` block wins and is untouched. A
     * single-tenant host changes nothing and `central === default` silently. Runs in `register()`
     * (not boot) so the alias is in place before any provider's boot phase can resolve a model.
     *
     * Two no-op guards, both for hosts where a copy would be a lie rather than an alias: a host whose
     * `database.default` IS `central` has nothing to copy FROM (the missing block is the default block
     * — a real misconfiguration, and its own error message is more useful than ours), and a host whose
     * default connection has no config block at all is broken independently of this package.
     */
    protected function registerCentralConnectionAlias(): void
    {
        if (config('database.connections.central') !== null) {
            return;
        }

        $default = config('database.default');

        if ($default === null || $default === 'central') {
            return;
        }

        $block = config("database.connections.{$default}");

        if (! is_array($block)) {
            return;
        }

        config(['database.connections.central' => $block]);
    }

    /**
     * The facade-conformance regime (beam-facade tickets 10 and 19): five single-purpose audits that keep
     * the four-method surface from un-collapsing, split across two substrates because **each substrate can
     * only do half the job**. Surgeon hardcodes the `php` extension in `AuditEngine::phpFilesIn()` and is
     * structurally blind to the `.php.stub` population one of the checks is entirely about; a plain
     * {@see DoctorAudit} is text-level and cannot answer the position-of-hit question that
     * ticket 04's `SchemaTargetResolver` rejection turns on. So the stub and config checks are doctor-side
     * and unconditional, and the three shape checks are surgeon-side behind the same
     * `interface_exists()` guard every other surgeon audit here uses.
     *
     * ## One registration block, five siblings, all advisory
     * The estate's ~30 audits are single-purpose with one check key each, and this follows that: five keys,
     * five `order` slots, each independently promotable to `gate: true` later. **None gates.**
     *
     * **Six as of beam-facade ticket 30** — {@see UnguardedCreateAudit} joined the block without being a
     * facade check at all, because what it shares with the five is the *scope*, which is the expensive
     * part. Read the regime as "beam's static, host-scoped conformance checks" rather than as five things
     * about the facade; the same is already true of {@see KeyTypeConformanceAudit} below. Precedent is
     * lopsided — two audits in the estate are `gate: true`, both in {@see registerRegistryConformanceAudits()}
     * and both carrying an in-code justification for the exception — and the specific argument here is that
     * ticket 10's census measured 238 naive flags across 16 repos on day one. That is how a check gets its
     * floor bumped and then deleted. Gating stays available to *callers*: ticket 07 made the old bridge
     * audit a gate on every sweep unit without it being registered as one.
     *
     * **This is what supersedes `StaticBridgeAudit`**, which ticket 18 deleted with the bridge itself.
     * Registering the replacement in the same release is what keeps the estate from ever being without a
     * facade signal — the sequencing ticket 10 §6 chose when it put this after the cutover.
     *
     * The {@see FacadeConformanceScope} is a **singleton** on purpose: it memoizes one filesystem walk that
     * four of the five audits share. Doctor already OOMs at the default 128 MB `memory_limit` inside
     * `HouseStyleStripOperation::plan()` (ticket 18 §5), so five independent walks of a host's tree plus
     * every overlaid family package was not an acceptable cost. The scope also does the cheap prefilter, so
     * only a couple of dozen files estate-wide are ever parsed.
     */
    protected function registerFacadeConformanceAudits(): void
    {
        $this->app->singleton(FacadeConformanceScope::class, fn () => FacadeConformanceScope::forApp());

        // Doctor side — unconditional. A stub that names the deleted bridge breaks a host at publish time
        // whether or not that host ever installed surgeon, and a config template that calls the facade
        // fatals at `config:cache` in any host at all.
        $this->app->bind(StubStaticReferenceAudit::class, fn ($app) => new StubStaticReferenceAudit(
            $app->make(FacadeConformanceScope::class),
        ));
        $this->app->bind(ConfigFacadeReferenceAudit::class, fn () => ConfigFacadeReferenceAudit::forApp());

        // The sixth audit (beam-facade tickets 22 Q8a and 30). Not a facade check — it watches the
        // migration-collision family that 27–29 closed at its mechanism — but it shares this scope: the
        // memoized walk, the symlink resolution-mode rule and the published-copy exclusion are the same
        // rules, and a second walk is the one cost 19's construction was most careful about. Doctor-side
        // and unconditional for StubStaticReferenceAudit's reason: the population is `.php.stub`, which
        // surgeon cannot see, and an unguarded create damages a host whether or not it installed surgeon.
        $this->app->bind(UnguardedCreateAudit::class, fn ($app) => new UnguardedCreateAudit(
            $app->make(FacadeConformanceScope::class),
        ));

        // The key-type conformance check is unconditional and NOT part of the facade regime — it is
        // registered here only because this is where beam's static, host-scoped schema checks live. It
        // reports a defect already present in a schema rather than a style drift: a primary key and the
        // model that reads it disagreeing, a foreign key that will not constrain, or an identity table
        // keyed against the estate's uuid convention. Advisory like the rest, but a finding here is worth
        // acting on the day it appears.
        $this->app->bind(KeyTypeConformanceAudit::class, fn () => KeyTypeConformanceAudit::forApp());

        // A `config()` read whose ROOT nothing loaded resolves to null, silently and forever. The estate
        // has produced that bug four times in four packages (beam-commerce's webhook route, beam-mdx's
        // whole suite, beam-workflows' BootTest, and — the argument for a mechanical check over a
        // convention — beam-commerce's own REGRESSION TEST for the first occurrence, which kept passing
        // through the second because it set the same wrong key the broken route read). The predicate is
        // runtime truth rather than a naming rule: it asks the config repository which roots are loaded,
        // so it needs no list to maintain and cannot go stale as packages are added.
        $this->app->bind(DeadConfigKeyAudit::class, fn () => new DeadConfigKeyAudit);

        // The other half of the convergence story, and the one no instrument reported until now
        // (beam-facade ticket 109). `ConvergencePreflight` refuses to rehearse a body it cannot prove is
        // a pure convergent declaration — correctly, since a rehearsal neutralises convergent guards and
        // NOTHING else — but that refusal was only visible to an operator mid-install, and there is no
        // report-only entry point to the preflight. So "how much of this host is unmeasured" was a number
        // each session rediscovered by installing. Both instruments now hold ONE predicate
        // ({@see RehearsalSafety}); a second copy would drift, which is the mistake 28 already paid for.
        // Advisory, permanently: whether a raw-DDL stub is published HERE is a fact about the host's
        // composition, and the DDL itself is ruled legitimate.
        $this->app->bind(UnrehearsableStubAudit::class, fn ($app) => new UnrehearsableStubAudit(
            MigrationFiles::pathsFor($app),
        ));

        $manifest = $this->app->make(BeamDoctorManifest::class);
        $manifest->register('splicewire/laravel-beam', KeyTypeConformanceAudit::class);
        $manifest->register('splicewire/laravel-beam', DeadConfigKeyAudit::class);
        $manifest->register('splicewire/laravel-beam', StubStaticReferenceAudit::class);
        $manifest->register('splicewire/laravel-beam', ConfigFacadeReferenceAudit::class);
        $manifest->register('splicewire/laravel-beam', UnguardedCreateAudit::class);
        $manifest->register('splicewire/laravel-beam', UnrehearsableStubAudit::class);
        // An operation whose `ability:` is `null` is UNDECLARED, not decided — the residue
        // particle-operation-surface ticket 03 named and could not close in one act without 403ing
        // fourteen shipped endpoints. Registry-side rather than static: the question is what THIS host
        // mounted, and the count is the gate the `null` → derived-permission-name flip waits on.
        $manifest->register('splicewire/laravel-beam', UngatedOperationAudit::class);
        // Joins the install manifest (package → order) against every package's migration stubs
        // (table → created/altered), so a cross-package ALTER declared ahead of its CREATE is caught
        // at boot rather than by a failed greenfield migrate.
        $manifest->register('splicewire/laravel-beam', MigrationOrderingAudit::class);

        // Surgeon side — the three position-sensitive shapes, which need the AST. A host that composes beam
        // without `rushing/laravel-surgeon` autoloads nothing here and pays nothing; SurgeonWiringAudit
        // already instruments that case, so the silence is reported rather than mysterious.
        if (! interface_exists(SuggestsOperations::class)) {
            return;
        }

        $this->app->bind(ParticleWriteBypassAudit::class, fn ($app) => new ParticleWriteBypassAudit(
            $app->make(FacadeConformanceScope::class),
        ));
        $this->app->bind(ComposedTableConfigAudit::class, fn ($app) => new ComposedTableConfigAudit(
            $app->make(FacadeConformanceScope::class),
        ));
        $this->app->bind(TablePrefixBypassAudit::class, fn ($app) => new TablePrefixBypassAudit(
            $app->make(FacadeConformanceScope::class),
        ));

        $manifest->register('splicewire/laravel-beam', ParticleWriteBypassAudit::class);
        $manifest->register('splicewire/laravel-beam', ComposedTableConfigAudit::class);
        $manifest->register('splicewire/laravel-beam', TablePrefixBypassAudit::class);
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
        // House-style scans the app base path PLUS every package-contributed scan pair — a contributed
        // controllers/routes dir is family-owned source and rides the same forbidden-construct sweep.
        $this->app->bind(HouseStyleAudit::class, fn ($app) => new HouseStyleAudit(array_values(array_unique([
            $app->basePath(),
            ...$app->make(AuditScanPaths::class)->controllersDirs(),
            ...$app->make(AuditScanPaths::class)->routesDirs(),
        ]))));
        $this->app->bind(SdkEndpointDriftAudit::class, fn () => SdkEndpointDriftAudit::forClientPackage());
        $this->app->bind(SdkNameConventionAudit::class, fn () => SdkNameConventionAudit::forClientPackage());
        $this->app->bind(ParticleControllerRedundancyAudit::class, fn () => ParticleControllerRedundancyAudit::forRoutes());
        $this->app->bind(ParticleOperationBypassAudit::class, fn () => ParticleOperationBypassAudit::forRoutes());
        // The bare-mount census scans `routes/` AND `app/` — half the estate's `Route::particle*()` call
        // sites are in a service provider, not a route file (api-surface-coherence 93).
        $this->app->bind(BareParticleMountAudit::class, fn () => BareParticleMountAudit::forRoutes());
        // The write-map burn-down reads the two DECLARATION registries rather than scanning source — the
        // slot it audits is a registered value, not a syntactic one, so there is nothing for a parser to do.
        $this->app->bind(UndeclaredWriteMapAudit::class, fn ($app) => new UndeclaredWriteMapAudit(
            $app->make(ParticleResourceRegistry::class),
            $app->make(ParticleOperationRegistry::class),
        ));
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
        // The negative-space detector reads the two particle registries and the LIVE route table.
        // ~~no host-scoped file paths, so it needs no `forApp()` seam of its own~~ — it takes one now
        // (beam-facade ticket 140). Its ARTIFACT is committed, and a committed artifact cannot carry
        // absolute per-machine paths, nor a count that moves with whether dev dependencies are installed.
        // Both answers come from the host's own `vendor/composer/installed.json`, which is host-scoped by
        // definition; see {@see PackageOrigin} for why composer's manifest and not the path shape.
        $this->app->bind(UndeclaredSurfaceAudit::class, fn ($app) => new UndeclaredSurfaceAudit(
            $app->make(ParticleResourceRegistry::class),
            $app->make(ParticleOperationRegistry::class),
            origins: PackageOrigin::forBasePath($app->basePath()),
        ));
        // The surface-posture projector and the document/runtime composer (soc2-readiness-dogfood 03).
        // The projector CONSUMES the negative-space audit above rather than re-walking the route table:
        // the exemptions, the tiering, and the closure handling are decisions already made there, and a
        // second copy would drift from them. Both are plain services — a mechanism, not a particle.
        $this->app->bind(RuntimeCorroborator::class, fn ($app) => new RuntimeCorroborator(
            $app->make(Router::class),
            $app->make(ParticleResourceRegistry::class),
            $app->make(ParticleOperationRegistry::class),
            $app->make(UndeclaredSurfaceAudit::class),
            (array) config('beam.surface.middleware_signals', []),
        ));
        $this->app->bind(OpenApiSpecCorroborator::class, fn ($app) => new OpenApiSpecCorroborator(
            $app->make(RuntimeCorroborator::class),
        ));
        // The Inertia leg of the same detector. Unlike the HTTP leg above it IS host-scoped and
        // filesystem-bound — `Inertia::render` props live in host source, not in any registry or route table —
        // which is exactly why it is a sibling audit rather than a row-producer inside that one.
        $this->app->bind(InertiaPropShapeAudit::class, fn () => InertiaPropShapeAudit::forApp());
        // The central-pin census scans the host's own code AND the family packages it composes — almost
        // every pin in the estate lives in a package, so an `app/`-only scan would report a comfortable zero
        // from inside every host while the backlog sat one directory over. See the audit's `forApp()`.
        $this->app->bind(CentralPinJustificationAudit::class, fn () => CentralPinJustificationAudit::forApp());
        // The resolvability sibling shares that census exactly — same scope, same rows — so a pin can never
        // appear in one audit's population and not the other's. See the audit on why it is a sibling.
        $this->app->bind(CentralPinResolvabilityAudit::class, fn ($app) => CentralPinResolvabilityAudit::forApp(
            $app->make(CentralPinJustificationAudit::class),
        ));
        $manifest = $this->app->make(BeamDoctorManifest::class);
        $manifest->register('splicewire/laravel-beam', HouseStyleAudit::class);
        $manifest->register('splicewire/laravel-beam', SdkEndpointDriftAudit::class);
        $manifest->register('splicewire/laravel-beam', SdkNameConventionAudit::class);
        $manifest->register('splicewire/laravel-beam', ParticleControllerRedundancyAudit::class);
        $manifest->register('splicewire/laravel-beam', ParticleOperationBypassAudit::class);
        $manifest->register('splicewire/laravel-beam', BareParticleMountAudit::class);
        // Advisory, and it is the burn-down meter that decides when the duck-typed `toModelAttributes()`
        // branch and the snake-case fallback may be deleted (particle-write-surface 03). Not a gate: the
        // fallback it reports is still a supported path, so failing a build over it would be beam gating
        // beam's own documented behaviour — and the POPULATION is a host fact (which resources are
        // registered here) even though each row's verdict is a fact about the declaration.
        $manifest->register('splicewire/laravel-beam', UndeclaredWriteMapAudit::class);
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
        // Advisory, and NOT because the finding is soft — every finding it emits is a `fail`, because an
        // unresolvable pin is a guaranteed runtime fatal with no judgement in it. It is advisory because
        // "does connection [central] exist HERE" is a fact about the HOST, which
        // `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md` names as its textbook advisory
        // case, and because ticket 88 ruled a foundation package may not decide what fails a root's build.
        // A host that wants it to gate registers this class in its own manifest with `gate: true`.
        $manifest->register('splicewire/laravel-beam', CentralPinResolvabilityAudit::class);
        // Advisory: every Eloquent model a family package ships should have its alias registered by that
        // package's OWN provider, not by the host composing it. Not a gate, and for the same reason the
        // others here are not — the scope is every model in every installed family package, which includes
        // many legitimately never used polymorphically (a pivot, a lookup table, timeline's Clip/Track).
        // A gate over that set blocks every host on day one and gets switched off within the hour. The
        // burn-down is one `Relation::morphMap()` line per finding; promote to `gate: true` once it is
        // clear and the permanently-exempt set is decided.
        $manifest->register('splicewire/laravel-beam', MorphAliasCoverageAudit::class);
    }

    /**
     * The registry-conformance regime (registry-kernel ticket 35, from ticket 14) — three audits and one
     * ratchet command, all **doctor-side and unconditional**.
     *
     * ## Unconditional is the fix, not a detail
     *
     * {@see UndescribedRegistryAudit} was registered in {@see registerSurgeonAudits()} until now, which put
     * the estate's only GATE behind `interface_exists(SuggestsOperations::class)` — and
     * `rushing/laravel-surgeon` is a `require-dev` of beam. So the one check the effort was willing to block
     * on was silently absent from every production host: enforcement as a function of host composition,
     * which is ticket 04 D1's defect wearing a different hat, and the reason ticket 14 D7 moved it here.
     * The two ticket-35 audits are registered beside it for the same reason rather than for a different one.
     *
     * They pay the price that goes with it. `nikic/php-parser` arrives through surgeon, so an unconditional
     * audit can run in a host without it — and both of the shape-reading audits report their own blindness
     * there instead of an empty work-list. See {@see UndescribedRegistryAudit::detectionAvailable()}.
     *
     * ## One gate, two advisories, and the line between them
     *
     * {@see RegistryConformanceAudit} gates because its population OPTED IN by declaring `#[IsRegistry]`, so
     * it carries no suppression list of any kind (14 D6). That property only holds because
     * {@see UndeclaredRegistryShapeAudit} exists beside it to carry every judgement call — which is exactly
     * why the second one never gates. Splitting them was ticket 14's central decision; registering them
     * apart is what makes the split real rather than documentary.
     */
    protected function registerRegistryConformanceAudits(): void
    {
        // The meta-audit reads the LIVE index to derive its own scan scope, so it is bound off the container
        // rather than given a static path list — the governed set is whatever has described itself by boot.
        $this->app->bind(UndescribedRegistryAudit::class, fn ($app) => UndescribedRegistryAudit::forIndex(
            $app->make(RegistryIndex::class),
        ));

        // The gate reads the live container binding table plus the index, so its claim is about THIS
        // composition and never about the family (14 D2, D12).
        $this->app->bind(RegistryConformanceAudit::class, fn ($app) => new RegistryConformanceAudit(
            $app,
            $app->make(RegistryIndex::class),
        ));

        // The advisory report consumes the meta-audit's structural test at HOST scope — same verbs, same
        // exclusions, one implementation, a wider net. A second copy of that heuristic would drift.
        $this->app->bind(UndeclaredRegistryShapeAudit::class, fn ($app) => new UndeclaredRegistryShapeAudit(
            UndescribedRegistryAudit::forHost($app->make(RegistryIndex::class)),
            (string) (config('beam.core.registry_conformance.artifact') ?? base_path('.beam/registry-conformance.json')),
            UndeclaredRegistryShapeAudit::DEFAULT_WHITELIST,
            TrackerTicketStatus::fromConfig(),
        ));

        $manifest = $this->app->make(BeamDoctorManifest::class);

        // GATE — the ONLY gating registration in the particle-doctrine-convergence effort. Everything else
        // here is a burn-down backlog; this one protects the discoverability surface the rest of the effort
        // depends on. `popcorn:registries --json` is what an agent is told to run to answer "where do
        // I register this", so an undeclared registry is not a stale document, it is an agent building a
        // parallel mechanism next to one that already exists. It is also the cheapest possible fix (one
        // attribute), which is what makes blocking proportionate here and nowhere else. Its findings are
        // Fail, so it blocks at the runner's DEFAULT floor.
        $manifest->register('splicewire/laravel-beam', UndescribedRegistryAudit::class, gate: true);

        // GATE, and the second one the estate has ever had — see the audit's own docblock for why the usual
        // "blocks every host on day one" objection does not reach a population that opted in by declaring
        // itself. Its `implements Registry` check reports rather than blocks until tickets 37/38 land the
        // migration; that is one population-wide flag naming no rows, not a suppression list.
        $manifest->register('splicewire/laravel-beam', RegistryConformanceAudit::class, gate: true);

        // Advisory, permanently. This is where every judgement call about a registry-SHAPED class lives, and
        // a judgement call that fails the build is a judgement someone else made for you. It ratchets via
        // its committed artifact and `splicewire:beam:registry-conformance --check`, not via the gate.
        $manifest->register('splicewire/laravel-beam', UndeclaredRegistryShapeAudit::class);
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
        // Every route macro this package ships, contributed by the trait that OWNS it rather than
        // hand-listed here: the particle resource/op mounts, the `->beam()` route-metadata namespace,
        // and the rendering mount. Each link declares its own `order:`, so adding one is `use`-ing a
        // trait — and the sequence does not rest on where a `use` statement sits, which `pint`'s
        // `ordered_traits` fixer would resort alphabetically.
        $this->chainTraitMethods('boot');

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
        // frame/intake-door/data-schemas checks it runs are advisory + presence-conditional.
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
                // The contribution registry's codegen half: an owner resource's read type intersected
                // with every contributed slice (particle-contribution-seam #22). Unconditional and inert
                // — a host with no contributions generates an artifact that declares nothing.
                GenerateContributedTypesCommand::class,
                GenerateAssetsCommand::class,
                UndeclaredSurfaceCommand::class,
                // The registry ratchet's write/check/json surface (registry-kernel ticket 35 §3). Sits
                // beside the undeclared-surface command it is modelled on, and unconditional for the same
                // reason its audits are: the artifact is the accountability, and a command that only exists
                // where a dev dependency does is a ratchet half the fleet cannot turn.
                RegistryConformanceCommand::class,
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

            // The Scribe stub (ADR-0211 §7). A publish-only STUB, never a merged package config: once
            // published the file is the host's, and Scribe's own defaults still apply to a host that
            // never publishes it. What the stub carries that those defaults do not is the emitter-only
            // pair (`type` = laravel + `add_routes` = false, ADR-0028), the `api/*` match rules that ARE
            // the exposure boundary given the artifact route is public, and the Splicewire\Beam\Scribe\*
            // strategies + generators without which a generated spec is bare paths.
            //
            // Unlike beam-stubs, this IS an install step (registered below): a fresh starter must boot
            // with a spec, and it cannot generate one worth reading from Scribe's stock config.
            $this->publishes([
                dirname(__DIR__).'/stubs/scribe/scribe.php' => config_path('scribe.php'),
            ], 'beam-scribe');
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
            publishTags: ['beam-config', 'beam-migrations', 'beam-scribe'],
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

        // The fleet test-runner convention (docs/agents/test-runner.convention.md): family repos test on
        // Pest. Same self-registering, advisory shape as the AGENTS.md check above — a repo answers for
        // itself and nothing gates. Beam core is currently one of the thirteen holdouts, so this package
        // ships an audit it fails; that is the TablePrefixBypassAudit shape, not an oversight.
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            TestRunnerConformanceAudit::class,
        );

        // ADR-0211 §8: Scribe stays an EMITTER (no second docs UI), the host has an artifact to serve, and
        // something regenerates it on deploy. Advisory — beam reserves `gate: true` for "an agent is
        // building a thing wrong", and a host that deliberately wants Scribe's own static UI, or that has
        // simply not generated yet, is making a defensible choice to report, not to block.
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            ScribeOutputContractAudit::class,
        );

        // Published copies of migrations beam has since RETIRED. Publishing is a copy, so a squash
        // upstream cannot reach back into a host — and a stale copy usually sorts EARLIER than the stub
        // that replaced it, creating the table in a shape the survivor then refuses to converge onto.
        // One such file produced 421 failures in tower's suite; the guard was right and the cause was
        // invisible. Advisory: the remedy deletes a file from the host's own database/migrations, which
        // is the host's call.
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            RetiredMigrationAudit::class,
        );

        // beam-facade #110: the OTHER direction of the same defect — a ledger row whose migration file
        // the host cannot produce, because a publish was run and then never committed. RetiredMigration
        // reports a file that should not exist; this reports the absence of one that should. Measured at
        // rushing/audiostud: twelve orphan rows, two of which (`create_ranks_table`,
        // `create_rank_trees_table`) meant twelve RanksTest cases passed on the dev database and failed
        // on every fresh one. Advisory — the repair is re-publish-and-commit OR delete the row, and only
        // the host knows which.
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            LedgerAheadOfRepositoryAudit::class,
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

        // particle-doctrine-followups #14: the schema leg's first drift guard. Advisory (a
        // regeneration backlog that fails the build is just a blocked build) and unconditional here —
        // its forApp() degrades to a stated skip when data-schemas isn't installed, mirroring
        // SchemaRoundTripAudit's presence-conditionality.
        $this->app->bind(SchemaProjectionDriftAudit::class, fn () => SchemaProjectionDriftAudit::forApp());
        $this->app->make(BeamDoctorManifest::class)->register(
            'splicewire/laravel-beam',
            SchemaProjectionDriftAudit::class,
        );

        // The OPTIONAL public intake door (ticket 04) — mounted only when the host opts in. Deny-default
        // still guards it (a schema must be allow-listed), so mounting alone opens nothing.
        if (config('beam.core.intake.enabled', false)) {
            $this->registerIntakeRoute();
        }

        // The OpenAPI artifact routes (ADR-0211 §1). UNCONDITIONAL, unlike the intake door above: with no
        // artifact on disk both URLs 404, so mounting opens nothing either — and a headless beam host (no
        // laravel-beam-ux) still serves its own spec, which is the whole reason this is a package route
        // rather than a docs entry.
        $this->registerOpenApiRoutes();

        // Attributed-realm registration (realm-architecture ticket 08 slice D). Realms are ~4
        // (admin·tenant·user·docs), so a filesystem scan is overkill — register the configured
        // realm-marker classes EXPLICITLY. Each is reflected for its `#[Realm]`-family attribute,
        // projected into a RealmDefinition, and self-registered onto the singleton RealmRegistry —
        // ADDITIVE onto the three imperative base realms (last-wins by key). Vocabulary lives in beam.
        $this->registerRealms();

        // DECLARING and INDEXING are two acts (registry-kernel 21 D1): the `#[IsRegistry]` on
        // RealmRegistry names `beam.realm`, and this is where that root actually becomes reachable
        // through `RegistryIndex::routeTo()`. Described from the OWNER's own boot — the index never
        // reaches up — and AFTER registerRealms(), so `popcorn:registries` reports a populated registry
        // rather than an empty one. The host's `App\Frame\RealmRegistry` subclass is what resolves
        // here; it inherits the declaration through IsRegistry's parent walk (ticket 42), so there is
        // one described root, not two.
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(RealmRegistry::class),
            by: self::class,
        );

        // The same second act for the config-fed adapter ticket 25 landed. Described here rather than
        // beside its binding because a describe belongs in boot(), after the config merges every
        // registrant writes through have run — the property ConfigRegistry's docblock argues for.
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(RouteManifestSourceRegistry::class),
            by: self::class,
        );

        // Resource DECLARATION discovery (ADR-0156). Reflect the configured #[ParticleResource] classes +
        // scan the configured discover-paths into beam's singleton ParticleResourceRegistry, which frame's
        // manifest machinery reads through the ResourceRegistry port (via ParticleResourceRegistryAdapter). This
        // is the discovery wiring that used to live in frame's FrameServiceProvider — moved here because the
        // #[ParticleResource] opinion is beam's.
        $this->discoverResources();

        // Attributed REST/op discovery (ADR-0116/0160): the runtime twin of #[ParticleResource] discovery.
        // Reflect the configured #[ParticleResource] / #[ParticleOp] Data classes (+ discover-paths) into
        // the two particle registries, so a host declares a REST resource / named op ON its Data class
        // instead of hand-registering it from a provider. Closures the attribute can't carry are resolved
        // from `public static` convention methods on the annotated class.
        $this->discoverParticleAttributes();

        // The per-resource rendering registry's own registrar, attached in the OWNER's boot() for the
        // reason its binding above states (registry-kernel ticket 53). `beam.core.renderings` is
        // `resource => [class, class]`; the expansion of that list into one entry per rendering is the
        // registry's own vocabulary, not the kernel's — see `ResourceRenderingRegistry::register()`.
        $this->app->make(ResourceRenderingRegistry::class)->attach(new ConfigRegistrar(
            (array) config('beam.core.renderings', []),
            'beam.core.renderings',
        ));

        // The second act, per ticket 21 D1: declaring and indexing are two things, and these two
        // registries are the first registrar-FED roots the index carries.
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(ParticleResourceRegistry::class),
            by: self::class,
        );

        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(ResourceRenderingRegistry::class),
            by: self::class,
        );

        // The relative-edge registry (api-surface-coherence ticket 50). Described from beam's own boot
        // per the register-down rule, and AFTER discoverParticleAttributes() so `popcorn:registries`
        // reports a populated root rather than an empty one — the same sequencing the two registries
        // above take. Both axes the vocabulary note asks for ride on its `#[IsRegistry]`: the SEAM (what
        // gets in — declared relative edges, contributed by the coupling owner) and the ARITY (PickOne
        // over a flat `<parent>.<child>` keyspace).
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(ParticleRelativeRegistry::class),
            by: self::class,
        );

        // The operation registry (particle-operation-surface ticket 01). It was the one particle root
        // that never described itself — five sibling registries have reported from this boot for months
        // and `beam.particle.operations` was absent from `popcorn:registries` the whole time, which is
        // exactly the negative space `UndescribedRegistryAudit` calls "vacuous until owners start
        // describing". AFTER discoverParticleAttributes(), for the reason the relative registry above
        // states: a described-but-empty root is a worse answer than a described-and-populated one.
        //
        // Its `registerHint` matters more here than for most: the whole point of ticket 01 is that a
        // package registers an operation on a resource it does not own, and the index is where a
        // contributor finds out that is legal. ⚠️ 08 found `UndescribedRegistryAudit` gates that a
        // registry IS described, never that the description is TRUE — a lying hint passed for months —
        // so the hint on the `#[IsRegistry]` is a claim to keep honest, not a formality.
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(ParticleOperationRegistry::class),
            by: self::class,
        );

        // The event catalog's own registrars, attached on `booted()` rather than here — measured, not
        // cautious. Every `register()` validates the event name's prefix against the LIVE resource keys,
        // and the flagship host declares its twenty-odd resources from an APP provider's boot(), which
        // runs after every package provider's. Filling in beam's own boot() would therefore fan
        // `{resource}.persisted` out over an estate of two, and a host package registering
        // `compositions.render.completed` would be rejected for a resource that exists three providers
        // later. `booted()` is the only point at which "the live resource keys" is a settled question.
        //
        // ⚠️ `attach()` is EventTypeRegistry's own, not the composed store's: `BasicRegistry::attach()`
        // hands the registrar the store and every write would bypass that validation silently. See the
        // registry's class docblock.
        $this->app->booted(fn () => $this->registerEventTypes());

        // The second act (registry-kernel ticket 21 D1) for the event catalog.
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(EventTypeRegistry::class),
            by: self::class,
        );

        // Frame OS ticket 08 (ADR-0013 §2): beam is the authority that unifies the two authorization
        // planes. Register a Laravel Gate ability per KNOWN feature key (`entitlement:{key}`) delegating to
        // the entitlement gate (which consults the bound kernel EntitlementResolver) — so the feature plane
        // (`Gate::allows('entitlement:workbench.enter')`) and the per-action plane (`Gate::allows('song',
        // $song)`) answer one uniform predicate. Inert by default: with no `app.entitlements` configured
        // and the null resolver, no abilities register that would ever pass.
        $this->registerEntitlementAbilities();

        // A hook is deleted when its subject is (api-surface-coherence 38 / 12 §1) — nulling the morph
        // would silently promote a one-record subscription into a firehose over the whole resource. A
        // polymorphic reference cannot carry a database FK, so it is a wildcard Eloquent listener;
        // {@see HookSubjectPruner} carries the memo that keeps it off the hot path of every delete in
        // the host, and the `saved`/`deleted` hooks below are what keep that memo honest in a
        // long-lived queue worker.
        Event::listen('eloquent.deleted: *', [HookSubjectPruner::class, 'handle']);
        Hook::saved(fn () => HookSubjectPruner::forget());
        Hook::deleted(fn () => HookSubjectPruner::forget());

        // The tripwire against the one registration idiom that cannot work (particle-contribution-seam
        // ticket 07): `afterResolving()` on a particle registry beam has ALREADY resolved above. The guard
        // arms an `Application::booted()` callback rather than checking inline, because a package that
        // boots after beam has not registered its hook yet at this point in the order.
        (new DeadResolvingHookGuard($this->app))->arm();
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
        return class_exists('Splicewire\Beam\Tenancy\BeamTenancyServiceProvider');
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
     * Boot-time #[ParticleResource] / #[ParticleOp] / #[ParticleRelative] discovery into the three
     * particle registries — the REST/op/edge sibling of {@see self::discoverResources()} (which feeds the
     * admin manifest). Reads
     * `beam.core.particle.classes` / `.discover_paths`. Absent config ⇒ no-op (every existing host that
     * hand-registers its resources from a provider is unchanged; the two seams coexist).
     */
    protected function discoverParticleAttributes(): void
    {
        $classes = config('beam.core.particle.classes', []);
        $paths = config('beam.core.particle.discover_paths', []);

        // Beam's OWN declaration sites, always registered — they are not a host's to name.
        //
        // This is `particle-contribution-seam` ticket 07's ratified idiom applied to the owner itself:
        // a package registers its own declarations rather than waiting for a host to list their FQCNs.
        // The estate-wide `discover_paths` points at the HOST's `app_path('Data')`, which a beam class
        // can never be inside, so without this line `BeamSchemaData` would reach a registry only where
        // some host happened to name it — the exact defect ADR-0214 §5 removed for beam-ux.
        //
        // Registration is idempotent by key (last wins), so a host that also lists these classes during
        // a migration window registers the same declaration twice and gets the same result.
        $classes = [...self::PARTICLE_DECLARATIONS, ...$classes];

        (new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
            $this->app->make(ParticleRelativeRegistry::class),
        ))->discover($classes, $paths);
    }

    /**
     * Every `#[ParticleResource]` / `#[ParticleOp]` declaration site inside beam itself.
     *
     * `BeamSchemaData` is the `schemas` resource over the DB-backed schema registry (`beam_schemas`),
     * declared by registry-kernel ticket 65. It mounts no generic CRUD — see that class's docblock for
     * why none of the five verbs fit a write-once, `$id`-addressed, two-tier surface.
     *
     * @var list<class-string>
     */
    protected const PARTICLE_DECLARATIONS = [
        BeamSchemaData::class,
    ];

    /**
     * Fill the publishable-event catalog: the `BeamParticlePersisted` fan-out (one `{resource}.persisted`
     * entry per particle resource that has a model), then any `#[BeamEvent]`-annotated event classes the
     * host has opted into scanning.
     *
     * Both are {@see Registrar}s attached to the registry ITSELF rather than to its composed store, which
     * is what keeps every write behind the registration-time validation. Order matters only in that the
     * fan-out runs first: it is the one source that cannot name a duplicate, so a collision between it
     * and a host declaration is reported against the host's own class.
     *
     * `beam.core.events.discover_paths` / `.classes` are empty by default and the scan is skipped
     * entirely when they are — a fresh beam host gets the persisted fan-out and nothing else.
     */
    protected function registerEventTypes(): void
    {
        $events = $this->app->make(EventTypeRegistry::class);

        $events->attach(new ParticlePersistedEventRegistrar(
            $this->app->make(ParticleResourceRegistry::class),
        ));

        // The `hooks` resource's own two lifecycle names (api-surface-coherence 38 / 12 §8). Attached
        // AFTER the persisted fan-out for the same reason that one goes first: the fan-out is the only
        // source that cannot name a duplicate, so a collision between it and anything else is reported
        // against the other claimant.
        $events->attach(new HookEventRegistrar);

        $classes = (array) config('beam.core.events.classes', []);
        $paths = (array) config('beam.core.events.discover_paths', []);

        if ($classes === [] && $paths === []) {
            return;
        }

        $events->attach(new BeamEventRegistrar($paths, $classes));
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

        // Dev fallback: no manifest — live-scan the discover-paths, through the kernel's own reader.
        //
        // ⚠️ **The estate's FIRST live registrar attach** (registry-kernel ticket 53, paying ticket 19
        // D3's clause and ticket 07's four-times-relayed criterion). Three properties are load-bearing
        // and all three are asserted by `ParticleResourceRegistrarOrderingTest`:
        //
        //  - it is attached in the OWNER's own `boot()`, so it runs before any consumer provider boots
        //    and hand-registers — explicit registration lands second and wins by `OnDuplicate::Supersede`
        //    alone, with no tier, no branch and no precedence rule (07 D9);
        //  - `attach()` FILLS IMMEDIATELY, so "when did you call attach" IS the ordering rule — there is
        //    no second `fill()` for anyone to put in the wrong place (24 D2);
        //  - the key comes off the projected entry through `HasRegistryKey` (`ParticleResource::$key`).
        //    `AttributeRegistrar` THROWS rather than deriving one from a class name, so there is no
        //    name-derived fallback to land in by accident.
        //
        // `registerClass()` stays for the explicit-list and cached-manifest paths above; what moves here
        // is the SCAN, which is what the registrar reads.
        $registry->attach(new AttributeRegistrar(
            paths: (array) config('beam.core.resources.discover_paths', config('frame.discover_paths', [])),
            attribute: ParticleResourceAttribute::class,
            project: fn (string $class) => AttributedParticleDiscovery::resourceFromAttribute($class),
            instanceof: false,
        ));
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

    /**
     * Mount `GET beam/openapi.yaml` + `GET beam/openapi.json` onto {@see OpenApiSpecController}
     * (ADR-0211 §1/§2), with host-configured middleware — public by default.
     *
     * Two fixed routes, not one negotiated on `Accept`: every consumer of a spec takes a URL, so
     * negotiation alone would leave a caller with no JSON link to paste. The path is fixed and NOT
     * configurable — ticket 02 established that public docs paths come from containment, and this
     * inherits that ruling by being a package-owned namespaced path rather than a docs path at all.
     */
    protected function registerOpenApiRoutes(): void
    {
        $middleware = (array) config('beam.core.openapi.middleware', []);

        Route::get('beam/openapi.yaml', [OpenApiSpecController::class, 'yaml'])
            ->middleware($middleware)
            ->name('beam.openapi.yaml');

        Route::get('beam/openapi.json', [OpenApiSpecController::class, 'json'])
            ->middleware($middleware)
            ->name('beam.openapi.json');
    }
}
