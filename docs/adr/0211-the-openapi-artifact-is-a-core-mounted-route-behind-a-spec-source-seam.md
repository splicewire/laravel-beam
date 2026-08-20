# ADR-0211 — The OpenAPI artifact is a core-mounted route behind a spec-source seam

**Status:** accepted
**Date:** 2026-08-19
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/beam-docs-satellite/` ticket 05
**Supersedes nothing. Extends:** ADR-0028 (Scribe emits the artifact, Scalar is the canonical UI),
ADR-0145 (the exposed MCP manifest is a per-caller resolution), ADR-0210 (a docs-surface contribution
is a seed row, an endpoint, and a generic component).

## Context

Beam's docs are becoming an out-of-the-box capability: a fresh `splicewire/laravel-beam-starter` must
boot with a live API reference generated from **its own** routes, not a copy of one upstream spec.
Today the arrangement exists only as `splicewire/splicewire-app` local wiring — a hand-written
`Route::get('docs/openapi.yaml')` reading `storage/app/scribe/openapi.yaml`, a Blade view pointing
Scalar at it, and a 500-line `config/scribe.php`.

Three facts on disk shaped this decision:

1. **Scribe is already a hard, transitive dependency of beam core.** `splicewire/laravel-beam` requires
   `rushing/laravel-data-schemas-scribe`, which requires `knuckleswtf/scribe: ^4.0|^5.0`. Every beam
   install already carries the generator; there was no "should beam depend on Scribe" question to answer.
2. **Core already mounts a fixed package route.** `BeamServiceProvider::registerIntakeRoute()` mounts
   `POST beam/intake/{schema}` with config-driven middleware. A core-mounted artifact route has
   precedent in the same file.
3. **Scribe emits YAML only.** `Knuckles\Scribe\Writing\Writer` does a single `Yaml::dump` to
   `openapi.yaml`. There is no JSON emitter, so JSON is a derivation we own.

## Decision

### 1. The artifact is a core-mounted package route, not an entry

`GET beam/openapi.yaml` and `GET beam/openapi.json`, named `beam.openapi.yaml` / `beam.openapi.json`,
mounted by `BeamServiceProvider` alongside the intake route.

ADR-0210 §4 already ruled the general case for live docs data: a fixed, namespaced, read-only route
auto-registered by the contributing package. ADR-0116's concern is a package claiming *unmatched* URLs
— which is why the entry renderer is host-mounted (ADR-0209) — and a fixed path is not that.

The artifact is deliberately **not** a `BeamUxEntry`. It is a build artifact, not authored content, and
making it an entry would leave a headless beam host — no `beam-ux` installed — unable to serve its own
spec. The *page* remains a beam-ux seed row per ADR-0210 §9, embedding
`<ApiReference specUrl={route('beam.openapi.yaml')}>`.

The path is fixed and not configurable. Ticket 02 established that public docs paths come from
containment (`segment` on a seeded root entry), not config; this route inherits that ruling by being a
package-owned namespaced path rather than a docs path at all.

### 2. Both formats are real URLs; JSON is derived at request time

Two fixed routes onto one controller. Every consumer of a spec — Scalar, Redoc, `curl`, an MCP client —
takes a **URL**, not an `Accept` header, so content negotiation alone would leave callers with no JSON
link to paste.

The YAML artifact stays the single source. JSON is `Yaml::parseFile()` → `json_encode`, cached on the
artifact's mtime, so the conversion is one cost per regeneration and the two representations cannot
drift. `Accept` negotiation on a bare `beam/openapi` may be added later if something asks for it.

### 3. One spec now, behind an `OpenApiSpecSource` seam

Scribe supports variants natively (`scribe:generate --config=<name> --scribe-dir=<dir>` — N configs is
N independent specs) but has **no notion of request-time gating**; it is strictly build-time. The three
options were (a) one spec, (b) pre-generated variants resolved per request, (c) one spec filtered at
runtime.

**We ship (a), and the seam is the resolver, not the artifact.** A container-bound `OpenApiSpecSource`
answers "which spec bytes for this request, in this format," with a default single-file implementation
reading the configured artifact. A host rebinds it to do (b) with zero change to the route, the page, or
the component.

This mirrors ADR-0210 §5's ruling for the MCP manifest, and on the same dependency grounds, harder: a
per-caller OpenAPI filter would make **beam core** reach for `Beam\Commerce\Entitlements\EntitlementGate`
and `Beam\Tenancy\Tenant`. Runtime filtering also forfeits public cacheability.

No variant *config schema* ships now. An unused enumeration of variants is the single-value assumption's
mirror image; the seam alone satisfies ADR-0145's warning against baking a static-list assumption in.

### 4. Generated at install and at deploy — never on request

- `splicewire:beam:install` runs generation as an install step, so a fresh starter has an artifact
  immediately and the OTB promise holds on first boot.
- A composer/CI script regenerates on deploy (the app's existing `"docs": "@php artisan scribe:generate"`).
- `beam:doctor` reports a missing or stale artifact — the doctor-as-reporting-seam pattern of ADR-0209 §7
  and ADR-0210 §8.

Lazy generation on first request is rejected: extraction reflects over every route, and a public `GET`
must not write to storage.

The endpoint 404s cleanly when no artifact exists.

### 5. Public by default, middleware config-driven

`beam.core.openapi.middleware` defaults to `[]`, shaped exactly like `beam.core.intake.throttle`. A docs
surface nobody can read is not a docs surface; the *exclusion rules* are the real contract boundary,
which is what §7 makes safe.

### 6. Disk path is configurable; URL is not

`beam.core.openapi.artifact`, defaulting to `storage_path('app/scribe/openapi.yaml')`. This is a disk
path, not a URL, so it does not reopen ticket 02. Reading it back off Scribe's internal `$this->paths`
resolution would couple us to something we do not control, and hard-coding would foreclose the (b)
variant future the §3 seam exists to keep open.

### 7. Beam publishes a `config/scribe.php` stub, matching `api/*` only

Published under a `beam-scribe` tag. Defaults:

- `type => 'laravel'`, `laravel.add_routes => false` — carrying ADR-0028's reasoning in the comments.
- `openapi.enabled => true`, **`postman.enabled => false`** — the Postman collection is ruled out of
  scope for the docs surface, and an OTB default emitting an artifact nothing consumes widens the
  contract for free.
- `routes.match.prefixes => ['api/*']`, empty `include`, empty `exclude`.

Given §5's public default, the stub's match rules **are** the exposure boundary for every beam site. A
beam host's public contract is its `api/*` surface; admin, webhook receivers, and intake are not things
a fresh install should publish to the world. Hosts widen deliberately.

Host-specific concerns stay host-side: the `servers` override (which must resolve to a concrete host —
a `{tenant}` template is a trap that Scalar cannot resolve and that tenancy middleware rejects) and the
group taxonomy.

### 8. The no-HTML guarantee is a non-gating doctor audit

A `beam:doctor` audit asserts `scribe.type === 'laravel'` and `scribe.laravel.add_routes === false`.
Without it, a host flipping either value silently grows a second, unbranded docs UI at a URL beam does
not own — which also collides with ADR-0209's catch-all renderer.

**Not gating.** Beam reserves `gate: true` for "an agent is building a thing wrong" (exactly one audit
today, `UndescribedRegistryAudit`). A host that deliberately wants Scribe's static UI is making a
defensible choice to report, not block.

### 9. Particle-aware extraction must live in beam core — but this ADR does not move it

The seven particle-aware Scribe strategies currently live in `splicewire/tower`
(`Splicewire\Tower\Scribe\Strategies\*`), two tiers above beam, and `ParticleGroupStrategy` imports
`Splicewire\Beam\Http\Particle\ParticleController`. Without them in core, a fresh beam starter's
self-generated spec is bare paths with no groups, titles, or schemas — the OTB promise degrades to
something not worth pointing a reference at.

The rehoming is **already owned** by `splicewire-app` wayfinding
`.scratch/splicewire/splicewire-app/api-surface-coherence/` ticket 24, whose audit independently reached
the same verdict: five strategies are beam-core, one (`RouteTitleStrategy`) is below-beam, and **two are
condemned** — `ApiGroupStrategy` (reads the `config/api-groups.php` that ticket 17 deletes) and
`ParticleGroupStrategy` (it *is* the URI-prefix guess ticket 01 rejected).

This ADR contributes one argument that audit did not have: **OTB self-documentation makes the rehome
load-bearing rather than tidy-up.** It takes no position on `RouteTitleStrategy`'s open-vs-proprietary
home — beam core already requires `rushing/laravel-data-schemas-scribe`, so either destination satisfies
beam OTB, which removes one constraint from that decision.

> **Resolved 2026-08-20 — §9 is now history, and ticket 21 should read this paragraph instead.**
> api-surface-coherence 17 deleted the two condemned strategies and shipped
> `Splicewire\Beam\Scribe\Strategies\GroupStrategy` in their place; ticket 24 moved the rest down. The
> namespace a `config/scribe.php` stub must name is **`Splicewire\Beam\Scribe\Strategies\`**, and the
> members are:
>
> | Slot | Strategy |
> | --- | --- |
> | `metadata` | `GroupStrategy`, `ParticleTitleStrategy`, `RouteTitleStrategy` |
> | `bodyParameters` | `ParticleRequestStrategy` |
> | `responses` | `ReturnsResponseStrategy`, `ParticleResponseStrategy` |
>
> `ModelsResponseEnvelope` is a trait the two response strategies share, not a registrable strategy —
> do not list it. `RouteTitleStrategy` landed in **beam core, not the open `rushing/*` package**: the
> `/secret-sauce` call §9 anticipated dissolved once beam-ux's tier correction established that beam is
> already MIT free tier, leaving placement with no exposure axis to decide.
>
> Two dependencies came down that this ADR never counted: `RouteReturnType` (which
> `ReturnsResponseStrategy` imports) and `ClientTypeName` (which `RouteReturnType` calls), both now in
> `Splicewire\Beam\Routing\`.

### 10. Group taxonomy comes from `GroupRegistry`, and a fresh host's groups are its own resources

`api-surface-coherence` ticket 01 (closed) settled that **the group is a property of the resource, not
the URI**, read off the particle stamp every route already carries, with hand-rolled routes declaring via
`->beam()->inResource(...)`; groups are first-class `GroupRegistry` entries each declaring a `parent`,
and `GroupRegistry` lands **in beam core**. Ticket 17 builds it and deletes `config/api-groups.php`.

This is a better OTB answer than a published taxonomy config: a fresh beam site groups its own spec
correctly with no host config at all.

The requirement this map hands to ticket 17: **a fresh beam host's groups are derived from its own
declared particle resources**, falling through only where nothing is declared. Seeding this estate's
eight roots (Knowledge, Composition, Automation, Governance, Access, Commerce, Platform, Lineage) into
every beam host would ship one estate's ontology to all of them — the exact mistake `api-groups.php`'s
own header warns about: *"a package cannot know the taxonomy of every host that mounts it."*

> **Built 2026-08-20 (ticket 21) — §6's default path was wrong on a real host, and is amended here.**
>
> The ADR named a literal default of `storage_path('app/scribe/openapi.yaml')`. On a fresh
> `laravel-beam-starter` that 404s: Scribe writes the artifact through **`Storage::disk('local')`**
> (`Writer::writeOpenAPISpec()`), and the Laravel 11+ skeleton roots the local disk at
> `storage/app/private`. The starter generated to `storage/app/private/scribe/openapi.yaml` while beam
> looked one directory up.
>
> `beam.core.openapi.artifact` therefore ships as **`null` ⇒ derive**, with
> `ConfiguredArtifactSpecSource` resolving `filesystems.disks.local.root` + `/scribe/openapi.yaml`. This is
> not the coupling §6 argued against — that was about reading Scribe's *internal* `$this->paths`
> resolution. The local disk root is ordinary published Laravel config, it is the same value Scribe itself
> resolves through, and it follows a host that repoints the disk. An explicit path still wins, which is
> what a `--scribe-dir` host sets.
>
> Two smaller findings from the same bare-host run:
>
> - **`openapi.version` must be `3.1.0` in the stub, not Scribe's `3.0.3`.** `DataSchemaGenerator` stamps
>   `openapi: 3.1.0` on the assembled document unconditionally (hoisting Data-class `$defs` into
>   `components/schemas` needs 3.1's JSON Schema compatibility), so a stub saying 3.0.3 makes Scribe emit
>   3.0-shaped fragments into a document that declares 3.1.
> - **`type => 'laravel'` still writes a Blade view and public assets** on every generate, even with
>   `add_routes` false. Nothing routes to them, so the §8 guarantee holds — but the files appear, and the
>   stub says so rather than letting a host discover it as a surprise.
>
> §7's deploy-time composer script could not ship from beam: a package has no `artisan`. It ships instead
> as the **third check in the §8 doctor audit**, which reports when no composer script invokes
> `scribe:generate` and names the line to add.

## Consequences

- A headless beam install — no `beam-ux` — still serves its own spec at `beam/openapi.{yaml,json}`.
- The docs *page* and the *artifact* degrade independently, per ADR-0210 §8.
- Beam core gains a published config stub, an install step, a doctor audit, and two routes; it gains no
  new dependency.
- Ticket 06 (extracting the app's docs surface) now waits on the beam-core build **and** on the
  cross-map rehoming, because a spec generated without the particle strategies is not worth extracting a
  page for.
