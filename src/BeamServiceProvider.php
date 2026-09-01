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
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Rushing\Doctor\DoctorAudit;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Rushing\Popcorn\Registries\Registrars\AttributeRegistrar;
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
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Schemastud\Frame\Realm\RealmDefinition;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\GuardActorAdapter;
use Splicewire\Beam\Capabilities\CapabilityRegistry;
use Splicewire\Beam\Concerns\BootsBeamRouteNamespace;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Console\BeamSeedCommand;
use Splicewire\Beam\Console\ConvergencePreflightCommand;
use Splicewire\Beam\Console\DocblockCommand;
use Splicewire\Beam\Console\FrameCacheCommand;
use Splicewire\Beam\Console\FrameClearCommand;
use Splicewire\Beam\Console\GenerateAssetsCommand;
use Splicewire\Beam\Console\GenerateClientSdkCommand;
use Splicewire\Beam\Console\GenerateContributedTypesCommand;
use Splicewire\Beam\Console\HouseStyleCommand;
use Splicewire\Beam\Console\MakeParticleOpCommand;
use Splicewire\Beam\Console\MakeParticleResourceCommand;
use Splicewire\Beam\Console\ParticleResourcesCommand;
use Splicewire\Beam\Console\RegistryConformanceCommand;
use Splicewire\Beam\Console\UndeclaredSurfaceCommand;
use Splicewire\Beam\Console\VerifyDeclaredTypesCommand;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Discovery\ResourceMountMap;
use Splicewire\Beam\Discovery\RouteReachability;
use Splicewire\Beam\Doctor\AgentsMdConventionAudit;
use Splicewire\Beam\Doctor\BeamCoreMigrationsAudit;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\ConfigFacadeReferenceAudit;
use Splicewire\Beam\Doctor\DeadConfigKeyAudit;
use Splicewire\Beam\Doctor\FamilySourceCoverageAudit;
use Splicewire\Beam\Doctor\FamilyTokenContractAudit;
use Splicewire\Beam\Doctor\FilterablePromiseAudit;
use Splicewire\Beam\Doctor\FilterStampReadPathAudit;
use Splicewire\Beam\Doctor\KeyTypeConformanceAudit;
use Splicewire\Beam\Doctor\LedgerAheadOfRepositoryAudit;
use Splicewire\Beam\Doctor\MigrationOrderingAudit;
use Splicewire\Beam\Doctor\PackageStubConflictAudit;
use Splicewire\Beam\Doctor\ParticleCapabilityDisagreementAudit;
use Splicewire\Beam\Doctor\ParticleIdConstraintKeyTypeAudit;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Doctor\RetiredMigrationAudit;
use Splicewire\Beam\Doctor\ScribeOutputContractAudit;
use Splicewire\Beam\Doctor\StubStaticReferenceAudit;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Doctor\Support\FamilyTailwindScan;
use Splicewire\Beam\Doctor\Support\TrackerTicketStatus;
use Splicewire\Beam\Doctor\TestRunnerConformanceAudit;
use Splicewire\Beam\Doctor\UndeclaredInputAudit;
use Splicewire\Beam\Doctor\UndeclaredOutputAudit;
use Splicewire\Beam\Doctor\UndeclaredRegistryShapeAudit;
use Splicewire\Beam\Doctor\UngatedOperationAudit;
use Splicewire\Beam\Doctor\UngatedWriteOperationAudit;
use Splicewire\Beam\Doctor\UnguardedCreateAudit;
use Splicewire\Beam\Doctor\UnrehearsableStubAudit;
use Splicewire\Beam\Doctor\UnverifiedOnPopulatedTableAudit;
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
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Splicewire\Beam\Query\HookResourceQuery;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Realm\ConfigTenantResolver;
use Splicewire\Beam\Realm\Contracts\TenantResolver;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Routing\RouteActionMetadataReader;
use Splicewire\Beam\Routing\RouteMetadataReader;
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
use Splicewire\Beam\Surgeon\DeclarationDocblockAudit;
use Splicewire\Beam\Surgeon\DocblockTierAudit;
use Splicewire\Beam\Surgeon\DuplicateRouteNameAudit;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Splicewire\Beam\Surgeon\ListedResourceDisplacementAudit;
use Splicewire\Beam\Surgeon\MorphAliasCoverageAudit;
use Splicewire\Beam\Surgeon\ParticleControllerRedundancyAudit;
use Splicewire\Beam\Surgeon\ParticleOperationBypassAudit;
use Splicewire\Beam\Surgeon\ParticleWriteBypassAudit;
use Splicewire\Beam\Surgeon\RealmGateCoverageAudit;
use Splicewire\Beam\Surgeon\SchemaProjectionDriftAudit;
use Splicewire\Beam\Surgeon\SdkEndpointDriftAudit;
use Splicewire\Beam\Surgeon\SdkHookMigrationAudit;
use Splicewire\Beam\Surgeon\SdkHookMigrationBridge;
use Splicewire\Beam\Surgeon\SdkNameConventionAudit;
use Splicewire\Beam\Surgeon\SdkReturnsCoverageAudit;
use Splicewire\Beam\Surgeon\SdkReturnsTypeScriptResolutionAudit;
use Splicewire\Beam\Surgeon\StatusChannelLiteralDriftAudit;
use Splicewire\Beam\Surgeon\SupersededDeclarationAudit;
use Splicewire\Beam\Surgeon\Support\PackageOrigin;
use Splicewire\Beam\Surgeon\TablePrefixBypassAudit;
use Splicewire\Beam\Surgeon\TypeScriptShortNameCollisionAudit;
use Splicewire\Beam\Surgeon\TypeScriptUnknownResolutionAudit;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Surgeon\UndeclaredWriteMapAudit;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;
use Splicewire\Beam\Surgeon\UnindexedRegistryAudit;
use Splicewire\Beam\Surgeon\UnrealmedResourceAudit;
use Splicewire\Beam\Surgeon\WireNameDeclarationAudit;
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

        // Frame's agnostic ResourceRegistry port (ADR-0156: "frame has no concept of admin") is served by
        // the genuinely stateless {@see ParticleResourceRegistryAdapter} forwarder — it exists only because
        // PHP has no overloading (ParticleResourceRegistry's REST-facing `get(): ParticleResource` and the
        // port's `get(): ResourceDefinition` can't share a method name on one class), so every call passes
        // straight through to ParticleResourceRegistry's differently-named projection methods. A SINGLETON
        // so boot-time #[ParticleResource] discovery (packageBooted) persists across the request. Frame's
        // manifest machinery reads the port; it never imports this beam type (arrow points DOWN, beam → frame).
        $this->app->singleton(ParticleResourceRegistryAdapter::class);

        // ⚠️ It is ATTACHED as a member of `frame.resources`, not ALIASED onto the port (registry-kernel
        // 77). The alias made the container's answer for the port and the index's answer for the root two
        // different objects — the adapter holding 53 resources at `~/Herd/splicewire-app` beside a
        // freshly-constructed, empty `InMemoryResourceRegistry` owning the root — and it expressed "beam
        // supplies frame's resources" as a container binding that `popcorn:registries` and `routeTo()`
        // could not see. As a member the relationship is addressable at `frame.resources.beam`, a second
        // producer is expressible instead of silently overwriting this one, and the projection semantics
        // stay exactly where they belong: the index routes, the adapter projects.
        //
        // The closure is over the container SINGLETON above, deliberately — the index does not memoise
        // members, so a closure that constructs would hand out a fresh adapter per read.
        // `callAfterResolving`, not `afterResolving`: the latter only fires on FUTURE resolutions, so a
        // composite already built by an earlier provider would never learn about beam — the silent
        // half-wiring this file's own history is full of. This one fires immediately when the binding is
        // already resolved and registers the hook otherwise.
        $this->callAfterResolving(CompositeResourceRegistry::class, function (CompositeResourceRegistry $resources, $app): void {
            $resources->attach('beam', fn (): ParticleResourceRegistryAdapter => $app->make(ParticleResourceRegistryAdapter::class), by: self::class);
        });

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
        // registries are container singletons so the `Particle::mount()` / `Particle::ops()`
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

        // ⚠️ TWO realm-map seeds, and the second one exists so a host never re-binds this singleton
        // (particle-manifest-repatriation ticket 02). `frame.realms` is FRAME's config file: a host with
        // one membership fact to add had to publish and restate the whole map, which is restating in
        // order to extend — so `beam.core.resources.realm_map` is a purely ADDITIVE second source, unioned
        // in by the same idempotent method. Absent at 20 of the 21 `~/Herd` roots and inert when absent,
        // so membership is byte-for-byte unchanged wherever it is not declared. The imperative twin is
        // `app(ParticleResourceRegistry::class)->loadRealmMap([...])` from the host's own boot(); both
        // reach the SHARED instance, which is the point — a host that overrides this binding to change one
        // collaborator drops the ones added later. The flagship was the worked example (it passed one
        // argument where this passes two, so its registry ran with `contributions: NULL`); its override is
        // deleted as of particle-manifest-repatriation ticket 03, and it needed no replacement seam at all
        // because the seed it repeated is the `frame.realms` read on the line below.
        $this->app->singleton(ParticleResourceRegistry::class, fn ($app) => (new ParticleResourceRegistry(
            $app->make(RealmResourceRegistry::class),
            $app->make(ResourceContributionRegistry::class),
        ))
            ->loadRealmMap((array) config('frame.realms', []))
            ->loadRealmMap((array) config('beam.core.resources.realm_map', [])));
        $this->app->singleton(ParticleOperationRegistry::class);
        // The relative-edge registry (api-surface-coherence ticket 50) — the op registry's twin in shape
        // as well as in role, so the two migrate as one archetype when registry-kernel's
        // per-resource-registry sweep reaches beam.
        $this->app->singleton(ParticleRelativeRegistry::class);

        // The gated-capability registry (root `beam.capabilities`, app ADR-0023). Bound HERE, by the
        // package that owns the root, because contributors now seed it from their OWN boot
        // (`Splicewire\Tower\TowerServiceProvider::packageBooted()`) instead of through a subclass
        // whose `registerDefaults()` hook the host had to remember to bind. A shared singleton is what
        // makes that seeding reach a reader: unbound, this class is auto-resolvable, so every
        // `app(CapabilityRegistry::class)` and every constructor injection would get a FRESH, EMPTY
        // registry and the contributor's writes would land on an object nobody reads — green suite,
        // empty registry. That was the live state before this binding: the flagship bound tower's
        // SUBCLASS, so `DefaultEntitlementGate` and `FreePlanEntitlementGate`, which type-hint THIS
        // class, were autowired an unseeded one and `get('schema.llm-migration')` answered null in a
        // host where the capability was registered.
        $this->app->singleton(CapabilityRegistry::class);

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

        // The route-metadata read seam (api-surface-coherence 126). The `…If` suffix is load-bearing: a
        // host that has already bound its own reader wins, which is the whole point of turning six
        // statics into a substitutable contract. `singleton` rather than `bind` because the default
        // implementation is pure and stateless — it takes a Route as an argument and holds nothing — so
        // one instance is as correct as N and cheaper. (Unlike the three binds below, which must NOT
        // memoise, because they read the live route table.)
        //
        // `BeamRouteAction`'s statics resolve THIS binding, so rebinding it also substitutes the front
        // door the two sibling repos (`splicewire/tower`, the flagship) still call.
        $this->app->singletonIf(RouteMetadataReader::class, RouteActionMetadataReader::class);

        // The converged per-resource discovery listing (api-surface-coherence 105, decided by 41 D1/D5/D6).
        // All three are plain binds, not singletons: the map is a READ over the live route table and
        // memoising it would hand a request the table as it stood at first resolve — which is precisely
        // the staleness a runtime listing exists to avoid.
        $this->app->bind(ResourceMountMap::class, fn ($app) => new ResourceMountMap(
            $app->make('router'),
            $app->make(RouteMetadataReader::class),
        ));
        $this->app->bind(RouteReachability::class, fn ($app) => new RouteReachability(
            $app,
            $app->make(Gate::class),
            (array) config('beam.core.discovery.probes', []),
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
            $app->make(RouteMetadataReader::class),
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

        // The shared static read both family-Tailwind audits sit on (audit-and-cadence-sidebar). It takes
        // the host base path + `beam.core.tailwind`, so the manifest can `make()` either audit with no
        // per-check wiring in the aggregating command — the PrototypeWiringAudit binding precedent.
        //
        // `bind`, deliberately, not `scoped`. It memoises its own filesystem read, which is exactly the
        // silhouette {@see UndescribedRegistryAudit} gates on — plural array state plus a read verb — and
        // as a `scoped()` it failed that audit's own dogfood assertion. It is NOT a registry: nothing
        // registers into it and it holds no keyspace, so declaring `#[IsRegistry]` to quiet the gate would
        // be a lie. A fresh instance per resolution is the honest shape and costs nothing (~40ms across 24
        // resolved packages at the flagship); the memo still serves the one audit run that owns it.
        $this->app->bind(FamilyTailwindScan::class, fn ($app) => new FamilyTailwindScan(
            $app->basePath(),
            (array) config('beam.core.tailwind', []),
        ));

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
        $this->registerFilterablePromiseAudit();
        $this->registerFilterStampReadPathAudit();
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

        // The THIRD scoping, and the one 108 found nothing in the estate had (beam-facade ticket 182).
        // The two above read the published copies and the templates respectively; neither rehearses a
        // PACKAGE STUB against the live database. A host that overrides a package's shape with its own
        // published copy takes that stub out of `MigrationFiles` forever, so the preflight rehearses the
        // override, passes, and says nothing about the package declaration sitting one republish away
        // from a tier-three throw. Advisory permanently: the live shape is a fact about the host, and
        // the overrides this reports at audiostud are the estate's shape-ownership mechanism working.
        $this->app->bind(PackageStubConflictAudit::class, fn () => PackageStubConflictAudit::forApp());

        // The FOURTH convergence instrument, and the one no tier below beam can build (beam-facade
        // ticket 187). `ColumnTypeEquivalence::matches()` answers `null` for a pairing it has no mapping
        // for, and `ConvergentTable` reports that as `unverified` rather than guessing. Surgeon's
        // `unmapped-convergent-type` answers which declared TYPES those are from stub text — reaching
        // the beamless `rushing/*` packages this cannot — and stops where a text scan must, because
        // pairing a type with a live COLUMN means executing the declaration. That is `MigrationRehearsal`,
        // which lives here, and surgeon's `mustNotRequire` names `splicewire/*`. Advisory permanently:
        // whether a table holds rows HERE is a host fact, and this estate has already paid once for a
        // host-dependent throw.
        $this->app->bind(UnverifiedOnPopulatedTableAudit::class, fn () => UnverifiedOnPopulatedTableAudit::forApp());

        // The registry projection both the command and the disagreement audit read. Bound explicitly
        // rather than left to autowiring so the HANDLER column is filled from whatever resolver this host
        // ended up with — beam's OOTB default, or the bespoke map an app provider bound over it.
        $this->app->bind(ResourceRegistryReport::class, fn ($app) => new ResourceRegistryReport(
            $app->make(ParticleResourceRegistry::class),
            $app->make(FrameResourceHandlerResolver::class),
        ));
        $this->app->bind(ParticleCapabilityDisagreementAudit::class, fn ($app) => new ParticleCapabilityDisagreementAudit(
            $app->make(ResourceRegistryReport::class),
        ));

        $manifest = $this->app->make(BeamDoctorManifest::class);
        $manifest->register('splicewire/laravel-beam', KeyTypeConformanceAudit::class);
        $manifest->register('splicewire/laravel-beam', DeadConfigKeyAudit::class);
        $manifest->register('splicewire/laravel-beam', StubStaticReferenceAudit::class);
        $manifest->register('splicewire/laravel-beam', ConfigFacadeReferenceAudit::class);
        $manifest->register('splicewire/laravel-beam', UnguardedCreateAudit::class);
        $manifest->register('splicewire/laravel-beam', UnrehearsableStubAudit::class);
        $manifest->register('splicewire/laravel-beam', PackageStubConflictAudit::class);
        $manifest->register('splicewire/laravel-beam', UnverifiedOnPopulatedTableAudit::class);
        // An operation whose `ability:` is `null` is UNDECLARED, not decided — the residue
        // particle-operation-surface ticket 03 named and could not close in one act without 403ing
        // fourteen shipped endpoints. Registry-side rather than static: the question is what THIS host
        // mounted, and the count is the gate the `null` → derived-permission-name flip waits on.
        $manifest->register('splicewire/laravel-beam', UngatedOperationAudit::class);
        // The `kind: Write` half of the audit above, split out and GATING (particle-write-surface 02).
        // A write operation naming no `ability:` is the one member of that residue with no legitimate
        // reading — a Read's gate is its query scope, a Write's is nothing — and "did this declaration
        // name an ability" is answerable without knowing which host would load it, which is exactly the
        // class AGENTS.md licenses to be fatal. It gates here rather than throwing at registration
        // because a boot throw over a registry fact is what took `~/Herd/tower` off the air; measured
        // 2026-08-31, all 15 `~/Herd/*` roots that resolve this registry read zero, so the promotion
        // refuses the next one and fails nothing that exists. Split from its sibling rather than
        // branching on severity inside it: the gate flag is per REGISTRATION, so one class would fail
        // the legitimate `kind: Read` residue at `--floor=warn`.
        $manifest->register('splicewire/laravel-beam', UngatedWriteOperationAudit::class, gate: true);
        // The `input:` twin of the audit above (api-surface-coherence 117). Two checks from one class,
        // because the axes are decoupled: the RESOURCE axis counts REACHABLE write mounts, derived from
        // the router every run — a declaration count reports the 22-route saved-filters sub-surface and
        // cries wolf — while the OPERATION axis is registry-side, since every mounted op reads its
        // declaration unconditionally. Warn on both; the `null` → `false` flip is what they gate.
        $manifest->register('splicewire/laravel-beam', UndeclaredInputAudit::class);
        // The `output:` twin of the audit above (api-surface-coherence 127). Two checks again, but here
        // the split is two DIRECTIONS of one axis rather than two axes, and they scope to different kinds:
        // `respond:` with no `output:` is universal (`finish()` consults the projector on all four kinds),
        // while `output:` with no `respond:` is TASK-only (only a Task has a `{ queued: … }` default that
        // contradicts the declaration — 18 of the flagship's 20 Read/Write/Stream ops declare `output:`
        // with no projector and are correct to). Warn, and registry-side only: both live offenders are
        // provider CLOSURES a static scan structurally cannot see, and all five respond-bearing attributed
        // op classes estate-wide already declare, so the static twin has no population to read.
        $manifest->register('splicewire/laravel-beam', UndeclaredOutputAudit::class);
        // A declared `IdConstraint` measured against the model's REAL key type
        // (particle-operation-surface 14 gate 1). Advisory, because a key type is a fact about the
        // HOST and not grammar a package's declaration could have gotten right blind — and registry-
        // side, because only a booted host knows which concrete model the declaration resolved to.
        // Its reading is the precondition for gate 3: `Ulid`/`Int` stay inert until this reads zero.
        $manifest->register('splicewire/laravel-beam', ParticleIdConstraintKeyTypeAudit::class);
        // Intent measured against capability across the whole resource registry — the standing half of
        // `splicewire:beam:particle:resources` (otb-ui-frontier-sidebar DESIGN-02). Registry-side like the
        // one above it, and in the MANIFEST rather than hardcoded in `BeamDoctorCommand` because the
        // manifest is what `surgeon:audit` discovers through the host's `ConformanceManifest` port; a
        // disagreement that only appears when an operator runs a command is a reading nobody is
        // accountable to. Advisory permanently — see the audit's docblock.
        $manifest->register('splicewire/laravel-beam', ParticleCapabilityDisagreementAudit::class);
        // Joins the PUBLISHED migration files this host will actually run (stamped filename → tables
        // created / pointed at) against each other, so a migration whose foreign key targets a table
        // created later is caught at boot rather than by a failed greenfield migrate. Reads published
        // files rather than package stubs because both facts it needs — the stamp and the resolved
        // table name — only exist after publish; see the class docblock for what that trade cost.
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
        // The wire-name burn-down, same population and same reason: a declared slot is a registered
        // value. Sibling of the write-map meter — that one asks whether a DTO declared how it maps onto
        // COLUMNS, this one asks whether it declared what a client must SEND, which is the published
        // half. api-surface-coherence 100.
        // ⚠️ The host's OWN mapper config is passed in, and the audit reports only where a configured
        // mapper would REWRITE a property name. That is the whole rule: an identity mapping means the
        // property name IS the published key and there is nothing to declare. Without it the audit
        // reported 232 rows at the flagship, 212 of them correct camelCase read properties under
        // `output => null`, and its suggestion would have renamed all 212 on the wire.
        $this->app->bind(WireNameDeclarationAudit::class, fn ($app) => WireNameDeclarationAudit::forRegistries(
            $app->make(ParticleResourceRegistry::class),
            $app->make(ParticleOperationRegistry::class),
            input: config('data.name_mapping_strategy.input'),
            output: config('data.name_mapping_strategy.output'),
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
        // The one audit in the estate that compares a declaration against its OWN docblock rather than
        // against another declaration across a seam. Its TypeScript roots are DERIVED from the resolved
        // PHP roots (each package's structural JS sibling) plus whatever the host declares, so nothing
        // per-machine is committed anywhere.
        $this->app->bind(DeclarationDocblockAudit::class, fn () => DeclarationDocblockAudit::forApp());
        // Reads the resource registry only — membership is a registered value, not a syntactic one, so
        // there is nothing for a parser to do and no host path to scope. Its skip branch is what keeps
        // it quiet in the 19 of 20 bootable Herd roots that declare no realms at all.
        $this->app->bind(UnrealmedResourceAudit::class, fn ($app) => new UnrealmedResourceAudit(
            $app->make(ParticleResourceRegistry::class),
        ));
        // The realm registry's sibling meter, and deliberately a `bind` rather than a `singleton`: the
        // registry it holds is the boot singleton, but the audit itself must re-read `all()` on every
        // run so a realm contributed after beam's own boot clears its gate's finding.
        $this->app->bind(RealmGateCoverageAudit::class, fn ($app) => new RealmGateCoverageAudit(
            $app->make(RealmRegistry::class),
        ));
        // Reads the assembled route table and nothing else — no host path to scope, no registry to
        // consult, because a route name is only a defect in the company of the whole table. A plain
        // `bind` for the same reason `ResourceMountMap` is one: this is a READ over the live router, and
        // memoising it would hand a run the table as it stood at first resolve.
        $this->app->bind(DuplicateRouteNameAudit::class, fn ($app) => new DuplicateRouteNameAudit(
            $app->make(Router::class),
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
        // Advisory, permanently. The POPULATION is a host fact (which Data classes this host declares),
        // and by the estate's rule a check whose answer depends on the host must not throw. Measured
        // 2026-08-28 on laravel-beam-calendars: fifteen Data classes declared NEITHER mapping axis, so
        // the package emitted `calendar_id` and demanded `calendarId` for the same field — read one key,
        // write another — and nothing anywhere reported it.
        $manifest->register('splicewire/laravel-beam', WireNameDeclarationAudit::class);
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
        // Advisory, and the split inside it is the point: `docblock.phantom-parameter` is a Fail, because
        // whether a docblock names a parameter the constructor beneath it declares is grammar its own
        // author could have gotten right without knowing which host would load it. The other two are Warns,
        // because both need a counterpart artifact whose PRESENCE is a fact about where the audit is
        // standing. The registration is advisory because the POPULATION — which packages this host composes
        // — is a host fact either way, and a foundation package does not decide what fails a root's build.
        $manifest->register('splicewire/laravel-beam', DeclarationDocblockAudit::class);
        // Advisory: every Eloquent model a family package ships should have its alias registered by that
        // package's OWN provider, not by the host composing it. Not a gate, and for the same reason the
        // others here are not — the scope is every model in every installed family package, which includes
        // many legitimately never used polymorphically (a pivot, a lookup table, timeline's Clip/Track).
        // A gate over that set blocks every host on day one and gets switched off within the hour. The
        // burn-down is one `Relation::morphMap()` line per finding; promote to `gate: true` once it is
        // clear and the permanently-exempt set is decided.
        $manifest->register('splicewire/laravel-beam', MorphAliasCoverageAudit::class);
        // Advisory, permanently. The explicit `beam.core.resources.classes` / `frame.resources` list is
        // registered FIRST — before beam's own manifest/scan and before every other package's provider
        // boots — so under `OnDuplicate::Supersede` a host's listed override is the entry that LOSES,
        // silently, while the provider-boot route the registry's own docblock describes wins
        // (registry-kernel ticket 67). The population is a host fact — which classes this host lists and
        // which packages it composes them with — so by the estate's rule this reports rather than throws,
        // and the ordering is left exactly as ticket 19 D1 settled it.
        $manifest->register('splicewire/laravel-beam', ListedResourceDisplacementAudit::class);
        // Advisory, permanently — and the WIDER population the audit above measures a slice of. That one
        // reads the explicit config list (7 roots, 31 entries); this one reads the registry itself, so it
        // sees every displacement however it arrived — attribute scan, package provider, host provider,
        // config list. Measured at the booted `~/Herd/splicewire-app` 2026-08-31: 21 of 53 resource keys
        // and 10 of 36 operation keys carry a displaced entry that no instrument had ever read, against
        // ONE resource key and zero operations at `~/Herd/tower`. Which packages a host composes, and in
        // what provider order, is the definition of a host fact, so this reports and never throws.
        $manifest->register('splicewire/laravel-beam', SupersededDeclarationAudit::class);
        // Advisory, permanently, and NOT as a burn-down posture that could later be promoted: whether a
        // resource is realmed is a fact about the HOST, and the same declaration is unrealmed at
        // `~/Herd/splicewire-app` and UNREALMABLE at `~/Herd/tower`, which declares no realms to join.
        // Swept 2026-08-30: the flagship is the only one of the 20 bootable Herd roots that declares
        // `frame.realms` at all, so a gate here would fail every other host on a configuration they
        // deliberately do not use. That is the shape of the event-catalog outage — true at the flagship,
        // false at tower, and tower could not boot until it was downgraded. A host that wants it to gate
        // registers this class in its own manifest with `gate: true`.
        $manifest->register('splicewire/laravel-beam', UnrealmedResourceAudit::class);
        // Advisory, non-negotiably, and for the sharper half of the same reason as the audit above:
        // whether a realm is REGISTERED is a fact about which capability packages a host composes and
        // when their providers boot, so a host may legitimately register a realm after config is loaded
        // and a throw here would assert load order as correctness. That is exactly the shape that took
        // `~/Herd/tower` off the air. Swept 2026-08-30: four of the sixteen beam-installing Herd roots
        // declare realm gates at all (audiostud gates operator+tenant; the beam/satellite/tower starters
        // gate operator), twelve declare none including the flagship, and ZERO orphans remain — the three
        // starters' `os` gates, against a realm RealmRegistry has never shipped, were deleted hours
        // earlier. A zero reading is the argument for the instrument, not against it.
        $manifest->register('splicewire/laravel-beam', RealmGateCoverageAudit::class);
        // Advisory, and this is the one registration here where "advisory" understates the finding rather
        // than overstating it: a duplicate route name makes `route:cache` throw, so a host carrying one
        // cannot deploy a cached table at all. It is still not a gate, because which routes are assembled
        // is the definitive fact about the HOST — the same beam mount collides at `~/Herd/audiostud`
        // (an Inertia page route and a particle listing both claiming `songs.index`) and not at the
        // flagship, and neither package could have known. Measured 2026-08-30: the flagship's 883 named
        // routes collide zero times and cache cleanly, while audiostud, prahsys-gateway (80) and
        // prognosix-api (2) all fail `route:cache` outright. A gate would fail those roots on day one
        // over route files beam does not own. A host that wants it to gate registers this class in its
        // own manifest with `gate: true` — and given the deploy consequence, most should.
        $manifest->register('splicewire/laravel-beam', DuplicateRouteNameAudit::class);
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
     * ## Gates and advisories, and the line between them
     *
     * ⚠️ This heading read *"One gate, two advisories"* and undercounted: two of the three register
     * `gate: true`. Registry-kernel 76 moved the line rather than the count — {@see UndescribedRegistryAudit}
     * still gates, but only over classes that `implements Registry` and omit the attribute; its shape
     * heuristic's suspicions now report at Warn. The invariant both gates share is the one below: a gating
     * population must be one that answered the question in its own source.
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

        // GATE, over HALF of what it scans — and the half is the whole point (registry-kernel 76).
        //
        // This comment used to argue that blocking was proportionate because the population "opted in by
        // declaring". ⚠️ That justification had already broken when it was written. The audit's reach is
        // `governedRoots()` — one directory per package that owns a root — so a class is judged because a
        // SIBLING declared. Measured 2026-08-31: conforming one registry in `rushing/laravel-request-logs`
        // turned `RequestLogCollector` and `RequestLogTracker` into gate FAILs with no change to either
        // class, both of them carrying a measured `drop: not-a-registry` verdict. The property is monotone
        // the wrong way: every registry the estate conforms drags that package's remaining registry-shaped
        // siblings into a gate they never joined, so the FAIL count is a function of sweep progress rather
        // than of code quality.
        //
        // So the gate keeps only the certain question — `implements Registry` with no `#[IsRegistry]`, a
        // class contradicting itself in one file, which is grammar its author could have gotten right
        // without knowing the host and is therefore what this estate lets throw. The shape heuristic's
        // suspicions are re-channelled to Warn by the audit itself and dispositioned by
        // `UndeclaredRegistryShapeAudit` below — ticket 35 §4's division of labour, restored. Nothing is
        // whitelisted (57's refusal stands, `DEFAULT_WHITELIST` is still empty), no verb list is tuned, and
        // no finding is lost; only its channel changed.
        //
        // The gating population is small and is empty at both hosts measured, which is precisely when this
        // estate's signature defect fires — so the audit's PASS names what it covered and what it did not.
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

        // Advisory, and it CANNOT be anything else: index membership is a composition fact, so the same
        // declaration is a finding at a host that does not install its owning package and correct at one
        // that does. Registry-kernel 73 step 1 — the check for the hole that produced that ticket, where
        // five beam registries declared, conformed, and sat outside the index for three days while five
        // instruments read green over them because every one of them was asking the DECLARATION question.
        // Host-scoped rather than ratcheted onto index membership: a package whose registries all missed
        // the index is exactly what this looks for, and the ratchet would scope it out.
        $this->app->bind(UnindexedRegistryAudit::class, fn ($app) => UnindexedRegistryAudit::forHost(
            $app->make(RegistryIndex::class),
        ));

        $manifest->register('splicewire/laravel-beam', UnindexedRegistryAudit::class);
    }

    /**
     * The detector for the promise `#[ParticleResource]` makes by NOT opting out.
     *
     * `filterable` defaults to **true**, and a filterable resource's index rides
     * `hydrator->query($key)`, which raises on a key with no `rushing/laravel-data-filters` resource
     * behind it. Four live 500s were repaired by hand on 2026-08-29 (b1a9cd9 for beam-calendars' three,
     * 9717817 for beam's own `hooks`) and not one of the four declarations spelled the flag out. See
     * {@see FilterablePromiseAudit} for the full argument.
     *
     * Registered UNCONDITIONALLY rather than from {@see registerSurgeonAudits()}: the audit reads two
     * booted registries and the route table and parses nothing, so it needs neither `nikic/php-parser`
     * nor surgeon. Putting it behind that guard would make the check a function of whether the host
     * installed dev dependencies — ticket 04 D1's defect, which {@see registerRegistryConformanceAudits()}
     * already exists to avoid.
     *
     * Advisory, permanently, and the audit's own docblock argues it at length: BOTH halves of the
     * population — which particle resources this composition registers, and what its
     * `config/data-filters.php` plus every installed provider declare — are facts about the HOST, which
     * `rushing/laravel-doctor`'s gate-or-advisory convention names as its textbook advisory case. A host
     * that wants it to block registers the class in its own manifest with `gate: true`.
     */
    protected function registerFilterablePromiseAudit(): void
    {
        $this->app->make(BeamDoctorManifest::class)
            ->register('splicewire/laravel-beam', FilterablePromiseAudit::class);
    }

    /**
     * The detector for the promise a hand-rolled exposure makes by SAYING so.
     *
     * `->beam()->inResource($key, filters: true)` mounts the resource's whole filter sub-surface beside a
     * hand-written index, and nothing checked that the index reads the vocabulary those routes publish.
     * Three at the flagship did not (api-surface-coherence 101). See {@see FilterStampReadPathAudit}.
     *
     * Registered UNCONDITIONALLY, beside {@see registerFilterablePromiseAudit()} and for the same
     * reason: it reads the route table and one booted registry and parses no PHP, so it depends on
     * neither surgeon nor `nikic/php-parser`, and gating it on a dev dependency would make the check a
     * function of how the host installed.
     *
     * Advisory, permanently — every input (which routes this composition mounts, which data-filters
     * resources it registered, which package's controller ends up bound) is a fact about the HOST.
     */
    protected function registerFilterStampReadPathAudit(): void
    {
        $this->app->make(BeamDoctorManifest::class)
            ->register('splicewire/laravel-beam', FilterStampReadPathAudit::class);
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
                // The install's convergence preflight, as a READ-ONLY entry point (beam-facade 146).
                // Registered beside the installer because it is the same phase asked without the mutation:
                // before this, "what would collide if I published?" could only be asked by an operator
                // willing to publish. It also reaches the population no migrator-tied instrument can —
                // unpublished package stubs — which is where ticket 108's evidence base lives.
                ConvergencePreflightCommand::class,
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
                // The same emit-or-fail guarantee, widened from contribution slices to the WHOLE declared
                // particle surface. Writes nothing — it is a check, and a check that changed what emits
                // would silently retype the frontend.
                VerifyDeclaredTypesCommand::class,
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
                // The registry's own reading surface (otb-ui-frontier-sidebar DESIGN-02, reshaped from an
                // operator SCREEN to a command). Nav-invisibility is the registry's default — most
                // declarations name no `section` — so nothing in-product could enumerate the vocabulary;
                // and a screen over that set would have to reimplement the host's secure-by-omission nav
                // gate or disclose resources the viewer is denied. A shell already holds the host.
                ParticleResourcesCommand::class,
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
            package: 'splicewire/laravel-beam',
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

        // The data-filters resources that back the FILTERABLE particle resources declared just above.
        // AFTER discovery, deliberately: the guard below asks data-filters what it already holds, and
        // the model port it leaves empty resolves off the particle registry discovery has just filled.
        $this->declareFilterResources();

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

        // The discovery listing's auto-mount (api-surface-coherence 105). On `booted()` for the same
        // reason the event catalog is: its population is "every resource key with a stamped route", and
        // that is only a settled question once every provider — including the host's — has registered.
        if ((bool) config('beam.core.discovery.enabled', true)) {
            $this->app->booted(fn () => $this->app->make(ResourceDiscoveryAutoMounter::class)->mount());
        }

        // The second act for the five rows registry-kernel 38's beam pass CONFORMED and never described
        // (`d3b2fd3`, `1a127aa`, 2026-08-28). All five carry `#[IsRegistry]` and implement the contract,
        // so `splicewire:beam:registry-conformance` counted them `conforming` and `UndescribedRegistryAudit`
        // — which asks the DECLARATION question, per its own docblock — passed over them. Neither gate asks
        // the second question, so `beam.capabilities`, `beam.doctor.audits`, `beam.install.steps`,
        // `beam.seed.steps` and `schemas.sources` were absent from `popcorn:registries` and unreachable
        // through `RegistryIndex::routeTo()` for three days while every instrument read green. That is the
        // sweep brief's spine step 8, and its own warning about the b rows that "were declared and green
        // for days over an index they had never been described into".
        //
        // On `booted()`, not here: every one of the five is an ACCUMULATOR whose registrants are other
        // packages' providers (tower seeds `beam.capabilities`; beam-* packages push install, doctor and
        // seed steps; a package contributes a schema tier). Beam cannot know whether a contributor's
        // provider boots before or after its own, so `booted()` is the only point at which "what this
        // registry holds" is a settled question — the same reasoning the event catalog and the discovery
        // auto-mount above take, and the reason `popcorn:registries` reports these populated rather than
        // empty.
        $this->app->booted(function () {

            foreach ([
                CapabilityRegistry::class,
                BeamDoctorManifest::class,
                BeamInstallManifest::class,
                BeamSeedManifest::class,
                SchemaSources::class,

                // ⚠️ SIX MORE, added 2026-08-31 by registry-kernel 73 phase A, and they are the same
                // defect as the five above — found by the instrument that did not exist when the five
                // were repaired. `a699757` (2026-08-28, titled "registry-kernel 38 CLOSES beam") landed
                // all six, and the sweep LEDGER records all six as VERIFIED. They were never in the
                // index at any host: `UnindexedRegistryAudit` measured every one of them unindexed at
                // **14 of 14** `~/Herd` roots. The sweep's verification standard could not see it,
                // because every instrument it had asks the DECLARATION question.
                //
                // All six are unconditional singletons bound in this provider's register(), and all six
                // are accumulators other packages contribute into (a realm overlay, a resource
                // contribution, a surface group, an audit scan path), so `booted()` is right for the
                // reason the block above states rather than by copying it.
                RealmOverlayRegistry::class,
                RealmResourceRegistry::class,
                ResourceContributionRegistry::class,
                GroupRegistry::class,
                AuditScanPaths::class,
                FacadeConformanceScope::class,
            ] as $registry) {
            }
        });

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

        $this->registerFamilyTailwindAudits();
    }

    /**
     * The two family-Tailwind audits, registered DOWN into beam's own doctor manifest — ADVISORY, and
     * the token contract ordered behind the coverage check because it is a tier below it.
     *
     * They ride {@see BeamDoctorManifest} rather than surgeon's `BuiltInAudits` for reach: beam is
     * installed at every affected root, and at least one of them (`beam-pilot-gcp-cloud-run`) has no
     * surgeon at all. Beam-core's own audits are otherwise hardcoded in {@see BeamDoctorCommand} — these
     * two are deliberately not, because they carry no bespoke `run(...)` inputs (the whole read is behind
     * {@see FamilyTailwindScan}) and so satisfy the argument-free {@see DoctorAudit}
     * contract the manifest exists to run.
     *
     * ⚠️ `gate: false` on both, and it is the AGENTS.md rule rather than caution: "does this host scan
     * this dist" and "does this host declare this token" are facts about the HOST, which its author
     * could not have gotten right, so neither may ever join an exit code.
     */
    protected function registerFamilyTailwindAudits(): void
    {
        if (! $this->app->bound(BeamDoctorManifest::class)) {
            return;
        }

        $manifest = $this->app->make(BeamDoctorManifest::class);

        $manifest->register(
            package: 'splicewire/laravel-beam',
            audit: FamilySourceCoverageAudit::class,
            gate: false,
            order: 120,
        );

        $manifest->register(
            package: 'splicewire/laravel-beam',
            audit: FamilyTokenContractAudit::class,
            gate: false,
            order: 121,
        );
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

        // Beam's OWN declaration ROOTS, always scanned — they are not a host's to name.
        //
        // This is `particle-contribution-seam` ticket 07's ratified idiom applied to the owner itself:
        // a package registers its own declarations rather than waiting for a host to list their FQCNs.
        // The estate-wide `discover_paths` points at the HOST's `app_path('Data')`, which a beam class
        // can never be inside, so without this a beam declaration would reach a registry only where some
        // host happened to name it — the exact defect ADR-0214 §5 removed for beam-ux.
        //
        // ⚠️ It used to be a hand-written FQCN list (`PARTICLE_DECLARATIONS`, one entry), and that was a
        // MISREAD of 07 rather than an implementation of it: "a package registers its own declarations"
        // became *list your own FQCNs* when it could simply have been *scan your own directory*. The
        // list held `BeamSchemaData` and silently omitted `GitRepoData`, `HookData` and `ResetHookOp` —
        // three declarations that reached a registry only where a host's `frame.resources` named them.
        // Nothing reported the omission, because a hand-list cannot report what its author forgot.
        //
        // Registration is idempotent by key (last wins), so a host that also lists these classes during
        // a migration window registers the same declaration twice and gets the same result. The
        // `$classes` argument is untouched — an explicit list is still the cheap door, and is what a
        // caller with a class-string rather than a directory should keep using.
        $paths = [...self::PARTICLE_DECLARATION_PATHS, ...$paths];

        (new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
            $this->app->make(ParticleRelativeRegistry::class),
        ))->discover($classes, $paths);
    }

    /**
     * Ship the `data-filters` resources beam's own filterable `#[ParticleResource]` declarations
     * promise.
     *
     * ⚠️ **`filterable` is a PROMISE, and beam is the tier that defined it.** `ParticleResource`
     * defaults `filterable` to **true** — `hooks` never spells it out, it simply doesn't opt out — and
     * {@see ParticleController::index()} sends a filterable resource through
     * `hydrator->query($key)`, which raises `BadMethodCallException` on a key with no data-filters
     * registration. Measured over authenticated HTTP at `~/Herd/splicewire-app` on 2026-08-29,
     * `GET /api/v1/hooks` was a live **500**: "No data-filters resource is registered under [hooks]".
     * The exact defect `splicewire/laravel-beam-calendars` (b1a9cd9) had just been repaired for its
     * three, and beam was carrying one of its own the whole time.
     *
     * `filterable: false` is not the escape here: that path is `defaultSortedQuery()`, which cannot see
     * the request, and it would demote a resource that has two REST mounts and an operator realm.
     *
     * Registered IMPERATIVELY rather than through data-filters' `#[ResourceFilter]` discovery, for the
     * same reason `laravel-beam-lineage` and `-calendars` do it this way:
     * `config('data-filters.discover')` is HOST-owned and empty by default — a closed door to a
     * package, exactly as `discover_paths` is for particles. A package cannot add itself to a host's
     * config array, so discovery here would register nothing at a host and leave the 500 in place.
     *
     * No `model:` — {@see ParticleResourceModelResolver} (bound onto data-filters'
     * `ResourceModelResolver` port above) fills the backing off the `#[ParticleResource]` registered
     * under the *same key*, lazily. So the model lives in one place and the two read paths cannot
     * drift.
     *
     * The `has()` guard is **the caller's job, not the registry's**: `registerDefinition()` overwrites
     * plainly, so an unguarded package registration would silently stomp a host that seeded its own
     * `hooks` key from `config('data-filters.resources')`. Guarded, this is strictly additive.
     *
     * Only `hooks` today. `schemas` ({@see Data\BeamSchemaData}) and `git-repo`
     * ({@see Data\GitRepoData}) are beam's other two unregistered filterable keys, but neither is
     * mounted at the probed tenant — both answer 404 at `/api/v1/<key>`, so they are latent rather
     * than broken, and each is left for its own measurement rather than folded in blind.
     */
    protected function declareFilterResources(): void
    {
        if (! $this->app->bound(FilterResourceRegistry::class)) {
            return; // data-filters genuinely absent — `hooks` is declared, just not filterable here.
        }

        $registry = $this->app->make(FilterResourceRegistry::class);

        if ($registry->has('hooks')) {
            return;
        }

        $registry->registerDefinition(new FilterResourceDefinition(
            key: 'hooks',
            data: HookData::class,
            query: HookResourceQuery::class,
        ));
    }

    /**
     * Every directory inside beam that may hold a `#[ParticleResource]` / `#[ParticleOp]` /
     * `#[ParticleRelative]` declaration.
     *
     * Directories rather than class-strings, deliberately: a new declaration next to its siblings is
     * then registered by existing as a file, which is the only version of this that cannot rot. Both
     * roots are small (beam's `Data` holds 8 files, `Particle/Ops` holds 1), and an unattributed class
     * under either is ignored rather than an error.
     *
     * @var list<string>
     */
    protected const PARTICLE_DECLARATION_PATHS = [
        __DIR__.'/Data',
        __DIR__.'/Particle/Ops',
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
     *   Particle::ops('timeline-projects', 'timeline_project', 'regenerate')
     *      → {method} {uri}/{id}/{op} → invoke  ({resourceKey}.{op}, or the 'name' override)
     *        plus the deprecated {uri}/{id}/op/{op} alias keeping the OLD name (ticket 12)
     *      stamped with the operation controller's RESOURCE + NAME defaults.
     *
     *      ⚠️ Two of the options this line used to list have MOVED ONTO THE DECLARATION
     *      (particle-operation-surface 14): 'method' is `#[ParticleOp(method: HttpMethod::Get)]` and
     *      'idConstraint' is `#[ParticleOp(idConstraint: IdConstraint::Uuid)]`. The option keys are
     *      still read, as a migration fallback with a deletion condition — see
     *      {@see ParticleMounter::op()}. Do not write new call sites against them. The one option that
     *      is genuinely a MOUNT fact and stays is 'name', the route-name override.
     *
     * ⚠️ This whole docblock speaks in `Route::particleResource()`/`Route::particleOp()`, macros
     * api-surface-coherence 93 DELETED. `Particle::mount()` / `Particle::ops()` are the doors; the
     * spellings above survive only as a shape sketch and must not be copied into a call site.
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
