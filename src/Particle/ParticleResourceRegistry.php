<?php

namespace Splicewire\Beam\Particle;

use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Laddered;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RecordsSupersession;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registrars\AttributeRegistrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\Superseded;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Contribution\ContributionProjector;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
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
 *     forwarder ({@see ParticleResourceRegistryAdapter}) sits behind the port and calls
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
    entryType: ParticleResource::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Declared, not inherited: overwrite is intentional here and the class docblock argues it. '
        .'This said `mixed` on the grounds that an entry is a ParticleResource OR a raw '
        .'ResourceDefinition — ⚠️ STALE since the escape hatch was collapsed: the keyspace holds only '
        .'ParticleResource and `all()`\'s instanceof filter was deleted as the identity. '
        .'Registry-kernel ticket 47 caught it while measuring whether any registry in the estate holds '
        .'two entry types; none does, which is why `entryType` stayed a scalar. Whether the three '
        .'collections split into three registries '
        .'is registry-kernel ticket 36\'s — ANSWERED: they do not split. The composed BasicRegistry is '
        .'the keyspace; `$realms`/`$realmMap` are a two-rung membership ladder beside the entry, declared '
        .'through {@see Laddered}. Realm membership is a TAG recorded '
        .'beside the entry, never a second key dimension: one entry, many realms, no duplicate.',
    order: 12,
)]
class ParticleResourceRegistry implements Filled, Gated, Laddered, RecordsSupersession, Registry
{
    /**
     * The stored DECLARATIONS, keyed by resource key — always a {@see ParticleResource}, projected
     * per-realm at build.
     *
     * Held as a FIELD rather than inherited (registry-kernel ticket 01 D1): this class already carries
     * REST vocabulary (`get()`/`has()`/`all()`) and Frame's manifest projection
     * (`definition()`/`definitions()`) that no kernel base class could supply, and it composes the
     * realm ladder beside the keyspace.
     */
    private BasicRegistry $entries;

    /** @var list<Registrar> */
    private array $registrars = [];

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
     * @param  ?ResourceContributionRegistry  $contributions  cross-package slices of a resource's read
     *                                                        projection (particle-contribution-seam ticket
     *                                                        04). Null or empty ⇒ INERT: {@see get()}
     *                                                        returns the stored declaration itself.
     */
    public function __construct(
        private ?RealmResourceRegistry $overrides = null,
        private ?ResourceContributionRegistry $contributions = null,
    ) {
        $this->entries = BasicRegistry::for($this);
    }

    // ── REST tier (unchanged signatures — every existing caller of this registry is unaffected) ───────

    /**
     * The stored declaration for `$key`, with any contributed STATIC includes folded into its
     * {@see ParticleResource::$includes}.
     *
     * ## Why the fold is here and not on the backing
     *
     * This is where BOTH transports read `$resource->includes` — REST at
     * {@see \Splicewire\Beam\Http\Particle\ParticleController} (index, subject resolution, detail
     * reload) and Frame at {@see ParticleFrameResourceHandler} (its index query and subject-resolution
     * base). {@see project()}, the sibling seam where the per-realm overlay applies, is the MANIFEST path
     * and reaches neither of them — which is why the `RealmResourceRegistry` precedent this fold was
     * modelled on did not transfer and had to be rebuilt here (ticket 04 §A0).
     *
     * Ticket 11 §A12 settled where the includes FIELD lives; it did not settle where contributions
     * COMPOSE, and the two are different questions.
     *
     * ## Still a pure declaration lookup
     *
     * Deliberately only the STATIC arm. A contribution whose includes are a Closure is a
     * request-parameterized constrained eager-load, and `get(string $key)` has no request to resolve it
     * against — folding it here would mean either inventing a facet bag or resolving per record. It
     * resolves once per request in {@see ParticleListQuery::forList()} instead (ticket 05 §A4).
     *
     * Inert by construction: with no contribution registered for `$key` the stored instance is returned
     * as-is, not a clone, so the overwhelmingly common case costs one array lookup exactly as before.
     */
    public function get(string $key): ParticleResource
    {
        return $this->find($key)
            ?? throw new RuntimeException("No particle resource registered for key [{$key}].");
    }

    /**
     * The nullable half of the miss pair — {@see get()} without the throw, contributions folded identically.
     *
     * The REST tier is right to demand: a request that reached {@see ParticleController} for an
     * unregistered key cannot be served, and 500 is the honest answer. A READER that merely wants to
     * *describe* the key is not in that position. Whether `guest-links` names a registered particle
     * resource **on this host** is a fact about the host, not something the declaration's author could
     * have gotten right (AGENTS.md, "a check whose answer depends on the host must not throw"), and the
     * Scribe strategies that ask it were turning one host's registration gap into 30 endpoints silently
     * missing from `openapi.yaml` (api-surface-coherence 102).
     *
     * The sibling registry has had this since it was written — {@see ParticleOperationRegistry::find()},
     * whose caller's docblock states the principle: a route mounted for an unregistered thing is *a
     * reportable absence, not a reason to fail an entire spec build*. This is the resource tier's copy of
     * it. The absence is reported by {@see \Splicewire\Beam\Doctor\ParticleRouteResourceAudit}.
     */
    public function find(string $key): ?ParticleResource
    {
        $resource = $this->lookup($key);

        if ($resource === null) {
            return null;
        }

        if ($this->contributions === null || ! $this->contributions->contributesTo($key)) {
            return $resource;
        }

        $contributed = (new ContributionProjector($this->contributions))->staticIncludes($key);

        if ($contributed === []) {
            return $resource;
        }

        $folded = clone $resource;
        $folded->includes = array_values(array_unique([...$resource->includes, ...$contributed]));

        return $folded;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->lookup($key) !== null;
    }

    /**
     * A READ that answers "absent" for a key that is not even a legal address, rather than throwing.
     *
     * Registration still throws on a malformed key — a declaration that cannot be addressed is a defect
     * and must be loud. A LOOKUP is the other way round: `get('Not A Key')` reaching this registry means
     * a caller asked about something that is not here, and `InvalidRegistryKey` would turn every such
     * miss into a 500 where the resource-not-registered path already exists.
     */
    private function lookup(RegistryKey|string $key): ?ParticleResource
    {
        if (is_string($key) && Key::tryParse($key) === null) {
            return null;
        }

        return $this->entries->tryResolve($key);
    }

    /**
     * Every registered DECLARATION, registration order — for auditors walking the declared set rather
     * than looking one up (the schema-projection drift audit, particle-doctrine-followups 14).
     *
     * This used to filter `instanceof ParticleResource` to exclude raw `ResourceDefinition` escape-hatch
     * entries, on the grounds that they carried no Data class to audit — ⚠️ which was never true
     * (`ResourceDefinition::$data` is required and non-nullable), so the drift audit was blind to exactly
     * the resources this map suspected. Under one declaration type the filter is the identity and goes.
     *
     * @return list<ParticleResource>
     */
    public function all(): array
    {
        return $this->entries->matches($this->entries->root());
    }

    // ── The kernel contract (registry-kernel ticket 53) ─────────────────────────────────────────────

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    /** @return list<ParticleResource> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->entries = $this->entries->unfiltered();

        return $unfiltered;
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * Attach a registrar and let it fill THIS registry — not the composed store — now.
     *
     * The one live subject of {@see Filled}'s eager-at-boot ordering claim in the estate
     * (registry-kernel ticket 19 D3, paid on ticket 53): `BeamServiceProvider::discoverResources()`
     * attaches an {@see AttributeRegistrar} in beam's own
     * `boot()`, so a consumer provider that hand-registers the same key afterwards lands second and
     * wins by {@see OnDuplicate::Supersede} alone — no tier, no branch, no precedence rule.
     *
     * ⚠️ The delegation trap, measured while landing that: `$this->entries->attach($r)` reads naturally
     * and is wrong, because `BasicRegistry::attach()` hands the registrar the STORE, so every write
     * bypasses this class's own `register()` — and with it the affordance-vs-capability assertion and
     * the realm ladder. A composing owner attaches to ITSELF and keeps the registrar list; only the
     * eagerness is inherited.
     */
    public function attach(Registrar $registrar): void
    {
        $this->registrars[] = $registrar;

        $registrar->fill($this);
    }

    /** @return list<Registrar> */
    public function registrars(): array
    {
        return $this->registrars;
    }

    /**
     * The entries displaced at `$key`, oldest first.
     *
     * Declared here rather than left on the composed store because it is what makes this registry's
     * `Supersede` ordering OBSERVABLE from outside — a registrar's entry losing to a consumer's
     * hand-registration is a fact a reader can check, not an inference from boot order
     * (registry-kernel ticket 19 D4's unmet acceptance item, which ticket 48 owns the reading surface
     * for; this is the one registry that can now answer it).
     *
     * @return list<Superseded>
     */
    public function superseded(RegistryKey|string $key): array
    {
        return $this->entries->superseded($key);
    }

    // ── Registration — gains the realm axis ─────────────────────────────────────────────────────────

    /**
     * Register a {@see ParticleResource} DECLARATION — the canonical stored form, servable over BOTH REST
     * ({@see get()}) and, when {@see ParticleResource::isFramed()}, Frame's manifest ({@see definitions()}).
     * Last-wins by key.
     *
     * ## Two spellings, one method (registry-kernel ticket 53)
     *
     * The parameter is WIDENED from {@see Registry::register()} rather than shadowing it
     * (contravariance), exactly as `RealmRegistry` does: the historical self-keying call
     * `register($resource, ['operator'])` keeps working — a `ParticleResource` carries its own key, so
     * the second argument is its realm list — and the contract spelling
     * `register('songs', $resource, by: …)` works too, which is what lets a
     * {@see Registrar} fill this registry at all.
     *
     * @param  RegistryKey|string|ParticleResource  $key  the resource key, or the self-keying declaration
     * @param  mixed  $entry  the declaration when `$key` is a key; the `list<string>` of realms when
     *                        `$key` is the declaration itself (the historical two-argument call).
     *                        Realms empty/absent ⇒ falls back to {@see loadRealmMap()}'s bulk map for
     *                        this key (today's `config('frame.realms')` membership); irrelevant for a
     *                        REST-only (non-framed) resource.
     */
    public function register(RegistryKey|string|ParticleResource $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof ParticleResource) {
            $resource = $key;
            $realms = is_array($entry) ? $entry : [];
        } else {
            $resource = $entry;
            $realms = [];

            if (! $resource instanceof ParticleResource) {
                throw new InvalidArgumentException(sprintf(
                    'ParticleResourceRegistry stores ParticleResource declarations; `%s` was given for key [%s].',
                    get_debug_type($entry),
                    (string) $key,
                ));
            }
        }

        // Capability is the CEILING; the affordance flags narrow it (ticket 11 §A5). A resource opening
        // an affordance its backing cannot honour is a DECLARATION error, caught here at registration
        // rather than as a runtime failure on the first write — the shape
        // `ParticleOperation::assertOutputMatchesKind()` already ships. Reads the backing statically, so
        // registration never resolves it (that stays request-time, so a backing may take injection).
        (new BackingResolver)->assertAffordancesWithinCapability($resource->key, $resource->backing, [
            'creatable' => ! $resource->readOnly,
            'editable' => $resource->editable ?? ! $resource->readOnly,
            'deletable' => $resource->deletable ?? ! $resource->readOnly,
        ]);

        $this->entries->register($resource->key, $resource, $by, $ability);
        $this->registerRealms($resource->key, $realms);

        return $this;
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
        $resource = $this->lookup($key);

        if ($resource === null) {
            return false;
        }

        return $resource->isFramed();
    }

    /**
     * Project ONE stored declaration into frame's {@see ResourceDefinition} for the given realm (default:
     * the identity/realm-agnostic projection). Recomputed at each call — the realm seam is live, not frozen.
     */
    public function definition(string $key, ?string $realm = null): ResourceDefinition
    {
        $resource = $this->lookup($key) ?? throw new InvalidArgumentException(
            "No frame resource registered for key [{$key}]."
        );

        if (! $resource->isFramed()) {
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

        foreach ($this->all() as $resource) {
            $key = $resource->key;

            if (! $resource->isFramed()) {
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
    /**
     * The realm-membership tiers, outermost first (registry-kernel ticket 36). Declarative only —
     * {@see realmsFor()} is the one thing that climbs them, and the kernel never does.
     *
     * @return non-empty-list<string>
     */
    public function rungs(): array
    {
        return ['explicit', 'realm-map'];
    }

    /**
     * PUBLIC because it is the declared authority, and a declared authority nothing can call is not one.
     *
     * Membership had three readers reaching it three different ways: the manifest through
     * {@see definitions()} (i.e. through here, the ladder), and the host's Frame CRUD gate and route-context
     * builder through a SECOND registry seeded straight from `config('frame.realms')` — plus a nav
     * capability reading that config raw, through neither. Nothing had diverged, because all three bottomed
     * out in the same config key and the `explicit` rung has never been used by any call site in the estate.
     * The divergence was gated entirely on that emptiness: the first resource to name its realms at
     * registration would have been manifest-visible, nav-invisible, and 404 on every read and write.
     *
     * @return list<string>
     */
    public function realmsFor(string $key): array
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
     * The inverse of {@see realmsFor()}: every REGISTERED resource key whose membership includes `$realm`.
     *
     * Three properties, each load-bearing rather than incidental:
     *
     * - **No `isFramed()` filter.** {@see definitions()} skips non-framed resources because a manifest is a
     *   nav/editor surface; membership is not. The host's CRUD gate confines keys that need not be framed
     *   at all, and conflating the two would silently 404 every REST-only member of a realm.
     * - **Iterates {@see all()}, not the realm map.** The map is raw config and can name a key nothing ever
     *   registered; this returns the intersection, so a typo in host config stops being a resource the gate
     *   admits and the registry cannot serve.
     * - **Computed on read, never cached.** A resource registered after the first call must appear. Same
     *   rule the event catalog learned the hard way: stamping at registration records load order as truth.
     *
     * @return list<string>
     */
    public function keysForRealm(string $realm): array
    {
        $keys = [];

        foreach ($this->all() as $resource) {
            if (in_array($realm, $this->realmsFor($resource->key), true)) {
                $keys[] = $resource->key;
            }
        }

        return $keys;
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
    private function project(ParticleResource $resource, ?string $realm): ResourceDefinition
    {
        $definition = $resource->toResourceDefinition($realm);

        return $this->overrides === null
            ? $definition
            : $this->overrides->apply($definition, $realm);
    }
}
