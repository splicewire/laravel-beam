# Particle doctrine — declare every boundary-crossing shape

> **Seam:** beam-tier contract reference · stack-blind · every mechanism it names is a beam dependency
> — `splicewire/laravel-beam`'s own attributes and `rushing/laravel-data-schemas-scribe`'s, which beam
> requires so this doctrine can mandate them without pointing outside its own dependency graph.

*Homed in `splicewire/laravel-beam`, next to the registries it governs. Moved here from the beam
runbook so a repo that vendors beam reaches it through beam's own `AGENTS.md` rather than a path on
one machine. One screen. Everything below is a link.*

## The invariant

> Every boundary-crossing data shape is a declared Data class, which feeds into and/or becomes a
> particle.

Three legal declaration sites — no fourth:

1. **`#[ParticleResource]`** — `backing:` (what the rows *are*), `data:` (the read projection) and
   `input:` (the write DTO).
2. **`#[ParticleOp]`** — `input:` and `output:`. On `kind: Stream`, `output:` is an **event-name map**
   (`['run_status' => [StatusData::class], …]`); on every other kind it is a single class-string.
   `ParticleOperation`'s constructor rejects the wrong pairing, so a mis-shaped declaration fails at
   registration rather than generating a wrong client type.
3. **`#[ResponseFromData]` / `#[RequestFromData]` / `#[StreamsFromData]`** on a controller method —
   for surfaces that are not particles. All three live in `rushing/laravel-data-schemas-scribe`
   (`StreamsFromData` is repeatable and carries the wire event name). Precedence for a route's type is
   **macro → method attribute → particle-derived**; the host-side `->returns()` macro deprecates by
   attrition, not by a rename.

Don't hand-roll: `splicewire:beam:make:particle-resource` and `splicewire:beam:make:particle-op`
emit the attribute with every slot filled, the classes those slots name, and the
`Particle::mount()` / `Particle::ops()` mount line to paste into the host's route file.

## Where an operation MOUNTS — `/op/` is gone, and the old spelling still answers

⚠️ **This section exists because the URL keeps being mis-stated from memory.** Two changes landed
separately and each invalidated a spelling that is still in circulation.

**1. The macros are deleted.** `api-surface-coherence` 93 removed all six —
`Route::particleResource`, `particleOp`, `particleOps`, `particleRelative`, `resourceRenderings`,
`resourceFilters`. **The `Particle` facade is the only door**: `Particle::mount()`, `::ops()`,
`::relative()`, `::relatives()`, `::filters()` (`src/Facades/Particle.php:53-57`). Any `Route::particle*`
you meet in prose is historical; do not write new prose in it.

**2. The `/op/` segment left** (`particle-operation-surface` 12, landed 2026-08-29). An operation now
mounts **twice** — the primary and a deprecated alias — and they split the two names, because two routes
cannot share one and `RouteCollection::addLookups()` overwrites silently while Laravel only refuses the
pair at `route:cache`:

| | URL | route name | |
|---|---|---|---|
| primary | `{uri}/{id}/{op}` | `{resourceKey}.{op}` | write new code against this |
| alias | `{uri}/{id}/op/{op}` | `{resourceKey}.op.{op}` | **deprecated**, still answers |

**The alias deliberately keeps the OLD name.** That is what made the drop a non-event for PHP callers:
every `route('users.op.login-as')` and `URL::temporarySignedRoute('sigils.op.assume', …)` in the estate
kept resolving. So *a route name containing `.op.` is not evidence of stale code* — it is the supported
spelling of the deprecated mount, and grepping for `.op.` to find callers to migrate will mostly find
correct ones.

⚠️ **Do not delete the alias on the grounds that the suite is green.** Eight files across five roots
hand-write `…/op/…` as a **template literal** and reach no generated client at all — `~/Herd/audiostud`,
`~/Herd/splicewire`, and `resources/js/editor/transport.ts` in all three starters. Nothing in PHP, no
type-check and no doctor audit can see them; deleting the segment would leave live 404s that every
instrument reports as green. `Http\Particle\LegacyOperationAlias` is the middleware that rides the alias
specifically to answer *"is anything still calling it?"*, which is the only evidence that can retire it.

`Doctor\ParticleSlotCollisionAudit` watches the slot the drop lands in — `{uri}/{id}/{segment}`, shared
with renderings, CRUD, and hand-written routes in no registry. Simulated across 21 route tables before
the drop, on both the URI and route-name axes: zero collisions.

## What BACKS a resource — one polymorphic slot, and a capability is allowed to be absent

⚠️ **`#[ParticleResource]` is NOT model-required, and has not been since particle-contribution-seam
ticket 11.** The attribute carries `public string $backing` and **no `$model` property at all** —
verified by reflection at `~/Herd/splicewire-app` on 2026-08-29, 26 properties, `model` not among them.
The `model` / `source` / `sourceKind` triple and its `model` XOR `source` contract are gone and were not
re-homed. Read `splicewire/laravel-beam` `src/Particle/Backing/` — every class there carries its own
reasoning.

The slot is polymorphic **on type**, never on a discriminator field (a `sourceKind` string with zero
branching readers is what the type test replaced). `BackingResolver::resolve()` takes three forms:

| given | meaning |
|---|---|
| a `ResourceBacking` **instance** | used as-is — imperative registration only, the attribute slot is a `string` |
| a `ResourceBacking` **class-string** | container-resolved **per request**, so it may take constructor injection |
| any other class-string | read as an Eloquent model, wrapped in `EloquentBacking` — the ordinary case |

So the ordinary declaration stays `backing: Silo::class`, one word longer than the `model:` it replaced,
and the declaration genuinely carries no model field. A model class-string is deliberately **not**
validated at declaration time (a host's model, or a fictional FQCN used to exercise the manifest without
a database, are both legal); a bogus one fails at the first query, exactly where `$model::query()`
already failed.

### The capabilities, and declining one is an ANSWER

`ResourceBacking` is a **marker with no methods**. Every real job is a capability sub-interface, and a
backing implements the ones it genuinely has:

- **`StreamsRecords`** — `records(filters, cursor, perPage): CursorPaginator`. The GENERAL list
  capability, the one a non-Eloquent backing can honestly implement.
- **`QueriesRecords`** — `query(filters): Builder`. Eloquent-only and **narrower, not weaker**: it hands
  back a query the caller may still compose, which is what data-filters, saved filters, owner scoping and
  the declared `#[Sortable(default: true)]` order need in order to work *on* the query. Implement both and
  the `BacksEloquent` trait expresses `records()` in terms of `query()` for free.
- **`ResolvesRecord`** — `resolve(id, filters): ?ResolvedRecord`, a projected detail read for a union-ish
  backing. The union-arm discriminator rides the same opaque `$filters` bag every other capability takes,
  so **a caller hands every capability the identical argument shape**.
- **`WritesRecords`** — `resolveForWrite()` + `newRecord()`. A write needs the mutable record, not a
  projection, which is why it does not reuse `ResolvesRecord`.
- **`BacksModel`** — `modelClass()`: every row is an instance of ONE Eloquent model. **Conditionally**
  required: a backing whose rows are pivot rows, or span two record types, has no legal answer and must
  not invent one. That is exactly why it is a capability and not a method on the port.

⚠️ **A declined capability is a legitimate answer, never an error, and the readers are written that way.**
`EloquentBacking` deliberately declines `ResolvesRecord` — a model-backed detail runs through the
declaration's own `data:`/`project:` projection, and adding it would give the model path a second
competing projection route. `ParticleResourceModelResolver` states the general rule: *absence is a return
value here, never an exception* — an unknown key, or one whose backing declares no single model, yields
`null` and lets the caller decide. `ModelResourceIndex` simply omits such a resource. Measured on the
flagship 2026-08-29: **44 registered resources, 2 of them with a null model** (`members`,
`review-queue`), and both read correctly rather than throwing.

### Capability is the CEILING; the affordance flags may only narrow

`instanceof WritesRecords` is what the backing **can** do; `creatable`/`editable`/`deletable`/`showable`/
`filterable` are what the resource **may** do. Opening a write affordance against a non-writing backing
is a declaration error the author could have gotten right, so `ParticleResourceRegistry::register()`
throws at registration — measured verbatim:

```
Resource [probe-bad] declares creatable/editable/deletable but its backing [Splicewire\Tower\Frame\Sources\MembershipSource]
cannot write (it does not implement …\WritesRecords). Capability is the ceiling: an affordance may narrow
what a backing can do, never widen it.
```

The reverse — a writing backing declared `readOnly` — is the mechanism working, not a finding. The
**read** axis is unvalidated at registration (`filterable` and `showable` both default to true, so a
custom backing acquires those claims by saying nothing), which is why the live population is reported,
advisory, by `surgeon:audit`'s `particle.capability-disagreement`. The whole picture per host:

```
XDEBUG_MODE=off herd php artisan splicewire:beam:particle:resources --json     # add --disagreements to narrow
```

### When imperative `register()` is genuinely required — and when it is a fossil

The attribute and `ParticleResourceRegistry::register()` build the **same** runtime `ParticleResource`;
`AttributedParticleDiscovery::resourceFromAttribute()` passes `backing:` through verbatim. So the only
real reasons to register imperatively are:

- the backing is a pre-built **instance** rather than a class-string (the attribute slot is `string`);
- a slot needs a **non-constant expression** — but check first whether a `ResourceBacking` class-string
  answers it better, because that resolves at **request** time rather than boot. Three freeze points, and
  only the last follows a host's config: attribute ⇒ compile · imperative `new ParticleResource(...)` ⇒
  boot · a `ResourceBacking` class-string ⇒ request. `splicewire/laravel-beam-accounts`
  `src/Particle/Backing/ConfiguredUserBacking.php` is the worked example (the `users` resource, whose
  model is the host's);
- the Data class is one you cannot annotate.

**"The backing is not an Eloquent model" is NOT on that list**, and the closures are not either — `scope`,
`project`, `prepare` and `afterWrite` are resolved by convention from `public static` methods on the
annotated class.

⚠️ **`splicewire/tower`'s `src/Frame/TowerFrameResourceProvider.php` still says otherwise**, and its
docblock is the reason this section exists: it justifies registering `members` and `review-queue`
imperatively with *"the unified `#[ParticleResource]` attribute is model-required (`public string
$model`, no `$source`)"*. That sentence describes an attribute that no longer exists. Probed at the
flagship on 2026-08-29 — a throwaway class carrying
`#[ParticleResource(key: 'probe-members', backing: MembershipSource::class, data: MembershipResourceData::class, filterable: false, readOnly: true, …)]`
reflected, registered and read back clean, `modelClass()` null, `isFramed()` true. **Both registrations
are declarable by attribute today.** Tower was deliberately left unchanged; this is a recorded finding,
not a landed migration.

⚠️ **The real constraint on those two is the TRANSPORT, not the attribute, and it is easy to swap them.**
`ParticleController` (REST) demands `QueriesRecords` and refuses a merely-streaming backing by name;
`ParticleFrameResourceHandler` serves a streams-only backing through its `streamedIndex()` /
`resolvedShow()` branches. So a `StreamsRecords`-only resource is a Frame-transport resource whether it is
declared by attribute or imperatively — moving it onto the attribute changes nothing about which
transport can serve it.

## What a declaration buys — the generation chain

This is *why* you declare. One declaration feeds four consumers, so the UI is locked to the backend by
construction rather than by discipline. [frontend.md](frontend.md) states the stack-blind version of
this contract (producer authors, consumer generates); below is the beam-tier concrete chain.

```
                       ┌─ typescript:transform  → generated.d.ts   (DTO interfaces, committed)
a declared Data class ─┼─ schemas:generate      → resources/schemas (JSON Schema, committed)
   (any of the 3 sites)├─ generate:client       → routes.ts, hooks/, aliases.ts (committed)
                       └─ scribe:generate       → openapi.yaml  ⚠ NOT in the umbrella, gitignored
```

`splicewire:beam:generate:assets` is the umbrella and runs the **first three** in dependency order
(pipeline is a config seam, `beam.client.assets.generators`). Regenerate, then a dirty tree is the
drift signal. `--json` emits a `{ran, skipped, failed}` summary, so a generator this host
legitimately lacks is a *reported* skip, not ambiguous silence.

**The gate is `returns`.** No resolved `returns` on a manifest entry ⇒ a line in `routes.ts` and
nothing else: no typed hook, no alias, no store. `streams` is a parallel gate emitting a discriminated
union per wire event plus a `useSseStream` hook. So a declaration is not documentation — it is the
difference between a typed hook and a bare path string.

**Type names are the class's real native namespace**, dots for backslashes
(`Splicewire.Tower.Data.AgentData`). There is no `App.Data` rebasing — that remap was removed because
it had to be hand-kept in sync; native FQNs are globally unique, so this is collision-proof.

**Two schema-driven UI rungs terminate in `@schemastud/seam`**, and neither wants per-resource UI code:
`@schemastud/frame` renders admin CRUD straight off a `#[ParticleResource]`'s manifest fields
(`label`, `form`, `editData`, `layout`, …); BeamUx renders a site's own front-end off a `BeamUxEntry`'s
schema. A `.tsx` component's props can be *inferred* into a draft JSON Schema — never with fabricated
widgets or `$ref`s, and graduating the draft is a separate authoring act.

**Which HANDLER runs a resource's CRUD is a declaration slot too — `handler:`.** Omit it and the
resource rides `ParticleFrameResourceHandler`, the one generic handler; name a class and that class
serves it. There is exactly one non-null instance in the estate (`conduits`, whose federated edit
writes an alternate subject the generic write pipeline `findOrFail`s before any hook can redirect it).

The slot exists because the alternative had been tried and failed twice over. The flagship carried
`App\Frame\FrameResourceRegistry`, a constructor-seeded `key => handler` table — the residue of the
ADR-0156 fold, which collapsed 18 bespoke handlers onto the generic one and left a table behind to say
so. It had already conceded most of its job (`keysForRealm()` delegated to `ParticleResourceRegistry`,
"the declared membership authority"; the inverse `realmFor()` deleted for having zero callers, on the
reasoning that *resurrecting an unused inverse is how a second authority gets recreated*). The handler
map was the same mistake one slot over: a second place, beside the declaration, where a fact about a
resource was written down.

Two failure modes follow from that placement, and both were live:

- **A host table cannot express absence.** `$this->handlers[$key] ?? DefaultResourceHandler::class`
  answers for keys it has never heard of, so a miss is indistinguishable from a choice. Nine of the
  flagship's framed resources were never listed and therefore sat on the host's *legacy* handler
  undetected — one of them, `users`, throwing `CannotCreateData` on every non-empty read, in a repeat
  of the `hooks` defect (api-surface-coherence 107) that outlived its own repair because that repair
  named one key instead of asking which others were absent. A read that cannot miss cannot report one.
  ⚠️ Note the direction: absence produced the **legacy** handler, so the explicit rows naming the
  generic one were **load-bearing**, not padding. Deleting them as no-ops would have moved 18
  resources *backwards*.
- **A host table cannot be written by the party that knows.** Every one of those resources is declared
  in a *package*, so the only place able to name a handler for `conduits` was a host file — which is
  why tower's `ConduitResourceData` docblock pointed at `\App\Frame\Handlers\ConduitResourceHandler`
  in prose. Declared on the resource, the handler travels with it and an installing host wires nothing.

`Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver` reads the slot. Its `handlerFor()`
**throws** `UnknownFrameResource` for a key this host does not declare, and `handlerIfDeclared()` is
the nullable half — the `get()`/`find()` pair `ParticleResourceRegistry` already ships, mirrored one
tier out. A caller wanting a default supplies it at its own call site. The throw is safe because
Frame's transport resolves a `ResourceDefinition` (and 404s) before it ever asks for a handler, and
because every Frame manifest key is a registered particle at every host — set difference empty across
the flagship and 11 `~/Herd` roots. Enumerate hosts from disk when re-checking that, never from
`symlinks.json`, which cannot see the three starter symlinks.

### How far the chain is wired (updated: particle-doctrine-followups 12–14)

The four gaps this section used to list are closed or explicitly decided; what remains open is stated
last.

- **The client runtime contract is owned and audited.** `@/lib/api` / `@/lib/routes` stay host-owned,
  but the contract is written down (`splicewire/laravel-beam` `docs/client-runtime-contract.md`), a
  reference implementation ships (`vendor:publish --tag=beam-client-runtime`; the beam starter carries
  it pre-published plus a committed generated tree), and `sdk.client-runtime-contract` (advisory)
  reports a host whose modules are missing or don't export the required symbols. The backwards type
  dependency is gone: **the generator owns `RouteMap`** and emits it in `routes.ts`; a host runtime
  may widen entries on top, never constrain the emission. The two live implementations still diverge
  (axios + operator tier + upsell interceptor vs minimal `fetch`) — that is recorded as legitimate
  per-host character, not normalized.
- **Streams reach OpenAPI.** `UseDataStream` (scribe bridge) reads `#[StreamsFromData]`, and the
  platform's `ParticleResponseStrategy` routes a Stream operation's `output:` map through the same
  shared stash. Structural limit, stated: OpenAPI 3.1 has no native per-event schema slot for
  `text/event-stream` (AsyncAPI's territory), so the event union rides the **`x-sse-events`** vendor
  extension on the content entry — in the document and diffable, just not in a native slot.
- **The disk schema tree is app-only *by design*** (recorded at the config key,
  `data-schemas.auto_discover_types`): it is the app's own published artifact set, while package DTOs
  reach the spec in memory and TS via the transformer — two differently-scoped projections of one
  generator, on purpose. The schema leg now has its first guard: `schema.projection-drift` (advisory)
  reports a *declared* Data class whose disk schema is missing ("declared but never emitted") or
  unequal to an in-memory regeneration ("stale relative to the declaration"); "nothing declared"
  stays the negative-space detector's territory.
- **The regenerate-and-diff gate covers all three hosts.** The platform's CI job now runs all three
  umbrella generators *including the client* and diffs all the artifact trees (`ui/src/generated`
  included); the converged satellite's `generate` scripts name commands that exist again and its CI
  gained the same gate; the third host's absence of a client leg is recorded as deliberate in its
  `config/beam.php` (it routes through Wayfinder). The `@splicewire/_resources` DTO projection's
  staleness is detectable via the platform's `npm run verify:resources` (local-only by construction —
  the projection lives outside the repo). See [conformance.md](conformance.md)'s clean-build assertion
  for why a **clean** generation is the point.

Still honestly open: the OpenAPI spec itself stays gitignored and rebuilt per run, so there is no
OpenAPI *diff* gate; and the platform's CI baseline is known-broken upstream of the gates (stale
co-dev `composer.lock`, a vitest step that dies before `tsc` runs) — labeled in the workflow, owned
by the sessions whose in-flight work broke it.

`surgeon:audit` guards the TypeScript and client legs (six audits) **plus** the client runtime
(`sdk.client-runtime-contract`) and the schema leg (`schema.projection-drift`); only the OpenAPI
document itself remains gate-less.

## The four exceptions — narrow, and declared at the site

- RFC 8628 device-authorization grant (IETF-frozen wire format; the spec forbids evolving it).
- `Mcp::oauthRoutes()` — vendor-mounted; you cannot annotate routes you did not declare.
- Sanctum / stancl vendor internals — declaring auth into a registry that needs auth to boot is circular.
- The literal pre-boot installer path (`laravel-beam/src/Install/`) — only code that runs before
  registries exist.

Health endpoints and `/broadcasting/auth` are *outside* the doctrine's extension, not exceptions.

**So are description documents** (beam-facade ticket 114). A surface whose response body IS a
contract-description document in a schema/description media type — `beam/openapi.{yaml,json}`, and
the public schema door `GET <$id>` serving `application/schema+json` — is not a boundary-crossing
application data shape; it is the description *of* those shapes, and its vocabulary is defined by a
specification this estate does not own. Declaring one means modelling OpenAPI or JSON Schema itself
as a Data class, for a generated type that says nothing.

The test is the **media type**, not "it returns a document" — that phrasing is what a future surface
would abuse. `text/markdown` source bytes are application data and stay in the population; so does a
`204` with no body, which is undeclared for a different reason. Enforced in
`UndeclaredSurfaceAudit::DEFAULT_EXEMPT_URIS` / `DEFAULT_EXEMPT_NAMESPACES`, by exact URI or exact
controller FQCN, so the population of exemptions stays countable.

## Four one-line rules

- **List facets are declared on the Data class.** A resource's LIST surface is not a hand-rolled
  query: the read Data class carries `rushing/laravel-data-filters` attributes —
  `#[Filterable(operator: …)]` per facet, `#[Sortable(default: true)]` for the default order,
  `#[Includable]` for relations — and `filterable: true` on the `#[ParticleResource]` makes
  data-filters generate the index query from them. **Generate is literal**: `ResourceQuery::apply()`
  builds `allowedFilters`/`allowedSorts`/`allowedIncludes` off those attributes through
  `FilterReflector`, so a `ResourceQuery` subclass with an EMPTY BODY is fully functional. The only
  hand-written part is `baseQuery()` — the owner row-gate annotations can't express — which the host
  overrides (`*Query` suffix, per resource). That generated query is then the index's own read gate,
  which is why `ParticleController` and `ParticleFrameResourceHandler` both skip `scope()` on the
  filterable path. A `filterable: false` resource still reads the SAME `#[Sortable(default: true)]`
  for its default order, so sort is single-sourced either way. Model reading:
  `splicewire/laravel-beam-rank` `src/Data/RankData.php`.

  **`filterable: true` requires a data-filters REGISTRATION, and the attribute is not the only way to
  get one.** Three tiers, strongest first: `config/data-filters.php` seeds and wins ·
  `#[ResourceFilter]` discovery fills gaps · `DataFilter::resource()` overwrites either. So a
  correctly-wired resource may carry NO `query:` on its `#[ParticleResource]` at all — the estate's
  own `tokens` is registered purely in the platform's config, pointing at a `TokensQuery` whose
  `baseQuery()` is the row-scope. **Do not read a missing `query:` slot as "unscoped"; check the
  host's config before concluding anything about a resource's gate.** A key registered in no tier
  makes `ResourceRegistry::get()` throw `InvalidArgumentException` and the index breaks on first
  request — loudly, with nothing exposed, because no `ResourceQuery` is ever constructed
  (`particle-doctrine-followups` 15). `filterable: true` needs one more thing nobody checks at
  registration: a backing implementing `QueriesRecords` — see the backing section above, and
  `particle.capability-disagreement` for the standing reading.
- **Derived vs. published.** A rendering stays derived unless it becomes independently addressable
  *and* independently editable — at which point it is not a rendering, it is a publish, and
  `PublishPayload` is the seam. Fidelity (and therefore whether a write verb exists at all) is read
  from **certification, never a self-declared claim** — for renderings and for lenses alike; an empty
  proof certifies `Lossy`.
- **⚠️ A write DTO field written `public ?T $x = null` cannot express "clear this".**
  `Spatie\LaravelData\DataPipes\DefaultValuesDataPipe` checks `hasDefaultValue` **before**
  `type->isOptional`, so a declared default always wins and an **absent** field arrives as `null` —
  never as `Optional`. Three input states (absent / present-and-null / value) collapse to two, and a
  `toModelAttributes()` gating on `!== null` then **cannot null out a column**: it can set and never
  clear, silently, with a 200. Verified at runtime, not reasoned about.

  The fix, per field: type becomes `T|Optional|null`, the **`= null` default is removed** (use
  `= new Optional` when parameter order needs a default — the sentinel IS the default), and the gate
  becomes `! $this->x instanceof Optional`. Absent ⇒ untouched · present-and-null ⇒ written as null ·
  value ⇒ written. Restoring the `= null` makes the `Optional` arm unreachable again, so say so in the
  docblock.

  **It is a per-field judgment, never a sweep.** Convert a field only when *clearing* it is a real
  caller intent AND the column is nullable — converting a `NOT NULL` column turns a silent no-op into
  a constraint violation, and converting an authorization-bearing field can widen reach past a check
  that only runs on create. Leave the rest on the `!== null` path and say in the docblock why. Never
  `get_object_vars` in a write map — it leaks `Optional` sentinels onto the write. Model reading:
  `splicewire/tower` `src/Data/Compliance/OpenApiSpecInputData.php` (mixed gates, one clearable field)
  and `splicewire/laravel-beam` `src/Data/HookInputData.php` (mixed gates, with the non-conversions
  argued).

  ⚠️ **And the conversion is not done until every READER of the field is three-state aware.** A `??`,
  `?:`, `!== null` or `(string)` on a now-`Optional` field silently collapses the third state — which,
  on a re-validation keyed to "did the caller change this?", is a *skipped authorization check*, and on
  a cast is a runtime TypeError. Grep every reader before converting.
- **The write map is a declared contract, not a duck type.** A class named in an `input:`/`editData:`
  slot declares `Splicewire\Beam\Write\Contracts\MapsToModelAttributes`; its docblock is where the
  three-state rule above is written down. Every transport maps through the one
  `Splicewire\Beam\Write\ModelAttributeMapper` — never a hand-rolled snake-case loop, of which there
  were four. `method_exists('toModelAttributes')` is still honoured as a migration fallback, and
  `surgeon:audit`'s `beam.particle.undeclared-write-map` (advisory) is the burn-down meter that decides
  when both it and the snake-case fallback get deleted.
- **Tenancy floor.** [multitenancy.md](multitenancy.md) says the model doesn't know it's tenanted; the
  connection does. The floor test: **does this record index the churn, or participate in it?** Floor
  indexes; profile participates. Every central pin carries `@central-floor <category>` naming one of
  `kernel`, `tenant-isolation`, `query-engine`, `registry-runtime`, `auth`, `billing-wall` —
  `surgeon:audit` reports the uncited ones (advisory).

## Before you design any I/O surface, run this

```
XDEBUG_MODE=off herd php artisan popcorn:registries --json
```

The index of indexes: every registry that has described itself, its injection **seam**, its **arity**,
and a **`registerHint`** — the one-liner for how you register into it. Available from any beam,
satellite, or tower package or site. Describing one registry opts your package into a **gate**: it
must then describe *all* of its registry-shaped singletons, and `surgeon:audit` reports the ones it
left out.

`splicewire:beam:undeclared-surface` then writes the negative-space artifact (live surface minus
declared set); `--check` fails only on an **increase**. Three legs exist — HTTP, MCP tools, Inertia
props — but the committed artifact holds the HTTP leg's rows only, so read that number as the HTTP
surface, not the estate total; the other two report through `surgeon:audit --json`.

## Finding the commands at all

```
XDEBUG_MODE=off herd php artisan list splicewire --format=json
```

**This is the canonical form.** `artisan splicewire list` prints the same listing, but only because
Symfony resolves a bare namespace argument to the list command — the trailing word is ignored and
**`--format=json` is silently dropped**, which is exactly the flag an agent needs.

## Authorization is per transport

An op declares `ability:`; `AbilityResolver` answers it (`Gate` with a subject, `entitlement:<ability>`
without), and the **transport owns the deny shape** — HTTP throws 403, MCP omits the tool from
`tools/list`. **Command-line invocation is ungated by policy**: `php artisan …` reaches no resolver and
is always allowed. Command abilities exist only for the **MCP projection** — that gate gates the
*agent*, not the operation.

## Reference implementations

- **`splicewire/splicewire-app` — the reference for depth.** The most mechanisms in one place, and the
  biggest coverage backlog. Read it to see what a mechanism looks like fully exercised.
- **`splicewire/audiostud` — the reference for conformance.** Read it to see what a converged repo
  looks like. Do not study conformance in the deepest repo; it is the lowest-conformance one.

## Naming

Do not rename things *toward* "Particle" to signal alignment. The word's value is that it names
something specific — registry-reachable, permission-bearing, codegen-visible. Every non-particle
borrowing it makes the audits harder to write and the term less useful to the next agent.
