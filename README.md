# laravel-beam

The **app-substrate** rung of the schemastud stack — the runtime an application
stands on **with or without an editor**.

```
seam  ←  frame  ←  splice        (editor / UI tooling rungs)
                    │
                    ▼
                  beam                (app substrate — this package)
```

`beam` is the future home of the generic, editor-agnostic runtime pieces:

- the generic model traits — `SchemaRecord` / `PersistsSchemaRecord` (ticket 07),
  media traits (ticket 08);
- the host-hook registries — webhook / sitemap / doctor.

## Layering law (ADR-0082)

`frame → beam`, **never** `beam → frame`. `beam` boots headless; nothing in it
may reference the editor rung. This is enforced by the boot smoke test, which
registers **only** `BeamServiceProvider` and asserts the frame provider is absent.

## Not a `Beam` model

`beam` is a substrate, not an instance. **No `Beam` Eloquent model is minted** —
that graduates only if a surface forces an instance registry (map fog).

## The `Beam` facade

`Splicewire\Beam\Facades\Beam` is the short front door to the **Beam instance**
(`Splicewire\Beam\BeamManager`), a container-bound, `scoped()` manager. The surface is
**closed** at four methods:

| call | what it does |
| --- | --- |
| `Beam::table('particles')` | the table-prefix seam — `config('beam.core.table_prefix') . $stem` |
| `Beam::tablePrefix()` | the configured prefix (default `beam_`; a retrofit host may set `''`) |
| `Beam::tableFor($configKey, $stem)` | a sibling package's declared table name, falling back to the prefixed stem |
| `Beam::write($target, $payload, ...)` | forwards to the one `ParticleWriter` write pipeline |

There is no global alias — import the facade explicitly. There is no `Macroable`: a sibling
`laravel-beam-*` package extends Beam by registering into a core-owned registry
(`BeamInstallManifest` / `BeamDoctorManifest`), not by adding methods here.

Called before the container is booted (from a published `config/*.php`, say) the facade
**throws** — deliberately. The static helper it replaced silently fell back to a hardcoded
`beam_` and handed a retrofit host wrong table names with no error. Read the prefix in a config
file with `config('beam.core.table_prefix')`, not through the facade.

`Splicewire\Beam\Facades\Beam` is the only `Beam::` entry point. The `@deprecated`
`Splicewire\Beam\Beam` static bridge that carried the estate through the sweep was deleted at the
cutover, along with the `splicewire:beam:doctor` warning that nagged about it.

## Testing

Three idioms, so none of this is folklore:

- **Assert a write happened** with `Event::fake()` + `assertDispatched(BeamParticlePersisted::class)`.
  That is the write pipeline's own designed output — assert on the signal, not on a mock of the writer.
- **Seed fixtures** with `Beam::write(...)`, which does a **real write** and returns the persisted
  model. It is not a thing to mock; every test that calls it wants the row.
- **Intercept**, when you genuinely must, with `Beam::shouldReceive(...)` or `Beam::swap(...)` —
  free with the facade, and the reason there is no bespoke `Beam::fake()`.

## `splicewire:beam:doctor` — base-tier readiness

```
php artisan splicewire:beam:doctor
```

Audits whether a base-tier Beam app is deploy-ready. **Product-free**: it never requires
`splicewire/laravel-satellite`, and its frame / schema-forms / data-schemas checks are
**advisory and presence-conditional** (`class_exists` / `app()->bound()` at runtime) — on a
headless beam app where none are installed they emit an informational PASS/skip, never a
FAIL. It consumes the `Finding` + `DoctorStatus` primitives from `rushing/laravel-doctor`.

### Output format (the `<DoctorOutput>` parse target)

Each finding renders as **one line** in the stable form:

```
<check>: <detail>
```

at one of three levels — Pass → `info`, Warn → `warn`, Fail → `error` (the framework's
`$this->components` styling). The checks emitted, in order:

| check | gate? | fails on |
| --- | --- | --- |
| `lock path-free` | **hard** | a `type: path` package in `composer.lock` |
| `repos git-resolved` | **hard** | a committed `type: path` entry in `composer.json` `repositories` |
| `stability configured` | advisory | (warn only) dev-main pins without `minimum-stability`/`prefer-stable` |
| `schema round-trip` | advisory | never — skips when `data-schemas` absent |
| `frame manifest` | advisory | never — skips when `frame` absent |
| `schema-forms door` | advisory | never — skips when `schema-forms` absent |

The **exit code is non-zero only on a dependency-contract Fail** (`lock path-free` /
`repos git-resolved`). Every other check is advisory and never turns the run red — a headless
beam app with no editor rung installed is a valid, green configuration (ADR-0082 / ADR-0095).

## Namespace-aware validation — `@namespace` content in a `BeamSchema` artifact

A `BeamSchema.artifact` may declare `@namespace`/`@namespaced` content (the
`schemastud/php-json-ns` grammar), and each namespaced subtree of a submission is then
enforced against its namespace's `$vocabulary` schema — resolved through the host's
`SchemaRegistry` via the json-ns `VocabularyValidator` (ADR-0192/0193). The schema's
declarations govern the payload (they are overlaid before scoping), and **both** existing
validation gates enforce the same scoping, so a namespaced document can never pass one door
and not the other:

| Gate | package | shape |
| --- | --- | --- |
| `Splicewire\Beam\Validation\SchemaIntakeValidator` | this package | formatted `{pointer: [messages]}` map (the public-intake 422 body) — vocabulary violations merge into the same map |
| `Schemastud\DataSchemas\Migration\AcceptanceGate` | `schemastud/laravel-data-schemas` | boolean pass/fail (the `ParticleWriter` / migration-ladder write path) |

Additive: a schema with no namespace content validates byte-for-byte as before, and a host
with no json-ns wiring keeps plain structural validation (the enforcement engine resolves
lazily from the container's `JsonNsServiceProvider` binding).

## Frame operator seams — OOTB default bindings

`beam → frame` (ADR-0156): beam owns the model-backed CRUD driver behind Frame's agnostic
operator/admin sockets. Two of Frame's host-facing seams ship **out of the box** from
`BeamServiceProvider`, so a fresh host gets a working operator area with **no `app/Frame/` glue**:

| Frame contract | beam default | what it does |
| --- | --- | --- |
| `Schemastud\Frame\Contracts\FrameResourceHandlerResolver` | `Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver` | a constant map — every registered particle-resource key → the ONE `ParticleFrameResourceHandler` (which applies a registered `ParticleResource`'s enrichment when one exists under the key) |
| `Schemastud\Frame\Contracts\FrameFilterProvider` | `Splicewire\Beam\Frame\NullFrameFilterProvider` | an empty facet schema, so the ListShell's `filter-schema` / `filter-options` socket mounts without erroring (a resource declaring no data-filters `query` has no facets) |

Both are **overridable by the host** (last-binding-wins — an app provider registers after
beam-core's): bind your own `FrameResourceHandlerResolver` to map some keys to bespoke handlers,
or a real `FrameFilterProvider` (e.g. one derived from a data-filters query class) for a faceted list.

## Per-realm resource presentation overrides (RDU-03)

A realm may PRESENT the same resource differently — a different label, group, form, layout, or a
read-only gate — **without the resource declaration ever naming a realm** (declarations stay
realm-agnostic; frame stays agnostic). This is a separate **overlay layer** behind the `?realm` seam
that `AdminResourceRegistry` applies AFTER its realm-agnostic projection, so
`ParticleResource`/`#[ParticleResource]` never gain per-realm fields.

- **`Splicewire\Beam\Realm\RealmResourceOverride`** — a partial overlay of PRESENTATION fields only
  (`label`, `group`, `icon`, `section`, `navOrder`, `routeName`, `form`, `layout`, `editData`, `policy`,
  `query`, `readOnly`, `deletable`, `editable`, `showable`); a non-null field overlays the base, null
  inherits. `readOnly` derives the three write gates (`creatable`/`editable`/`deletable` = `!readOnly`),
  yielding to an explicit `editable`/`deletable` in the same overlay. **No runtime fields** —
  model/data/hooks never vary by realm. Merges onto a base via frame's agnostic
  `ResourceDefinition::withOverrides(...)` (named `withOverrides`, not `with()`, since spatie's `Data`
  base reserves `with()`).
- **`Splicewire\Beam\Realm\RealmResourceRegistry`** — holds overlays by `(key, realm)`;
  `apply($base, $realm)` resolves following the SAME `RealmRegistry::effective()`/stack order (the
  realm's `[...stack, self]` chain, merged bottom→top so the requested realm wins) and returns the base
  **UNCHANGED** when no overlay exists.

### Config surface

```php
// config/frame.php — ships EMPTY (inert: identity projection in every realm)
'realm_resource_overrides' => [
    'user' => [
        'members' => ['label' => 'My Teams', 'readOnly' => true],
        'tokens'  => ['label' => 'Personal Access Tokens', 'group' => 'Security'],
    ],
    // realms not listed (e.g. 'tenant') keep the base declaration's presentation.
],
```

The registry is a singleton hydrated from this config at boot. A host may also register overlays
imperatively — the fluent escape hatch:

```php
app(RealmResourceRegistry::class)->override('tokens', 'user', new RealmResourceOverride(
    label: 'Personal Access Tokens',
    group: 'Security',
));
```

INERT by default: with no overlay configured for a `(realm, key)`, every realm gets the identical
projection — a simple spin-up is untouched.

## Particle route macros

The generic particle surface ({@see ParticleController} / {@see ParticleOperationController}) is mounted
declaratively through a family of `Route::` macros registered by `BeamServiceProvider`. A host writes no
controller — it declares the resource/op and mounts it, keeping its **own** middleware/prefix `group()`.

| Macro | Mounts | Notes |
| --- | --- | --- |
| `Route::particleResource($uri, $key, $opts)` | the CRUD verbs (`index`/`show`/`store`/`update`/`destroy`) | `only`, `names`, `legacyPostUpdate`, `idConstraint` options |
| `Route::particleOp($uri, $key, $op, $opts)` | one named op at `POST {uri}/{id}/op/{op}` | `method`, `name`, `idConstraint` |
| `Route::particleOps($uri, $key, $ops, $opts)` | **a LIST** of ops (loop-collapse) | see below |
| `Route::particleRelative($uri, $model, $via, $routes, $opts)` | a bound-relative mount (nested URL) | see below |

### `Route::particleOps` — the plural loop-collapse (HTTP-02)

The sibling of `particleOp` that takes a **list** and registers + mounts each in one call — collapsing the
hand-rolled `foreach ([...]) { Route::particleOp(...); }` boilerplate. Each `$ops` entry is one of three
forms, the op **name derived from the declaration** (you pass the list, not a restated name per route):

```php
Route::middleware(['web', 'auth'])->prefix('resources')->group(function () use ($ops) {
    Route::particleOps('songs', 'songs', [
        new ParticleOperation(name: 'share', …),   // an inline object — registered here + mounted
        DownloadMedia::class,                       // a #[ParticleOp] class-string — discovered + mounted
        'reorder',                                  // a bare name — already registered elsewhere; mounted
    ]);
});
```

### `Route::particleRelative` — the bound-relative mount (HTTP-02)

Mounts a particle **through** a route-model-bound **relative** — the related model an operation is
scoped/associated through (parent is the common flavor; `hasManyThrough`, pivot, or an arbitrary scope are
all relatives). The SAME particle class can mount both **standalone** (`/media/{uuid}`) and **relative**
(`/albums/{album}/photos`) — the relative mount is *additive*, not a competing shape.

```php
Route::particleRelative('albums', Album::class, via: 'photos', routes: function () {
    Route::particleResource('photos', 'photos', ['only' => ['index', 'store']]);
});
```

`$via` is **relation-or-closure**:

- **`via: 'photos'`** (a relation name) — the controller bases `index`/`show`/`update`/`destroy` on
  `$relative->photos()` and, on **create**, associates the FK **structurally** through
  `$relative->photos()->make()` (never a forgeable body field). Covers `hasMany` / `hasManyThrough` /
  `belongsToMany` — Eloquent handles "through" for free.
- **`via: fn ($album, $q) => $q->where(...)`** (a scope closure) — an arbitrary scope (computed joins,
  polymorphic, cross-tenant). It **scopes** listing/resolution but **cannot** auto-associate on create, so
  it pairs with the resource's own `prepare` hook for the FK.

The bound relative is route-model-bound (`findOrFail` → a stranger parent id 404s); authorize it with a
`can:` middleware on the caller's `group()` (resolved once, children inherit). The relative context is
carried in the route defaults (`ParticleController::RELATIVE` / `::RELATIVE_MODEL` / `::VIA`) and read by
the controller — **the standalone (no-relative) path is byte-for-byte unchanged.**

## Conventions

Matches the `rushing/*` / `schemastud/*` house style: **no `strict_types`, no
`final`, no `readonly`.**
