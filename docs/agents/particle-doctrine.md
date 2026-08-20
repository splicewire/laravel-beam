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

1. **`#[ParticleResource]`** — `data:` (the read projection) and `input:` (the write DTO).
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
`Route::particleResource` / `Route::particleOp` mount line to paste into the host's route file.

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

## Three one-line rules

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
  (`particle-doctrine-followups` 15).
- **Derived vs. published.** A rendering stays derived unless it becomes independently addressable
  *and* independently editable — at which point it is not a rendering, it is a publish, and
  `PublishPayload` is the seam. Fidelity (and therefore whether a write verb exists at all) is read
  from **certification, never a self-declared claim** — for renderings and for lenses alike; an empty
  proof certifies `Lossy`.
- **Tenancy floor.** [multitenancy.md](multitenancy.md) says the model doesn't know it's tenanted; the
  connection does. The floor test: **does this record index the churn, or participate in it?** Floor
  indexes; profile participates. Every central pin carries `@central-floor <category>` naming one of
  `kernel`, `tenant-isolation`, `query-engine`, `registry-runtime`, `auth`, `billing-wall` —
  `surgeon:audit` reports the uncited ones (advisory).

## Before you design any I/O surface, run this

```
XDEBUG_MODE=off herd php artisan splicewire:beam:manifests --json
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
