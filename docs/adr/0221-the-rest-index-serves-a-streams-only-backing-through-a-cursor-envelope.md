# ADR-0221 — The REST index serves a streams-only backing, through a cursor envelope; everything else still demands a Builder

**Status:** accepted
**Date:** 2026-09-12
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/composite-backing/` ticket 04 (PRD user story 6).
**Extends:** ADR-0212 (the polymorphic `backing:` slot), ADR-0219 (a streams-only backing declares its
vocabulary), ADR-0220 (the composite backing).

## Context

ADR-0220 gave beam one general multi-source backing, and ticket 03 put the review queue on it. The review
queue is a **Frame** resource, so nothing in 03 had to ask what the other transport does with a backing
that has no `Builder`. Ticket 04 does, because the surface it has to fix is a REST mount.

Measured 2026-09-12 at `laravel-beam@b441904`:

- `ParticleController::index()` had exactly one shape — `$query->paginate(...)` over a `Builder` composed
  either by data-filters (`filterable: true`) or by `ParticleListQuery` (`filterable: false`)
  (`src/Http/Particle/ParticleController.php:123-138`).
- `queryableBacking()` refused a non-`QueriesRecords` backing by name, and its docblock generalised that
  refusal to the whole resource: *"A resource whose backing merely streams cannot be served here"*
  (`:150-159`).
- The doctrine restated it as a transport fact: *"a `StreamsRecords`-only resource is a Frame-transport
  resource"* (`docs/agents/particle-doctrine.md`, "Capability is the CEILING" section).
- `ResponseEnvelope` had three methods, all page-shaped or single-record
  (`src/Http/Contracts/ResponseEnvelope.php`), and both shipped implementations typed their list method
  `LengthAwarePaginator`.

The resource that needed the union is `activity`, mounted `Particle::mount('activity')->only(['index'])`
in `~/Herd/splicewire-app/routes/tenant.php:1389` and read by the flagship's Activity page over
`GET /api/v1/activity`. Under the rule above, putting a composite behind it would have made the page 500
rather than merge.

## Decision

**A LIST does not need a builder, so the REST index serves a streams-only backing. Everything else on the
REST surface still demands `QueriesRecords`.**

1. `ParticleController::index()` branches to `streamedIndex()` when the resource's backing implements
   `StreamsRecords` and does **not** implement `QueriesRecords`. The test is "streams and does not query",
   never "is a composite": `EloquentBacking` streams too (via `BacksEloquent`), and routing it here would
   silently drop data-filters, saved filters and the declared default sort from ~30 resources.
2. The branch sits **after** `denyUngatedRead()` and **before** the `filterable` fork, and only on the
   standalone path — a relative mount composes a relation and genuinely needs a builder.
3. `ResponseEnvelope` gains `streamed(array $records, int $perPage, ?string $nextCursor)`:
   `{ data, limit, nextCursor }` on the neutral envelope, and `data` + `limit` + `meta.nextCursor` on
   `ResponseBody`. `offset` and `total` are **absent, not null-filled** — a keyset page over N merged
   sources knows neither, and an envelope that invents them publishes a count no arm produced.
4. The signature takes ROWS and an encoded cursor rather than the `CursorPaginator`. A stock
   `CursorPaginator` derives its next cursor from the **last item's own attributes**, so a caller that
   projects the page first (every caller here does) hands over a paginator that can no longer answer.
   Taking the cursor as an argument makes "read the cursor before you project" the signature instead of a
   comment.
5. `show`/`update`/`destroy`, subject resolution and the relative-mount base keep `queryableBacking()`'s
   refusal, verbatim. A streams-only resource is **list-only** over REST.

## Consequences

- A resource declared over a streams-only backing sets `filterable: false` — there is no builder for
  data-filters to ride — and gets its FilterPanel from `DeclaresFilterVocabulary` (ADR-0219) instead. The
  registry report already nominated exactly that pairing; it is now the working configuration rather than
  a warning.
- Paging changes shape for such a resource: `cursor`, not `page`. That is the point — an offset across N
  merged sources is the O(everything) read the composite exists to remove — but it means a client that
  drove the list by `?page=` must move to `nextCursor`. First instance: `activity`, whose page reads only
  `data` and was unaffected.
- **No sort parameter is honoured on this path.** The order is the backing's declared one, descending
  (ADR-0220 ships one direction). A resource that needs an ascending list is not a composite yet.
- **No `scope:` closure is applied**, because it is a `Closure(Builder)`. A streams-only list's row gate is
  the backing's own narrowing plus the class-level gate `denyUngatedRead()` requires. `ResourceReadGuard`
  already answers `null` — "could not look" — for a model-less backing, and the model-less read posture is
  counted rather than assumed.
- Three classes implement the new interface method (`ArrayResponseEnvelope`, `ResponseBodyEnvelope`, and
  any host adapter). Measured 2026-09-12: no host in `~/Herd/*` implements `ResponseEnvelope` — the one
  that used to bind its own became a config key in particle-manifest-repatriation 04 — so the interface
  widening breaks nothing outside this package.

## Alternatives rejected

- **Leave REST alone and give the flagship a local `ActivityController`.** It would have worked and it
  puts a general transport capability in one host, where the next streams-only REST mount cannot find it.
  The gap was beam's.
- **Wrap the cursor page in a `LengthAwarePaginator`.** It fits the existing envelope method and requires
  inventing a `total` the merge never computed. An invented number beside real rows is the estate's
  signature defect, not a shortcut past it.
- **Keep `activity` on a `Builder` and union in SQL.** Not available: the two copies of `activity_log` are
  on two connections, which is the whole reason the union stayed nominated for a ticket.
