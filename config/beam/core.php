<?php

use Splicewire\Beam\Models\BeamParticle;

return [

    /*
    |--------------------------------------------------------------------------
    | beam substrate
    |--------------------------------------------------------------------------
    | The app-substrate rung. This config is intentionally near-empty at mint:
    | beam boots headless and the leaf-extraction tickets (07-10) populate it as
    | the generic schema-record / media traits and host-hook registries land.
    |
    | beam depends on nothing above it — frame (the editor rung) depends on beam,
    | never the reverse (ADR-0082). Keep host/editor concerns out of this file.
    */

    /*
    | Swappable models (Spatie swappable-model pattern). A host that composes the beam
    | traits on its own particle model points this at its subclass.
    |
    | The generic REFERENCE model was retired (ADR-0138 amendment): a submission is
    | exactly one thing — a BeamSubmission (a beam particle) homed in beam-core. The
    | two-model split was created-but-never-read; the FC-15 reference precedent is reversed. Renamed
    | record → particle (ADR-0151); the retired `SchemaRecord` reverse-alias shim was
    | removed at T09, so this key names the canonical BeamParticle directly.
    */
    'models' => [
        'particle' => BeamParticle::class,
    ],

    /*
    | Table names, illustrative. The AUTHORITATIVE resolver is Beam::table($name) =
    | `table_prefix . $name` (below / ADR-0151) — a model's getTable() calls it, it does not
    | read this list. "shared" means shared CODE, not a shared database — every app that
    | consumes beam gets its own tables; a multi-tenant host owns tenant-guarded copies so
    | particles land in the tenant schema, not central.
    */
    'tables' => [
        'particles' => 'beam_particles',
    ],

    /*
    | The optional generic PUBLIC INTAKE door (beam-write-pipeline ticket 04 / ADR-0150). A host mounts
    | this to accept anonymous form submissions with no controller of its own — or leaves it OFF and
    | calls RecordWriter from its own controller. It is deny-by-default: nothing is anonymously writable
    | unless a schema stem is explicitly listed in `public_schemas`.
    */
    'intake' => [
        // Mount POST /beam/intake/{form}. Off by default — the door is opt-in.
        'enabled' => false,

        // URL-safe form slug => the schema stem (or absolute $id) it resolves. The route addresses a
        // form by its slug; the slug maps to a registered schema. A slug absent here (and not itself a
        // resolvable stem) is a 404. Being addressable is NOT being public — see `public_schemas`.
        'forms' => [],

        // The allow-list: schema stems (or versioned $ids) a stranger may submit. Empty ⇒ nothing is
        // publicly submittable (the safe default; a bad default here would silently open write access).
        // A form that resolves but is absent here is refused (403) by the deny-default gate.
        'public_schemas' => [],

        // Opt-in honeypot bot defence, default OFF — never silently imposed.
        'honeypot' => [
            'enabled' => false,
            'field' => 'website',
        ],

        // Route throttle, "{maxAttempts},{decayMinutes}".
        'throttle' => '5,1',
    ],

    /*
    | RETROFIT SEAM (beam-particle-rename ticket 01). Every Beam table name is `table_prefix . $name`,
    | resolved in ONE place ({@see \Splicewire\Beam\Beam::table()}). A greenfield/satellite host keeps
    | the default `beam_`; a RETROFIT host dropping Beam into a pre-existing Laravel app changes this ONE
    | value (`''`, `acme_beam_`, …) so Beam's generic-noun tables never collide with the host's own
    | `posts`/`teams`/`categories`. No table is renamed by this knob yet — later tickets route model
    | `getTable()` + migrations through the helper.
    */
    'table_prefix' => 'beam_',

    /*
    | The schema-definition store's read/write source order (consumed by the registry collapse, T02):
    | an ordered list, e.g. ['db','file'] (DB-first, filesystem fallback) or ['file'] (filesystem only).
    | The trio of Filesystem/Database/Chained registries collapses onto one config-driven class reading
    | this. Additive here; no behaviour change until T02.
    */
    'schema' => [
        'sources' => ['db', 'file'],
    ],

    /*
    | Tenancy mode, answered by the `splicewire:beam:install` wizard (beam-particle-rename ticket 10): "single" (one
    | database) or "multi" (tenant-scoped). Declarative only — a multi-tenant host still wires its own
    | tenancy package; this records the operator's intent so tooling can branch on it.
    */
    'tenancy' => 'single',

    /*
    | Attributed realms (realm-architecture ticket 08 slice D). The RealmRegistry ships three imperative
    | base realms (operator·tenant·user); a host CONTRIBUTES more — or overrides a base one — by placing a
    | `#[Realm]`-family attribute (`#[OperatorRealm]`/`#[UserRealm]`/`#[TenantRealm]`, or the generic
    | `#[Realm]`) on a realm-marker class and listing it here. Boot registration reflects each into a
    | RealmDefinition and registers it additively (last-wins by key). Realms are a small fixed set, so
    | this is an EXPLICIT class list — no filesystem scan (the retired RealmDiscovery did one).
    */
    'realms' => [
        // Explicit realm-marker class-strings to reflect and register at boot.
        'classes' => [],
    ],

    /*
    | Realm entitlement gates (Frame OS ticket 08, ADR-0013 §4/§5). The RealmDefinition DTO carries no
    | gating (it is a shared schemastud shape); realm→entitlement gating rides HERE, keyed by realm key,
    | and is read by the RealmManifestProjector. Each entry: [ 'entitlement' => key, 'mode' => 'hard'|'soft',
    | 'upsell' => [...]? ]. Empty (the default) gates NOTHING — every realm always projects, so an existing
    | host stays byte-for-byte until it declares gates. Two modes:
    |
    |  - 'hard' → protection by construction: an unentitled principal does NOT see the realm at all (the
    |    descriptor is OMITTED from the projected manifest).
    |  - 'soft' → monetization: an unentitled principal STILL sees the realm, carrying `locked: true` + the
    |    `upsell` metadata, so a launcher can render it as lockable.
    |
    | Example:
    |   'operator' => ['entitlement' => 'app-operator', 'mode' => 'hard'],
    |   'studio' => ['entitlement' => 'go-songwriter', 'mode' => 'soft',
    |               'upsell' => ['title' => 'Go Songwriter', 'cta' => 'Upgrade']],
    */
    'realm_gates' => [],

    /*
    | Entitlement (feature-plane) wiring (Frame OS ticket 08, ADR-0013 §2). beam is the authority that
    | unifies the two authorization planes: it registers a Laravel Gate ability per known feature key
    | (`entitlement:{key}`) delegating to the entitlement gate, which consults the bound kernel
    | EntitlementResolver. The key universe is `array_keys(config('app.entitlements'))` ∪ these extra
    | beam-known feature keys (for a key a host has not mirrored into `app.entitlements`). Empty by default.
    */
    'entitlements' => [
        'keys' => [],
    ],

    /*
    | Attributed REST particle discovery (ADR-0116/0160) — the runtime twin of the #[ParticleResource]
    | `resources` config. A host declares a REST resource / named op ON its Data class with
    | `#[ParticleResource]` / `#[ParticleOp]` and lists the class here (or points `discover_paths` at its
    | Data directory) instead of hand-registering from a provider. Closures the attribute can't carry are
    | resolved from `public static` convention methods on the annotated class (scope/project/prepare/
    | afterWrite; handle/respond for an op). Empty by default — the imperative provider registration seam
    | still works and the two coexist. UNLIKE #[ParticleResource], this has no build-cache yet: a `discover_paths`
    | scan walks the filesystem each boot, so prefer the explicit `classes` list until the cache lands.
    */
    'particle' => [
        // Explicit #[ParticleResource]/#[ParticleOp] class-strings to reflect and register at boot.
        'classes' => [],

        // Filesystem paths scanned for annotated classes (dev convenience; no boot cache yet).
        'discover_paths' => [],
    ],

    // 'media'         => [ ... ]   // (ticket 08)
    // 'hooks'         => [ ... ]   // (webhook / sitemap / doctor registries)

];
