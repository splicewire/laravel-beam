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
    | this to accept anonymous intake submissions with no controller of its own — or leaves it OFF and
    | calls RecordWriter from its own controller. It is deny-by-default: nothing is anonymously writable
    | unless a schema stem is explicitly listed in `public_schemas`.
    */
    'intake' => [
        // Mount POST /beam/intake/{schema}. Off by default — the door is opt-in.
        'enabled' => false,

        // URL-safe slug => the schema stem (or absolute $id) it resolves. The route addresses an intake
        // surface by its slug; the slug maps to a registered schema. A slug absent here (and not itself
        // a resolvable stem) is a 404. Being addressable is NOT being public — see `public_schemas`.
        //
        // Named `forms` until beam-facade ticket 126. There is no such thing as a form schema (ticket
        // 41) — a form is a rendering/intake MODE of a schema — so a key holding "the forms" was the
        // last surviving fragment, in config, of the slug→schema registry that ticket deleted three
        // host forks of. `slugs` is the vocabulary this map's own reader already used. A host still
        // publishing the old key is REPORTED by IntakeDoorAudit rather than silently ignored, because
        // the failure mode is a 404 on every submission.
        'slugs' => [],

        // The allow-list: schema stems (or versioned $ids) a stranger may submit. Empty ⇒ nothing is
        // publicly submittable (the safe default; a bad default here would silently open write access).
        // A schema that resolves but is absent here is refused (403) by the deny-default gate.
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
    | The OpenAPI artifact beam serves at `beam/openapi.yaml` + `beam/openapi.json` (ADR-0211). Both URLs
    | are FIXED and package-owned — a docs page links them with route('beam.openapi.yaml') — and both are
    | mounted unconditionally, because with no artifact on disk they simply 404. Mounting opens nothing.
    |
    | Generation happens at INSTALL and at DEPLOY, never on request: extraction reflects over every route
    | in the app, and a public GET must not write to storage.
    */
    'openapi' => [
        // Where the generated spec lives on DISK — a path, not a URL, so it does not reopen the public
        // docs-path question (that comes from entry containment, never config).
        //
        // NULL ⇒ derive it, which is the right default and not merely a lazy one: Scribe writes through
        // `Storage::disk('local')`, and that disk is rooted at `storage/app/private` on a Laravel 11+
        // skeleton and `storage/app` on an older one. A hardcoded literal is wrong on one of them (and on
        // any host that repoints the disk). ConfiguredArtifactSpecSource resolves
        // `filesystems.disks.local.root` + `/scribe/openapi.yaml` — the same value Scribe itself resolves.
        //
        // Set it explicitly if you generate with `--scribe-dir` or keep the artifact somewhere else. A
        // host serving pre-made VARIANTS rebinds Splicewire\Beam\OpenApi\OpenApiSpecSource instead of
        // pointing this key at one of them.
        'artifact' => null,

        // Route middleware, shaped exactly like `intake.throttle` above. PUBLIC BY DEFAULT: a docs
        // surface nobody can read is not a docs surface. The real contract boundary is the published
        // config/scribe.php stub's `routes.match.prefixes` (`api/*` only) — what never enters the
        // artifact cannot leak from this route. A host that wants the spec behind auth lists middleware
        // here (e.g. ['auth'] or a signed-url guard).
        'middleware' => [],
    ],

    /*
    | RETROFIT SEAM (beam-particle-rename ticket 01). Every Beam table name is `table_prefix . $name`,
    | resolved in ONE place ({@see \Splicewire\Beam\Facades\Beam::table()}). A greenfield/satellite host keeps
    | the default `beam_`; a RETROFIT host dropping Beam into a pre-existing Laravel app changes this ONE
    | value (`''`, `acme_beam_`, …) so Beam's generic-noun tables never collide with the host's own
    | `posts`/`teams`/`categories`. No table is renamed by this knob yet — later tickets route model
    | `getTable()` + migrations through the helper.
    */
    'table_prefix' => 'beam_',

    /*
    | The schema-definition store's read/write source order (consumed by the registry collapse, T02):
    | an ordered list, e.g. ['fleet','db','file'] or ['file'] (filesystem only). Reads are
    | first-hit-wins, so ORDER IS LOAD-BEARING (ADR-0192 §4): `fleet` — the committed fleet-wide
    | conformance artifacts (vocabularies), contributed via the SchemaSources registry — sits AHEAD
    | of `db` so a tenant registration can never shadow a fleet artifact; ordinary content schemas
    | keep tenant-override (db over file). NOTE: FilesystemSchemaRegistry globs one directory
    | NON-recursively, which is why a fleet artifact needs its own tier + directory — it cannot
    | live in a `lifecycle/fleet/` subdirectory of the `file` tier.
    */
    'schema' => [
        'sources' => ['fleet', 'db', 'file'],

        // The tier `register()` WRITES to. Declared separately from `sources` because that list is a
        // READ precedence order and the two are not the same question (beam-facade ticket 150).
        //
        // ⚠️ This key exists because conflating them was a live multi-tenancy defect. `register()` used
        // to target `sources[0]`; once `fleet` was prepended above for read precedence, every tenant's
        // runtime registration began landing in the shared, git-tracked, publicly-served
        // `resources/schemas/fleet` — one directory per DEPLOYMENT, since tenancy bootstraps the
        // database, cache, queue, permissions and circuit but NOT the filesystem. Tenant A's schema then
        // shadowed tenant B's own `beam_schemas` row of the same `$id` (fleet is read first), and
        // write-once let A permanently 409 B out of registering it.
        //
        // `db` is the correct default: a runtime registration is tenant-owned and belongs in the
        // tenant-scoped table. A host whose `sources` do not include this key falls back to
        // `sources[0]` — so a `['file']`-only host keeps writing to its filesystem tier and needs no
        // override. Build-time writers that genuinely target a specific store name it explicitly via
        // `BeamSchemaRegistry::registerIn()`.
        'write_source' => 'db',

        // The committed fleet-artifact directory the default `fleet` tier reads (host-overridable).
        // Relative to resource_path() unless absolute.
        'fleet_path' => 'schemas/fleet',
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

    /*
    | The converged per-resource discovery listing, `GET {mount}/discovery`
    | (api-surface-coherence 105, decided by 41 D1/D5/D6). Mounted on `booted()`, ONE per stamped
    | MOUNT — a resource live at two exposures gets two listings, each reporting only its own reach.
    | The population follows ROUTES, not registrations: a stamped key with no `#[ParticleResource]`
    | still gets a listing, and a registered resource with no route does not.
    |
    | `probes` is how a host teaches the listing about its OWN middleware. Beam reads `auth` and
    | `can:` itself; `require.admin`, `entitlement:*` and friends are host predicates it has never
    | heard of, and it does not guess — an alias with no probe is treated as REACHABLE, so a missing
    | probe over-lists rather than silently hiding a route. Middleware alias => ReachabilityProbe
    | class-string.
    */
    'discovery' => [
        'enabled' => true,

        'probes' => [],
    ],

    /*
    | The publishable-event catalog (api-surface-coherence ticket 40). `EventTypeRegistry` is the
    | accumulator; `#[BeamEvent]` is a FEEDER onto it, never a second store. Both keys are empty by
    | default and the scan is skipped entirely when they are — a fresh beam host still gets the
    | `{resource}.persisted` fan-out over its own particle resources, which needs no config at all.
    | Registration throws on the name grammar and on a missing subject — facts about the DECLARATION.
    | Whether a name's prefix is a live resource key is a fact about the HOST, so it is advisory:
    | `splicewire:beam:doctor` reports it (`events.catalog-prefix`) and boot never refuses over it.
    | As a throw it took a host off the air; see api-surface-coherence ticket 91.
    */
    'events' => [
        // Explicit #[BeamEvent]-annotated event class-strings to reflect and register at boot.
        'classes' => [],

        // Filesystem paths scanned for #[BeamEvent] classes (dev convenience; no boot cache yet).
        'discover_paths' => [],
    ],

    /*
    | The per-resource rendering registry `Route::resourceRenderings()` enumerates (moved from
    | laravel-composition-engine into beam core). Resource token => list of ResourceRendering
    | class-strings, resolved from the container on demand. A package may also `register()` a rendering
    | onto the ResourceRenderingRegistry singleton imperatively from its own provider, so this key is a
    | seeding convenience, not the only way in. Empty by default — a resource with no renderings mounts no
    | routes.
    */
    'renderings' => [],

    /*
    | The registry-conformance ratchet (registry-kernel ticket 35). `artifact` is the committed JSON
    | `splicewire:beam:registry-conformance` writes and `--check`s — the accountability surface for every
    | registry-shaped class this host composes and its disposition.
    |
    | `tracker_path` is the ROOT of the fleet's file-backed issue tracker, and it exists for exactly one
    | check: a `deferred` disposition names an open ticket, and a deferral outliving its blocker is the way
    | that bucket rots into permanent permission. Left null, that staleness check reports the number of rows
    | it could not verify rather than passing them — an unanswerable question is named, never answered by
    | default in either direction. It is a per-machine absolute path, so it belongs here and never in the
    | committed artifact, whose ticket references are relative to it.
    */
    'registry_conformance' => [
        'artifact' => env('BEAM_REGISTRY_CONFORMANCE_ARTIFACT'),
        'tracker_path' => env('BEAM_TRACKER_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Family Tailwind scan (the two advisory @source/token audits)
    |--------------------------------------------------------------------------
    |
    | Where `FamilySourceCoverageAudit` and `FamilyTokenContractAudit` look. Every key has a working
    | default and exists only so an unusual host shape (a second frontend, a non-standard Vite config
    | path) can be named rather than silently missed — the flagship's app is `ui/src`, not
    | `resources/js`, and a scan pointed at the wrong tree returns a small non-zero result that reads
    | like a real answer.
    |
    | `css_roots` are searched for Tailwind **v4** entries (`@import "tailwindcss"`); a v3 host has no
    | `@source` and is out of the population. `plugin_markers` are what "the list is derived" looks
    | like in a Vite config — the audit's post-migration branch, satisfied by
    | `familySources()` from `@schemastud/seam/vite`.
    */
    'tailwind' => [
        'css_roots' => ['resources', 'ui', 'src'],
        'vite_configs' => [
            'vite.config.ts', 'vite.config.js', 'vite.config.mjs', 'vite.config.mts',
            'ui/vite.config.ts', 'ui/vite.config.js',
        ],
        'plugin_markers' => ['familySources', 'familyDistSources', 'vite-plugin-family-sources'],
        'file_cap' => 4000,
    ],

    // 'media'         => [ ... ]   // (ticket 08)
    // 'hooks'         => [ ... ]   // (webhook / sitemap / doctor registries)

];
