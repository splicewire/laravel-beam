# laravel-beam

The **app substrate** of the beam family: declaration-driven runtime (particles), storage,
registries, doctor/surgeon content, and the host-binding defaults — the package a Beam host stands
on with or without an editor.

Start here, in order:

- [`AGENTS.md`](AGENTS.md) — self-ID, instructions, where records live;
- [`docs/agents/particle-doctrine.md`](docs/agents/particle-doctrine.md) — every boundary-crossing
  shape is a declared Data class; the three legal sites; the make commands;
- [`docs/agents/host-bindings.md`](docs/agents/host-bindings.md) — how defaults ship and hosts
  override (the two lanes);
- [`GLOSSARY.md`](GLOSSARY.md) and [`docs/adr/`](docs/adr/) — the names and the rulings.

## Registries

Beam declares nineteen registries, and most Beam packages register into them. Each has a reference page under
[`docs/registries/`](docs/registries/). Start from a cluster page, which says which registry in the group you
reach for:

- [`beam.particle`](docs/registries/beam.particle.mdx): the five registries behind the declared API surface:
  resources, operations, relatives, resource contributions and merge strategies.
- [`beam.realm`](docs/registries/beam.realm.mdx): the areas people land in, with their overlays and per-realm
  resource overrides.
- [`beam.doctor`](docs/registries/beam.doctor.mdx): the registries behind `splicewire:beam:doctor`, including the
  facade-conformance scope, which a host rebinds rather than registers into.

The rest stand alone: [`beam.install.steps`](docs/registries/beam.install.steps.mdx),
[`beam.seed.steps`](docs/registries/beam.seed.steps.mdx),
[`beam.doctor.audits`](docs/registries/beam.doctor.audits.mdx),
[`beam.surgeon.scan-paths`](docs/registries/beam.surgeon.scan-paths.mdx),
[`beam.nav.sections`](docs/registries/beam.nav.sections.mdx),
[`beam.surface.groups`](docs/registries/beam.surface.groups.mdx),
[`beam.client.sources`](docs/registries/beam.client.sources.mdx),
[`beam.capabilities`](docs/registries/beam.capabilities.mdx),
[`beam.events.types`](docs/registries/beam.events.types.mdx) and
[`schemas.sources`](docs/registries/schemas.sources.mdx).

Beam registers into one registry it doesn't own:
[`frame.resources`](https://github.com/schemastud/laravel-frame/blob/main/docs/registries/frame.resources.mdx),
owned by `schemastud/laravel-frame`, where it attaches the particle resource registry as the `beam` member.

## Layering (ADR-0156)

`beam` **requires** `schemastud/laravel-frame` and the schema belt — the old "frame → beam, never
beam → frame" law (ADR-0082) is retired; this README stated it long after 0156 flipped it, which is
exactly the docs-rot the 2026-10-16 truth sweep removed. `beam` still boots headless: requiring the
contract tier is not mounting an editor.

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
`splicewire/laravel-satellite`, and its frame / intake-door / data-schemas checks are
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
| `beam.intake.door` | advisory | never — warns when a mounted intake door cannot answer (no registry, an empty one, an unresolvable slug, or a slug off the allow-list); skips when no door is mounted |

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

## Frame operator seams

`beam → frame` (ADR-0156): beam owns the model-backed CRUD driver behind Frame's agnostic
operator/admin sockets. `BeamServiceProvider` supplies the handler resolver, and each framed
particle resource projects its declared filter capability into Frame:

| Frame contract | beam default | what it does |
| --- | --- | --- |
| `Schemastud\Frame\Contracts\FrameResourceHandlerResolver` | `Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver` | resolves the resource's declared `handler:` when present, otherwise uses `ParticleFrameResourceHandler` |
| `Schemastud\Frame\Contracts\ResourceFilterProvider` | `Splicewire\Beam\Filters\BeamResourceFilterProvider` | serves the addressed resource's schema, options and variants through the shared Beam filter runtime |
| `Schemastud\Frame\Contracts\ResourceSummaryProvider` | `Splicewire\Beam\Summary\BeamResourceSummaryProvider` | answers `resources/{key}/summary` with one `total` figure counted through the same scoped index query the Frame index reads |

Declare `filterProvider: YourProvider::class` on `#[ParticleResource]` to override its filter
capability. Declare `summaryProvider: YourProvider::class` to override its summary capability: the
default counts through the scoped index query, a `StreamsRecords`-only backing declines (404), and
the response is a `SummaryResponseData` with an optional `OverviewData`. A custom host
`FrameResourceHandlerResolver` must honor each resource's declared `handler:` before falling back
to its own generic driver.

Beam registers the contextual `saved-filters` resource for ordinary Frame CRUD. Its index
requires `filter[resource]`, creation requires `resource`, and a persisted target is immutable.
Schema metadata advertises `savedViewsResource: 'saved-filters'` when the selected provider
can validate saved queries and the saved resource is reachable in the current realm. Beam's
default provider adapts its data-filters registration. A custom provider can instead implement
`Schemastud\Frame\Contracts\ResourceFilterValidator::validate(ResourceDefinition, array): array`
and advertise the saved resource from its schema, without registering a DataFilter. It receives
the complete query parameters and returns their validated form; generic facet fields belong in
`filter[...]`. Retained `Particle::filters()` reads use the declared provider for framed targets;
only explicitly non-Frame retained targets fall back to Beam's default runtime.

The top-level `filterVariant` selects a registered query vocabulary for ordinary list reads and
is retained in saved `query_parameters`. Default Beam variants must use a `ResourceQuery`, belong
to the same resource and resolve to the same model. Both target and candidate authorization and
row scopes apply; canonical pagination does not restrict the target scope's ID subquery. Beam's
reader and Tower's `DataFilterRecordHydrator` use this shared selection. Unknown, inaccessible or
ineligible variants cannot fall back to canonical execution. When a provider advertises saved
views only for a variant, pass that `filterVariant` on the saved-resource list request too.

The typed `savedViewsCan` metadata governs create controls; each saved row's typed `can` records
its allowed mutations, including ownership and stored target/variant access. Missing or denied
persistence removes the saved-resource reference while leaving readable vocabulary available.
Canonical Frame access denials are 403; retained mounts preserve 404 for a target outside the
explicit route realm. Canonical and retained routes share owner visibility, persistence and
target-scoped defaults, and retain 404 for mutations of another owner's view.
Publish the shared `saved_filters` migration with `php artisan vendor:publish --tag=beam-migrations`
(also included by the Beam installer), then run the host's normal migration process. The migration
converges an existing table while preserving records and existing morph-ID column types.

A resource whose backing is a service rather than a model — `StreamsRecords` without `QueriesRecords` —
gets its facets by **declaring them on the backing**: implement `DeclaresFilterVocabulary` and return a
`FilterVocabulary` of `DeclaredFacet`s; `GET {resource}/filters/schema` serves that declaration (before
any data-filters registration under the key) and `…/filters/options/{ref}` answers the handles it names.
Doctrine and the worked example: `docs/agents/particle-doctrine.md`, backing section.

A resource whose rows come from **several** sources declares `backing: <a CompositeBacking subclass>`
instead of writing a merge and a cursor by hand. Arms are keyed by the `source` discriminator, every arm
pages itself by one declared sort key, and the composite's cursor is an encoded `{arm => armCursor|null}`
map — so page N resumes each arm where page N−1 stopped instead of re-deriving the whole stream to find
its place. `filter[source]` narrows the arm set; the detail read dispatches to that arm; `BacksModel` and
`WritesRecords` are declined. The merge itself is a socket (`beam.particle.merge.<handle>`, `ordered` by
default) so a package can plug ranked fusion without beam knowing it exists. `CollectionBacking` is the
smallest thing that can be an arm — a materialized, ordered collection with a keyset cursor. Worked
example: `splicewire/tower`'s `ReviewQueueUnionSource` + `src/Review/Arms/` (ADR-0220).

Such a resource is served by **both** transports. The REST index streams it too — `cursor` instead of
`page`, answered as `{ data, limit, nextCursor }` with no invented `total` — while `show`/`update`/
`destroy` and a relative mount still require an Eloquent backing, so a streams-only resource is list-only
over REST. Declare it `filterable: false`; its panel comes from `DeclaresFilterVocabulary`. Worked example:
`splicewire/tower`'s `ActivityBacking`, two connections' copies of one table merged on `created_at`
(ADR-0221).

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

## Localization — beam binds the host's translator (localization 07)

**Installing beam replaces the host application's `translator`.** Beam's provider `extend()`s the framework's binding
with `Splicewire\Beam\Localization\BeamTranslator`, keeping the framework's loader, locale and fallback, so `__()`,
`trans_choice()`, Blade's `@lang` and every validation message in the host walk beam's ladder. This is a host-wide
effect, by design: one ladder answers every lookup.

- **The ladder.** The locale chain is the outer loop: the requested locale, its parent languages (`es-MX` → `es`), then
  the fallback. The realm chain is the inner loop: the realm, its stack from most to least specific, then the base. A
  realm rung is a sparse subdirectory, `lang/<locale>/<realm>/<group>.php` (`lang/vendor/<ns>/<locale>/<realm>/…` for
  a package's line).
- **A realm's configured label is its English rung line.** `beam.core.realm_resource_overrides.<realm>.<key>.label`
  answers `resources.<key>.label` at that realm, in English only, just below that rung's file. Existing overrides keep
  rendering as they did (localization ruling 05).
- **The realm** is read from the current request's `realm` route parameter at each lookup, and never stored. Code that
  holds a realm passes it: `app('translator')->getIn($realm, $key)`, `->choiceIn(...)`. No realm means the base.
- **Plurals** are selected by the rules of the locale whose line answered, not the locale asked for.
- **Package namespaces.** A package's translator namespace is its composer name less the vendor and a leading
  `laravel-`, or its `extra.beam.lang-namespace`. Two packages that shorten to one name are refused until one declares
  its own.
- **Where a string comes from:** `php artisan beam:lang:rungs <key> --locale=<locale> --realm=<realm>`.
- **The plural fixture** (`tests/Fixtures/Localization/plural-selector.json`) records the installed framework's plural
  rules; `PluralFixtureTest` fails if a framework upgrade changes them. Regenerate with
  `composer lang:plural-fixture -- --write` after reading the change.

- **The frame manifest reads in the request's language** (localization 08). A resource's label and singular and its
  actions' labels resolve when the manifest is built, never at registration (`Localization\FrameText`, called from
  `ParticleResourceRegistry`'s one projection). Their keys are `<ns>::resources.<key>.label`, `.singular` and
  `<ns>::actions.<resource>.<op>.label`, in the declaring class's package. A label-less resource's headlined key and an
  inflected singular are English defaults only. A realm's configured label still renders in English and yields to a
  translation.

How to write text: [`docs/agents/user-facing-text.convention.md`](docs/agents/user-facing-text.convention.md).

## Localization — a request resolves its locale (localization 06)

Every HTTP request answers inside one locale scope. Beam places `Localization\Http\ResolveLocale` first in the global
stack, so a 404, 419 or early 401 renders in a resolved language. It also places it last in the `web` and `api`
groups, where the session exists. Configure it in `config/beam/localization.php` (the `beam.localization` Config
Section):

- **`supported`**: the canonical tags the host ships (`['en', 'es', 'es-MX']`). Null means `app.locale` alone, so
  nothing changes until you list them. `fallback` is the last rung.
- **`http_profile`** (off by default): read `Accept-Language` and state `Content-Language`, merge
  `Vary: Accept-Language`, and make a person- or workspace-chosen response `private`. Off, a request still resolves
  from saved preference and defaults, and no header is read or written.
- **`pseudo`**: add `en-XA`, English that is marked, accented and padded. Text that shows up unmarked under it did not
  come through the catalog.

The order is the API client's header, the person, the browser's header, the tenant, the brand, then the application
default. A bearer token, or no session, makes a request an API request. The person, tenant and brand sources are
container slots (`Localization\Contracts\*LocaleSource`); their packages fill them, and until then they state nothing.

Work with no request sets and restores locale, `Number`, Carbon and realm together:

```php
LocaleScope::run('es', fn () => $mailable->render());
LocaleScope::runIn('es', 'acme', fn () => …);       // with a realm; null is the base
$captured = LocaleScope::capture();                // later: $captured->enter(fn () => …)

public function middleware(): array                // a job opts in
{
    return [new WithLocaleScope($this->locale, $this->realm)];
}
```

Currency is never derived from the locale. Set it with `Number::useCurrency()`. Rationale:
[ADR-0246](docs/adr/0246-a-request-resolves-its-locale-inside-one-scope.md).

## Localization — the gates (localization 12)

- **In tests, a mistyped family key throws.** Beam's provider makes a namespaced key with no line throw
  `MissingTranslationKey` while unit tests run, but only in a namespace a splicewire, schemastud or rushing package
  owns. Your host's own keys and third-party namespaces are never affected. Switch:
  `beam.localization.throw_on_missing_key`.
- **The doctor** adds five advisory `localization.*` checks: unresolved literal key, realm line without base,
  underived key, coverage and English derivation.
- **Commands:**
  - `beam:lang:keys` lists every key the host can emit, with its English;
  - `beam:lang:missing <locale>` exports what a language lacks;
  - `beam:lang:ratchet [root] [--write]` runs the per-package literal ratchet (`lang-ratchet.json`, tested with
    `Testing\AssertsTextRatchet`).

### The literal ratchet

`lang-ratchet.json` at a package root holds, per file, the user-facing text in `src/` that reaches a person without a
key. The count may only fall.

**What it counts.** Constant, interpolated (`"… {$x} …"`) and forwarded (`$e->getMessage()`) text in these places:
- `abort*`: the message argument only;
- `new …HttpException`;
- `withMessages`/`withErrors` values;
- `response()->json` and `new JsonResponse`: the `message`/`error` values;
- `MailMessage` builders;
- `Notification` payload values;
- flash messages;
- the copy-named arguments (`title`, `message`, `note`, `label`, `description`, `summary`) of `new …Data(…)` and
  `…Data::from([…])`.

**What it doesn't count:**
- A concatenation is one hit.
- A literal inside `__()`/`trans()` is a key, not text.
- A message forwarded from an exception caught as a `ValidationException` or a `Coded*` exception has already gone
  through the translator, so it isn't counted.

**Exclusions.** Text that must stay raw is listed in `lang-ratchet-exclusions.json` at the package root, which you
maintain by hand and review like code. Each entry has a `file`, exactly one of `method` or `line`, and a `reason`:

```json
{"exclusions": [{"file": "src/Ops/ReplayNotificationStatusOp.php", "method": "assertReplayable",
                 "reason": "replay diagnostics, ruling 04 raw bytes"}]}
```

An excluded hit is never silent:
- the command prints it with its reason;
- the written baseline lists it under `excluded`.

Two kinds of bad entry fail both the command and `AssertsTextRatchet`:
- a stale entry, one that matches no text any more;
- a malformed entry.

**Recorded limitations.** The census doesn't read these, so each package's sweep classifies them by hand:
- Blade views (a different grammar);
- text built by a package's own helper (`$this->fail("…")`);
- a generic `->with()` (it is also eager loading);
- raw `Response` bodies (a machine boundary, e.g. a webhook);
- DTO-wrapper fields;
- `match`/summary arms;
- a message forwarded through a variable (`abort(422, $reason)`).

How to write text: [`docs/agents/user-facing-text.convention.md`](docs/agents/user-facing-text.convention.md).

## Mounting the particle surface

The generic particle surface ({@see ParticleController} / {@see ParticleOperationController}) is mounted
declaratively through the **`Particle` facade**. A host writes no controller — it declares the resource/op
and mounts it, keeping its **own** middleware/prefix `group()`.

> ⚠️ **The six `Route::particle*` macros were DELETED** (`api-surface-coherence` 93). `Particle` is the
> only door. Older prose and docblocks across the estate still say `Route::particleResource(…)` — that
> spelling is historical and does not resolve.

| Facade call | Mounts | Notes |
| --- | --- | --- |
| `Particle::mount($uri, $key)` | the CRUD verbs (`index`/`show`/`store`/`update`/`destroy`) | fluent: `->only()`, `->names()`, `->legacyPostUpdate()`, `->idConstraint()`, `->ops()`, `->relatives()` |
| `Particle::ops($uri, $key, $ops, $opts)` | **a LIST** of ops (loop-collapse) | `method`, `name`, `idConstraint`; see below |
| `Particle::relative($uri, $model, $via, $routes, $opts)` | a bound-relative mount (nested URL) | see below |
| `Particle::relatives($parent, $relatives)` | the declared edges of a parent | `#[ParticleRelative]`-driven |
| `Particle::filters($resource, …)` | the saved-filter sub-surface | — |

### Where an operation lands

Each operation mounts once at `{uri}[/{coordinate}…]/{op}`, named `{key}.{op}`.
Coordinates come from the declared subject's `pathParameters()` and default to `{id}`.
The mount's `names` option supplies a relative name stem; `name` overrides the full name.
Legacy `{uri}/{id}/op/{op}` URLs and `{key}.op.{op}` names are not mounted.
Callers, including signed links and editor transports, use the canonical route.

### `Particle::ops` — the plural loop-collapse (HTTP-02)

Takes a **list** and registers + mounts each in one call — collapsing hand-rolled `foreach` boilerplate.
Each `$ops` entry is one of three forms, the op **name derived from the declaration** (you pass the list,
not a restated name per route):

```php
Route::middleware(['web', 'auth'])->prefix('resources')->group(function () use ($ops) {
    Particle::ops('songs', 'songs', [
        new ParticleOperation(name: 'share', …),   // an inline object — registered here + mounted
        DownloadMedia::class,                       // a #[ParticleOp] class-string — discovered + mounted
        'reorder',                                  // a bare name — already registered elsewhere; mounted
    ]);
});
```

### `Particle::relative` — the bound-relative mount (HTTP-02)

Mounts a particle **through** a route-model-bound **relative** — the related model an operation is
scoped/associated through (parent is the common flavor; `hasManyThrough`, pivot, or an arbitrary scope are
all relatives). The SAME particle class can mount both **standalone** (`/media/{uuid}`) and **relative**
(`/albums/{album}/photos`) — the relative mount is *additive*, not a competing shape.

```php
Particle::relative('albums', Album::class, via: 'photos', routes: function () {
    Particle::mount('photos', 'photos')->only(['index', 'store']);
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

## Customizing what the generators emit — `beam-stubs`

The six generators (`splicewire:beam:make:particle-resource`, `…:particle-op`, `…:particle-relative`,
`…:particle-backing`, `splicewire:beam:make:workflow` and `splicewire:beam:make:page`) render from stubs the package ships, and each checks
what it wrote before exiting (exit 1 on a finding; `--no-verify` skips). A host that wants its own docblock
header, namespace layout or slot defaults publishes them once and edits the copies in place:

```
php artisan vendor:publish --tag=beam-stubs
```

Sixteen stubs land in `stubs/` (`particle-resource`, `particle-resource-input`, `particle-summary-provider`,
`particle-op`, `particle-op-task`, `particle-op-stream`, `particle-data`, `particle-relative`,
`particle-backing`, `workflow`, `workflow-guard`, `workflow-effect`, `page-data`, `page-controller`, `page.tsx`,
`page-site.tsx`), and every later generate prefers the
host copy with no flag. **Not publishing is the default, not a missing file** — this is deliberately not a
`splicewire:beam:install` step. The tag maps the whole `stubs/` directory, so it also deposits inert copies
of the `client-runtime/` sources, which have their own tag; see `docs/agents/stub-publishing.convention.md`.

## Conventions

Matches the `rushing/*` / `schemastud/*` house style: **no `strict_types`, no
`final`, no `readonly`.**
