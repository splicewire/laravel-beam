<?php

namespace Splicewire\Beam\Particle;

use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Frame\ParticleResourceRegistryPort;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Realm\RealmResourceRegistry;

/**
 * The container-singleton registry of {@see ParticleResource} declarations, keyed by resource key — the
 * ONE registry serving both consumers that used to be two separate classes:
 *
 *   - the **REST tier**: {@see ParticleController} resolves a declaration here at request time
 *     ({@see get()}/{@see has()}, unchanged signatures — every existing caller of this registry keeps
 *     working exactly as before);
 *   - **Frame's manifest port**: {@see definition()}/{@see definitions()} project a stored declaration
 *     into a {@see ResourceDefinition}, realm-membership-filtered and overlay-applied. These are
 *     DIFFERENTLY NAMED from Frame's `ResourceRegistry::get()`/`all()` on purpose — this class cannot
 *     implement that port directly (PHP has no overloading; `get(): ParticleResource` and a hypothetical
 *     `get(): ResourceDefinition` can't coexist on one class), so a genuinely stateless one-method-per-line
 *     forwarder ({@see ParticleResourceRegistryPort}) sits behind the port and calls
 *     straight through to these.
 *
 * Was two registries: this one (REST-only, no realm concept) and the retired `AdminResourceRegistry`
 * (Frame's port implementation, its OWN parallel `$declarations` store, requiring every Frame-manifest
 * resource to be registered TWICE — once here, once there). Merging them means one `register()` call
 * is enough for both transports.
 *
 * ## The realm axis
 *
 * A resource may declare which realm(s) it belongs to AT REGISTRATION (`register($resource, realms:
 * ['operator', 'tenant'])`) — {@see definitions()} filters by this membership when asked for one realm.
 * A resource registered with no `$realms` falls back to {@see loadRealmMap()}'s bulk-seeded membership
 * (today's mechanism, `config('frame.realms')`) — so existing `#[ParticleResource]`-discovered resources
 * that have never named a realm at their own registration site keep exactly their current visibility
 * without every call site needing to migrate at once. Explicit registration-time realms WIN over the
 * bulk map for the same key.
 *
 * ## Declaration-level realm awareness vs. cross-layer overrides
 *
 * Two DIFFERENT, complementary mechanisms exist for a resource to vary by realm:
 *   - The declaring package can write realm-conditional logic directly into its OWN `ParticleResource`
 *     (e.g. a `$scope` closure that branches on the realm it's given — {@see ParticleResource::$scope}).
 *     Same-package, self-declared.
 *   - A DIFFERENT layer/package that does not own the declaration registers a {@see RealmResourceRegistry}
 *     overlay for `(realm, key)` instead — presentation-only fields (label/group/form/policy/query-class/
 *     readOnly), applied AFTER the realm-agnostic base projection. Cross-layer, additive, last-wins.
 * Both are real and both get fed the actual requested realm now ({@see project()}) — previously neither
 * ever received one (`AdminResourceRegistry::all()`/`get()` were always called with `$realm = null` from
 * the one real caller, so the overlay/declaration seam existed but never fired).
 */
#[IsRegistry(
    root: 'beam.particle.resources',
    of: 'Frame resource definitions (model→Data projections) driving reads + the editor',
    arity: RegistryArity::PickOne,
    entryType: 'mixed',
    onDuplicate: OnDuplicate::Supersede,
    note: 'Declared, not inherited: overwrite is intentional here and the class docblock argues it. '
        .'`mixed` is honest rather than lazy — an entry is a ParticleResource OR a raw ResourceDefinition '
        .'(the imperative/union escape hatch). Whether the three collections split into three registries '
        .'is registry-kernel ticket 36\'s, not a thing to improvise. Realm membership is a TAG recorded '
        .'beside the entry, never a second key dimension: one entry, many realms, no duplicate.',
    order: 12,
)]
class ParticleResourceRegistry
{
    /**
     * The stored DECLARATIONS, keyed by resource key — a {@see ParticleResource} (projected per-realm at
     * build) or a raw {@see ResourceDefinition} (the imperative/union escape hatch — {@see registerDefinition()}
     * — served as-is; realm-invariant by construction).
     *
     * @var array<string, ParticleResource|ResourceDefinition>
     */
    private array $resources = [];

    /**
     * Explicit realm membership named AT REGISTRATION, keyed by resource key. Wins over {@see $realmMap}
     * for the same key.
     *
     * @var array<string, list<string>>
     */
    private array $realms = [];

    /**
     * The bulk-seeded fallback membership map ({@see loadRealmMap()}) — `realm => [keys]`, today's
     * `config('frame.realms')` shape. Consulted only for a key with no explicit {@see $realms} entry.
     *
     * @var array<string, list<string>>
     */
    private array $realmMap = [];

    /**
     * @param  ?RealmResourceRegistry  $overrides  the per-realm presentation-override overlay (RDU-03).
     *                                             Null (the bare test ctor) or an empty overlay registry
     *                                             ⇒ INERT: identity projection in every realm.
     */
    public function __construct(private ?RealmResourceRegistry $overrides = null) {}

    // ── REST tier (unchanged signatures — every existing caller of this registry is unaffected) ───────

    public function get(string $key): ParticleResource
    {
        $resource = $this->resources[$key]
            ?? throw new RuntimeException("No particle resource registered for key [{$key}].");

        if (! $resource instanceof ParticleResource) {
            throw new RuntimeException(
                "Resource [{$key}] was registered as a raw ResourceDefinition (registerDefinition); it has no ParticleResource declaration for REST use."
            );
        }

        return $resource;
    }

    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    /**
     * Every registered {@see ParticleResource} DECLARATION, registration order — for auditors walking
     * the declared set rather than looking one up (the schema-projection drift audit,
     * particle-doctrine-followups 14). Raw {@see ResourceDefinition} escape-hatch entries are excluded:
     * they carry no `data:`/`input:` Data-class declaration to audit.
     *
     * @return list<ParticleResource>
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->resources,
            fn ($resource) => $resource instanceof ParticleResource,
        ));
    }

    // ── Registration — gains the realm axis ─────────────────────────────────────────────────────────

    /**
     * Register a {@see ParticleResource} DECLARATION — the canonical stored form, servable over BOTH REST
     * ({@see get()}) and, when {@see ParticleResource::isFramed()}, Frame's manifest ({@see definitions()}).
     * Last-wins by key.
     *
     * @param  list<string>  $realms  the realm(s) this resource belongs to for Frame-manifest purposes.
     *                                Empty (default) ⇒ falls back to {@see loadRealmMap()}'s bulk map for
     *                                this key (today's `config('frame.realms')` membership); irrelevant
     *                                for a REST-only (non-framed) resource.
     */
    public function register(ParticleResource $resource, array $realms = []): void
    {
        $this->resources[$resource->key] = $resource;
        $this->registerRealms($resource->key, $realms);
    }

    /**
     * The ->registerDefinition() escape hatch: add an attribute-less resource imperatively as a raw
     * {@see ResourceDefinition} (e.g. a Frame union `source`). Stored as-is and served realm-invariant —
     * it carries no per-realm projection or overlay (there is no {@see ParticleResource} to project).
     *
     * @param  list<string>  $realms  see {@see register()}.
     */
    public function registerDefinition(ResourceDefinition $definition, array $realms = []): void
    {
        $this->resources[$definition->key] = $definition;
        $this->registerRealms($definition->key, $realms);
    }

    /**
     * Reflect a `#[ParticleResource]`-annotated Data class and register its DECLARATION.
     *
     * @param  class-string  $dataClass
     * @param  list<string>  $realms  see {@see register()}.
     */
    public function registerClass(string $dataClass, array $realms = []): void
    {
        if (! class_exists($dataClass)) {
            throw new InvalidArgumentException("Particle resource class [{$dataClass}] does not exist.");
        }

        $reflection = new ReflectionClass($dataClass);
        $attrs = $reflection->getAttributes(ParticleResourceAttribute::class);

        if ($attrs === []) {
            throw new InvalidArgumentException(
                "Class [{$dataClass}] is not annotated with #[ParticleResource]; use register() for attribute-less resources."
            );
        }

        // A `#[ParticleResource]`-annotated class is projected into a runtime ParticleResource
        // declaration (including its manifest fields) by the particle discovery — reflecting it here
        // (rather than going through a separate registry) keeps this the single resource producer.
        $this->register(AttributedParticleDiscovery::resourceFromAttribute($dataClass), $realms);
    }

    /**
     * Scan configured class-strings and discover-paths for `#[ParticleResource]` classes. Idempotent —
     * re-scanning overwrites by key, never duplicates.
     *
     * @param  array<int, class-string>  $classes  explicit resource class list
     * @param  array<int, string>  $paths  filesystem paths to scan for annotated classes
     * @param  list<string>  $realms  applied to every discovered class — see {@see register()}.
     */
    public function discover(array $classes = [], array $paths = [], array $realms = []): void
    {
        foreach ($classes as $class) {
            $this->registerClass($class, $realms);
        }

        foreach ($this->scanPaths($paths) as $class) {
            $this->registerClass($class, $realms);
        }
    }

    /**
     * Find `#[ParticleResource]`-annotated class-strings under the given paths — the live filesystem
     * walk. Delegates the generic file→FQCN→attribute machinery to popcorn's {@see AttributedClassScanner}.
     *
     * @param  array<int, string>  $paths
     * @return list<class-string>
     */
    public function scanPaths(array $paths): array
    {
        $scanner = new AttributedClassScanner;

        return array_values(array_unique(
            $scanner->scan($paths, ParticleResourceAttribute::class, instanceof: false),
        ));
    }

    /**
     * Bulk-seed the realm-membership fallback map — `realm => [keys]` (today's `config('frame.realms')`
     * shape). Consulted only for a key with no EXPLICIT realms named at its own {@see register()} call, so
     * a resource can migrate to declaring its own realms without a config edit, and one not yet migrated
     * keeps working off this map exactly as it does today. Additive across calls (last-wins per realm).
     *
     * @param  array<string, array<int, string>>  $membership
     */
    public function loadRealmMap(array $membership): self
    {
        foreach ($membership as $realm => $keys) {
            $this->realmMap[$realm] = array_values(array_unique(array_merge($this->realmMap[$realm] ?? [], $keys)));
        }

        return $this;
    }

    private function registerRealms(string $key, array $realms): void
    {
        if ($realms !== []) {
            $this->realms[$key] = $realms;
        }
    }

    // ── Frame's manifest projection — differently named from ResourceRegistry::get()/all() on purpose ──

    /**
     * Whether `$key` is a framed (Frame-manifest-eligible) resource — false for an unknown key AND for a
     * REST-only (non-framed) {@see ParticleResource} (acceptance #4: it has no Frame projection).
     */
    public function hasFramedResource(string $key): bool
    {
        $resource = $this->resources[$key] ?? null;

        if ($resource === null) {
            return false;
        }

        return ! ($resource instanceof ParticleResource && ! $resource->isFramed());
    }

    /**
     * Project ONE stored declaration into frame's {@see ResourceDefinition} for the given realm (default:
     * the identity/realm-agnostic projection). Recomputed at each call — the realm seam is live, not frozen.
     */
    public function definition(string $key, ?string $realm = null): ResourceDefinition
    {
        $resource = $this->resources[$key] ?? throw new InvalidArgumentException(
            "No frame resource registered for key [{$key}]."
        );

        if ($resource instanceof ParticleResource && ! $resource->isFramed()) {
            throw new InvalidArgumentException(
                "Resource [{$key}] is a REST-only particle resource; it has no Frame manifest projection."
            );
        }

        return $this->project($resource, $realm);
    }

    /**
     * The served manifest: flat sibling entries, insertion order — each DECLARATION projected into a
     * {@see ResourceDefinition}. `$realm === null` ⇒ every framed resource, unfiltered (a REST/tooling
     * caller that wants the whole catalog); a non-null realm additionally filters to resources whose
     * membership ({@see register()}'s `$realms`, falling back to {@see loadRealmMap()}) includes it — the
     * confinement `App\Http\Controllers\Api\Frame\FrameManifestController` used to do itself, post-hoc, by
     * re-deriving membership from a SEPARATE registry; it now lives where the declaration does.
     *
     * @return list<ResourceDefinition>
     */
    public function definitions(?string $realm = null): array
    {
        $manifest = [];

        foreach ($this->resources as $key => $resource) {
            if ($resource instanceof ParticleResource && ! $resource->isFramed()) {
                continue;
            }

            if ($realm !== null && ! in_array($realm, $this->realmsFor($key), true)) {
                continue;
            }

            $manifest[] = $this->project($resource, $realm);
        }

        return $manifest;
    }

    /**
     * The realm(s) a key belongs to: its own explicit {@see register()} membership if it named any,
     * else whatever {@see loadRealmMap()} says for it (today's config-driven mechanism).
     *
     * @return list<string>
     */
    private function realmsFor(string $key): array
    {
        if (isset($this->realms[$key])) {
            return $this->realms[$key];
        }

        $realms = [];
        foreach ($this->realmMap as $realm => $keys) {
            if (in_array($key, $keys, true)) {
                $realms[] = $realm;
            }
        }

        return $realms;
    }

    /**
     * Project a stored declaration for a realm: a {@see ParticleResource} runs its per-realm projection
     * ({@see ParticleResource::toResourceDefinition()} — same-package realm-conditional `query`/`scope`/
     * `policy`/etc., when the declaring resource author has written any); a raw {@see ResourceDefinition}
     * is served as-is (realm-invariant escape hatch, no declaration to project).
     *
     * Then the {@see RealmResourceRegistry} cross-layer PRESENTATION overlay is applied — a DIFFERENT
     * package/layer's `(realm, key)` override, inert (identity) when none is registered for this pair.
     */
    private function project(ParticleResource|ResourceDefinition $resource, ?string $realm): ResourceDefinition
    {
        $definition = $resource instanceof ParticleResource
            ? $resource->toResourceDefinition($realm)
            : $resource;

        return $this->overrides === null
            ? $definition
            : $this->overrides->apply($definition, $realm);
    }
}
