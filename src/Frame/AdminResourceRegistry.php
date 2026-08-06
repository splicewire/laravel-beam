<?php

namespace Splicewire\Beam\Frame;

use InvalidArgumentException;
use ReflectionClass;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Frame\Attributes\AdminResource;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Realm\RealmResourceRegistry;

/**
 * Beam's resource DECLARATION registry — the producer behind frame's agnostic
 * {@see ResourceRegistry} port. It owns the resource opinion frame refuses to carry (ADR-0156):
 * the boot scan reflects annotated Data classes into a beam DECLARATION, and frame's manifest
 * machinery serves the {@see ResourceDefinition}s it projects.
 *
 * ## Stores declarations, projects PER-REALM at build (RDU-02)
 *
 * The registry no longer FREEZES one {@see ResourceDefinition} per key at registration time. It
 * stores the resource **declaration** — a {@see ParticleResource} (the one class describing an
 * editable, model-backed resource, RDU-01) or, for the imperative/union escape hatch, an
 * already-built {@see ResourceDefinition} — and calls {@see ParticleResource::toResourceDefinition()}
 * with the target realm when the manifest for a realm is built ({@see self::all()} / {@see self::get()}).
 * The realm seam is therefore LIVE: the projection is recomputed at build, not baked at registration.
 * After the realm-agnostic projection, the {@see RealmResourceRegistry} presentation-override overlay
 * (RDU-03) is applied for the target realm — INERT (identity) by default, so with no overlay configured a
 * resource appears in the manifest exactly as before, identically in every realm.
 *
 * ## Discovery is unified onto `#[ParticleResource]`, with a temporary `#[AdminResource]` dual-read
 *
 * {@see self::registerClass()} reads whichever attribute an annotated class carries:
 *   - `#[ParticleResource]` — the unified path (RDU); reflected into a {@see ParticleResource}
 *     declaration via {@see AttributedParticleDiscovery}.
 *   - `#[AdminResource]` — the legacy path, kept TEMPORARILY as a DUAL-READ so existing annotated
 *     classes keep registering. A model-backed `#[AdminResource]` becomes a {@see ParticleResource}
 *     declaration too (so it projects per-realm); a `source`-backed (union) one has no model, so it
 *     stays a frozen {@see ResourceDefinition}. RDU-07 deletes this dual-read (and the attribute).
 *
 * Plus a {@see self::register()} escape hatch for attribute-less resources (a raw `ResourceDefinition`,
 * e.g. a Frame union `source`). Beam binds this onto frame's `ResourceRegistry::class` port so frame
 * never imports a beam type — the arrow points DOWN (beam → frame), never up.
 */
class AdminResourceRegistry implements ResourceRegistry
{
    /**
     * The stored DECLARATIONS, keyed by resource key — a {@see ParticleResource} (projected
     * per-realm at build) or a raw {@see ResourceDefinition} (the imperative/union escape hatch,
     * served as-is; realm-invariant by construction).
     *
     * @var array<string, ParticleResource|ResourceDefinition>
     */
    private array $declarations = [];

    /**
     * The per-realm presentation-override overlay layer (RDU-03). Applied AFTER a declaration projects
     * into a {@see ResourceDefinition} for a realm — so a realm may present the same resource differently
     * (label/group/form/read-only gate) without the declaration naming a realm. Null (the bare test ctor)
     * or an empty overlay registry ⇒ INERT: identity projection in every realm.
     */
    private ?RealmResourceRegistry $overrides;

    public function __construct(?RealmResourceRegistry $overrides = null)
    {
        $this->overrides = $overrides;
    }

    /**
     * Reflect an attributed Data class and register its DECLARATION. Reads whichever of the two
     * attributes the class carries — the unified `#[ParticleResource]` or the legacy `#[AdminResource]`
     * (dual-read, RDU-07 removes it).
     *
     * @param  class-string  $dataClass
     */
    public function registerClass(string $dataClass): void
    {
        if (! class_exists($dataClass)) {
            throw new InvalidArgumentException("Admin resource class [{$dataClass}] does not exist.");
        }

        $reflection = new ReflectionClass($dataClass);

        // Unified path: a `#[ParticleResource]`-annotated class is projected into a runtime
        // ParticleResource declaration (including its manifest fields) by the particle discovery, and
        // that runtime object is handed to us. Reflecting it here (rather than going through the
        // particle registry) keeps this the single admin-manifest producer.
        if ($this->readParticleAttribute($reflection) !== null) {
            $this->registerDeclaration(self::particleFromAttribute($dataClass));

            return;
        }

        // Legacy dual-read (RDU-07 deletes this branch): a `#[AdminResource]`-annotated class.
        $attribute = $this->readAdminAttribute($reflection);

        if ($attribute === null) {
            throw new InvalidArgumentException(
                "Class [{$dataClass}] is not annotated with #[ParticleResource] or #[AdminResource]; use register() for attribute-less resources."
            );
        }

        $hasModel = $attribute->model !== null;
        $hasSource = $attribute->source !== null;

        if ($hasModel === $hasSource) {
            throw new InvalidArgumentException(
                "Admin resource [{$attribute->key}] must set exactly one of `model` or `source`; "
                .($hasModel ? 'both were set.' : 'neither was set.')
            );
        }

        // A model-backed #[AdminResource] becomes a ParticleResource DECLARATION, so it projects
        // per-realm like every unified resource. A source-backed (union) one has no model — it can't
        // be a ParticleResource — so it stays a frozen ResourceDefinition (realm-invariant).
        if ($hasModel) {
            $this->registerDeclaration(self::particleFromAdminAttribute($dataClass, $attribute));

            return;
        }

        $this->register(self::toDefinition($dataClass, $attribute));
    }

    /**
     * Register a {@see ParticleResource} DECLARATION — the canonical stored form. Projected per-realm
     * at manifest-build time ({@see self::all()} / {@see self::get()}). Last-wins by key.
     */
    public function registerDeclaration(ParticleResource $declaration): void
    {
        $this->declarations[$declaration->key] = $declaration;
    }

    /**
     * The ->register() escape hatch: add an attribute-less resource imperatively as a raw
     * {@see ResourceDefinition} (e.g. a Frame union `source`). Stored as-is and served realm-invariant
     * — it carries no per-realm projection.
     */
    public function register(ResourceDefinition $definition): void
    {
        $this->declarations[$definition->key] = $definition;
    }

    /**
     * Scan configured class-strings and discover-paths for `#[ParticleResource]` / `#[AdminResource]`
     * classes. Idempotent — re-scanning overwrites by key, never duplicates.
     *
     * @param  array<int, class-string>  $classes  explicit resource class list
     * @param  array<int, string>  $paths  filesystem paths to scan for annotated classes
     */
    public function discover(array $classes = [], array $paths = []): void
    {
        foreach ($classes as $class) {
            $this->registerClass($class);
        }

        foreach ($this->scanPaths($paths) as $class) {
            $this->registerClass($class);
        }
    }

    public function has(string $key): bool
    {
        $declaration = $this->declarations[$key] ?? null;

        if ($declaration === null) {
            return false;
        }

        // A REST-only (non-framed) particle declaration is not a manifest resource (acceptance #4).
        return ! ($declaration instanceof ParticleResource && ! $declaration->isFramed());
    }

    /**
     * Project ONE stored declaration into frame's {@see ResourceDefinition} for the given realm
     * (default: the identity realm). Recomputed at each call — the realm seam is live, not frozen.
     */
    public function get(string $key, ?string $realm = null): ResourceDefinition
    {
        $declaration = $this->declarations[$key] ?? throw new InvalidArgumentException(
            "No frame resource registered for key [{$key}]."
        );

        // A REST-only (non-framed) ParticleResource declaration is not a manifest resource — it has no
        // Frame projection (acceptance #4). Asking for it by key is the same "not registered" answer.
        if ($declaration instanceof ParticleResource && ! $declaration->isFramed()) {
            throw new InvalidArgumentException(
                "Resource [{$key}] is a REST-only particle resource; it has no Frame manifest projection."
            );
        }

        return $this->project($declaration, $realm);
    }

    /**
     * The served manifest: flat sibling entries, insertion order — each DECLARATION projected into a
     * {@see ResourceDefinition} for the given realm (default: the identity realm) at build time. A
     * REST-only (non-framed) {@see ParticleResource} declaration is SKIPPED: it has no Frame projection,
     * so it never appears in the manifest (acceptance #4 — no behavior change vs today, where REST-only
     * resources live in the separate particle registry).
     *
     * @return list<ResourceDefinition>
     */
    public function all(?string $realm = null): array
    {
        $manifest = [];

        foreach ($this->declarations as $declaration) {
            if ($declaration instanceof ParticleResource && ! $declaration->isFramed()) {
                continue;
            }

            $manifest[] = $this->project($declaration, $realm);
        }

        return $manifest;
    }

    /**
     * Project a stored declaration for a realm: a {@see ParticleResource} runs its per-realm
     * projection; a raw {@see ResourceDefinition} is served as-is (realm-invariant escape hatch).
     */
    private function project(ParticleResource|ResourceDefinition $declaration, ?string $realm): ResourceDefinition
    {
        $definition = $declaration instanceof ParticleResource
            ? $declaration->toResourceDefinition($realm)
            : $declaration;

        // RDU-03: overlay the realm's presentation overrides AFTER the realm-agnostic projection —
        // `$overrides->apply($particle->toResourceDefinition($realm), $realm)`. Inert (identity) when no
        // overlay is registered for this `(realm, key)`, so a raw ResourceDefinition escape hatch and a
        // no-overlay install are both returned unchanged.
        return $this->overrides === null
            ? $definition
            : $this->overrides->apply($definition, $realm);
    }

    /**
     * Reflect a `#[ParticleResource]`-annotated class into a runtime {@see ParticleResource}
     * DECLARATION (carrying its manifest fields + hook closures), via the particle discovery — so the
     * unified attribute is read exactly once, in one place.
     *
     * @param  class-string  $dataClass
     */
    private static function particleFromAttribute(string $dataClass): ParticleResource
    {
        return AttributedParticleDiscovery::resourceFromAttribute($dataClass);
    }

    /**
     * Bridge a model-backed legacy `#[AdminResource]` into a {@see ParticleResource} DECLARATION, so
     * it projects per-realm like every unified resource (RDU-07 removes this bridge with the attribute).
     * The annotated class itself is the read `data` (the single-class default).
     *
     * @param  class-string  $dataClass
     */
    private static function particleFromAdminAttribute(string $dataClass, AdminResource $attribute): ParticleResource
    {
        return new ParticleResource(
            key: $attribute->key,
            model: $attribute->model,
            data: $dataClass,
            label: $attribute->label,
            form: $attribute->form,
            editData: $attribute->editData,
            policy: $attribute->policy,
            query: $attribute->query,
            group: $attribute->group,
            icon: $attribute->icon,
            section: $attribute->section,
            navOrder: $attribute->navOrder,
            routeName: $attribute->routeName,
            layout: $attribute->layout,
            readOnly: $attribute->readOnly,
            deletable: $attribute->deletable,
            editable: $attribute->editable,
            // An #[AdminResource] is navigable/framed by construction (it carries a label); force it so
            // even a label-less legacy declaration still projects as framed.
            frame: true,
        );
    }

    /**
     * Project a `source`-backed (union) legacy `#[AdminResource]` into frame's agnostic
     * {@see ResourceDefinition}. A `source` yields a `service` union resource (model null,
     * editData/query preserved for the create-schema escape hatch, not creatable). Model-backed
     * #[AdminResource]s no longer route through here — they become {@see ParticleResource}s
     * ({@see self::particleFromAdminAttribute()}).
     *
     * @param  class-string  $dataClass
     */
    public static function toDefinition(string $dataClass, AdminResource $attribute): ResourceDefinition
    {
        $nav = new NavMetadata(
            label: $attribute->label,
            group: $attribute->group,
            icon: $attribute->icon,
            section: $attribute->section,
            navOrder: $attribute->navOrder,
            routeName: $attribute->routeName,
        );

        if ($attribute->source !== null) {
            return new ResourceDefinition(
                key: $attribute->key,
                sourceKind: 'service',
                model: null,
                source: $attribute->source,
                data: $dataClass,
                creatable: false,
                query: null,
                // A service-backed resource is not writable THROUGH Frame (store/update/destroy 405), but
                // it may still carry an `editData` create-SCHEMA escape hatch (ADR-0156 §83): the schema
                // endpoint emits `editData ?? data`, and a host may render that create form while SUBMITTING
                // it to a survivor REST endpoint (e.g. tenant provisioning). Purely a schema surface — it
                // grants no Frame write path (creatable/editable/deletable stay false). Default null ⇒ every
                // existing source-backed resource (review-queue, members) is unchanged.
                editData: $attribute->editData,
                policy: $attribute->policy,
                form: $attribute->form,
                nav: $nav,
                layout: $attribute->layout,
                deletable: false,
                editable: false,
            );
        }

        return new ResourceDefinition(
            key: $attribute->key,
            sourceKind: 'model',
            model: $attribute->model,
            source: null,
            data: $dataClass,
            creatable: ! $attribute->readOnly,
            query: $attribute->query,
            editData: $attribute->editData,
            policy: $attribute->policy,
            form: $attribute->form,
            nav: $nav,
            layout: $attribute->layout,
            deletable: $attribute->deletable ?? ! $attribute->readOnly,
            editable: $attribute->editable ?? ! $attribute->readOnly,
        );
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function readAdminAttribute(ReflectionClass $class): ?AdminResource
    {
        $attrs = $class->getAttributes(AdminResource::class);

        return empty($attrs) ? null : $attrs[0]->newInstance();
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function readParticleAttribute(ReflectionClass $class): ?ParticleResourceAttribute
    {
        $attrs = $class->getAttributes(ParticleResourceAttribute::class);

        return empty($attrs) ? null : $attrs[0]->newInstance();
    }

    /**
     * Find `#[ParticleResource]` / `#[AdminResource]`-annotated class-strings under the given paths —
     * the live filesystem walk. Delegates the generic file→FQCN→attribute machinery to popcorn's
     * {@see AttributedClassScanner}. Only classes carrying one of the attributes are returned (others
     * ignored, not errors), so a discover path may point at a whole Data directory. `instanceof: false`
     * preserves the original exact-match behaviour.
     *
     * @param  array<int, string>  $paths
     * @return list<class-string>
     */
    public function scanPaths(array $paths): array
    {
        $scanner = new AttributedClassScanner;

        return array_values(array_unique([
            ...$scanner->scan($paths, ParticleResourceAttribute::class, instanceof: false),
            ...$scanner->scan($paths, AdminResource::class, instanceof: false),
        ]));
    }
}
