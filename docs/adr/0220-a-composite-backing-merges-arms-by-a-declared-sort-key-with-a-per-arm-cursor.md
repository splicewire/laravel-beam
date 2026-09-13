# ADR-0220 — A composite backing merges ARMS by a declared sort key, with a cursor per arm; ranked mode is deferred

**Status:** accepted
**Date:** 2026-09-12
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/composite-backing/` ticket 03 (PRD "Implementation decisions").
**Extends:** ADR-0212 (the polymorphic `backing:` slot), ADR-0219 (a streams-only backing declares its vocabulary).

## Context

Every multi-source LIST read in this estate was hand-rolled, and each one rolled it the same way: merge
every source's `->all()` collection, sort the result, and find page N by scanning that merged stream for
the last id the client sent back.

- `Splicewire\Tower\Frame\Sources\ReviewQueueUnionSource` was the shipped instance. Measured 2026-09-12 at
  tower `d7614c4`: `ReviewInbox::pending()` merged four producers with `collect(...)->merge(...)
  ->sortByDesc('createdAt')` (`src/Review/ReviewInbox.php:43-46`) and the source located its cursor with
  `$stream->search(fn ($item) => $item->id === $lastId)` (`src/Frame/Sources/ReviewQueueUnionSource.php:44-66`).
  Page 12 costs exactly as much as page 1, and no source can resume where it stopped.
- `Splicewire\Tower\Particle\Backing\ActivityBacking` declined to become the second instance and left the
  cross-connection activity union un-built, on the ground that *"one paginated, one-sorted list across two
  connections cannot be a `Builder`"* (`particle-manifest-repatriation` 09).
- No backing composed another backing. Measured 2026-09-12 over `~/Workspaces/php/packages` +
  `~/Herd/splicewire-app/app`: five production `StreamsRecords`/`QueriesRecords` implementors, none
  delegating to a `ResourceBacking`; the only delegation anywhere is `EloquentBacking`'s `extends`.

The socket-in-kernel / plug-by-registry answer to "whose merge is it" already exists one tier over:
`Splicewire\Grounding\Fusion\GroundingFusion` is the interface and `OrderedUnionFusion` the default plug.

## Decision

1. **`CompositeBacking` is a beam-tier `ResourceBacking` over N ARMS, keyed by the `source`
   discriminator.** The key of an arm IS the value riding `filter[source]` and the detail route's
   `?source=`, which is the discriminator the estate's unions already stamp on every row. That one
   identification is what makes the three verbs line up without a fourth concept: `filter[source]`
   **narrows the arm set before anything is read**; `resolve($id, $filters)` dispatches on
   `$filters['source']`, falling back to the first arm answering non-null; `filterVocabulary()` declares
   `source` over the arm keys. An arm is anything `BackingResolver` accepts, so an Eloquent arm costs
   nothing to declare.
2. **Ordered mode only, on a DECLARED sort key, descending.** Every arm is asked for `perPage` rows at its
   own cursor, the `k × perPage` candidates merge into one descending list, and the first `perPage` is the
   page. Ties break on the arms' **declaration order** — not their key's alphabetical order — because that
   is what reproduces an existing in-memory merge exactly (PHP's stable sort left `pending()`'s
   concatenation order intact, and a key-alphabetical tie-break would have matched those four names by
   coincidence and broken on the first rename).
3. **The composite cursor is one `Cursor` whose parameters are the arm keys** — `{arm => armCursor|null}`,
   encoded through Laravel's own base64/JSON envelope rather than a second one nested inside it. Each arm's
   own resumption point comes from that arm's own paginator (`getCursorForItem()`), so the merge never
   learns an arm's cursor convention. An arm that contributed nothing keeps its incoming cursor and re-reads
   at most `perPage` rows next page; an unknown or renamed arm key degrades to "that arm starts over"
   rather than throwing at a user holding a page-3 URL.
4. **The merge is a SOCKET, `beam.particle.merge.<handle>`.** `MergeStrategy` is the interface,
   `OrderedMergeStrategy` beam's `ordered` plug, `MergeStrategyRegistry` the popcorn registry. A package
   shipping ranked fusion registers its own handle from its own boot and beam never learns its name.
5. **`BacksModel` and `WritesRecords` are declined.** A composite backs no single model — that is the
   premise — and a write across arms has no subject: which arm creates? So a composite resource opening
   `creatable` is refused by `assertAffordancesWithinCapability()` at REGISTRATION rather than 405'd at
   runtime. Writes stay per-arm.
6. **Ranked (relevance-fused) mode is researched, not built** (ticket 05). It is a different plug — a
   candidate window per arm, rank fusion, an optional rerank, and an offset over a materialized ranking —
   and nothing in the estate demands a paged search list yet. `MergeStrategy::merge()` is written so a
   ranked plug satisfies it unchanged: it ignores `$sortKey` and reads the cursor as an offset.

## Deviations from the charter, and why

- **Root is `beam.particle.merge`, not `particle.merge`.** Every registry beam owns roots under `beam.`,
  the popcorn index routes by root PREFIX, and roots are unique estate-wide — so an unprefixed
  `particle.merge` would have beam claim a top-level neighbourhood no package owns and a host could
  reasonably want. The HANDLE a composite declares is unchanged and bare (`ordered`).
- **`sortKey()` defaults to `id`, not to the resource's `#[Sortable(default: true)]` column.** A backing is
  handed to a `backing:` slot and is never told which resource key it was declared under, so it cannot look
  its own declaration up; and the sort key is a fact about what the ARMS can page by, which a column list
  does not know. A resource whose declared default sort and whose composite disagree is a declaration bug.
- **Arm vocabularies union DEDUPLICATED by facet name, not prefixed by arm.** A facet's name IS the
  `filter[<name>]` key on the wire, so `circuit.parentId` would publish a control no client sends; and
  `$filters` reaches every arm verbatim anyway, so two arms declaring `parentId` are two arms answering the
  same question about their own rows. `source` is what carries arm provenance, and it is the one facet the
  composite declares itself — which also means a composite's vocabulary is **never empty**, so the
  declaration-outranks-the-registry rule of ADR-0219 cannot blank a working panel.
- **Beam's own `ordered` plug is seeded in the registry's CONSTRUCTOR, not from `packageBooted()`.** The
  register-down rule governs what OTHER packages contribute; a socket's own default is part of the socket.
  Measured 2026-09-12: `splicewire/tower`'s testbench does not register `BeamServiceProvider` (it reaches
  beam transitively, and testbench does not auto-discover), so a boot-registered default was absent in the
  first suite that exercised a composite — `RegistryMiss: No entry registered under
  beam.particle.merge.ordered` against a registry that had been bound, constructed and read successfully.

## Consequences

- **`CollectionBacking` ships beside the composite**: a materialized, ordered collection given a keyset
  cursor on `(sortKey, idKey)`, and the smallest thing that can be an arm. It exists because the estate's
  union read-models genuinely cannot be queries yet, and it names that rather than hiding it.
- **`ReviewQueueUnionSource` is the first consumer.** It is now four `ReviewArm`s (circuit, composition,
  concept-anchor, workflow) under the composite; `ReviewInbox::pending()` survives for `collapsedUnits()`
  and the v1 controller but is no longer the paging path. Tower's `ReviewQueueCompositePagingTest` asserts
  the walked pages equal `pending()`'s order exactly, at every page size, including rows sharing a
  `createdAt` — the no-behaviour-change claim measured against the old definition rather than restated.
- ⚠️ **An arm still materializes its own producer before narrowing, and ticket 03 does not change that.**
  Two of the review queue's three facets cannot be pushed into the producers' queries as they stand:
  `keywords` searches a label computed in PHP from three relations per source, and the workflow arm is a
  per-principal inversion unioned and deduplicated in memory with no single query behind it. A `LIMIT`
  under a PHP filter pages wrongly — 25 rows narrowed to 3 is not a page of 25 — so pushing down without
  denormalising the label first would trade a slow list for a wrong one. What this ADR removes is the
  whole-queue merge per page and the linear rescan for the cursor. Per-arm query pushdown is a separate
  change with its own denormalisation, and it needs no change to this contract: an arm that CAN page by
  query simply does, and the merge cannot tell.
- The activity cross-connection union (ticket 04) now has a backing to land on; the limitation pinned in
  `particle-manifest-repatriation` 09 is liftable rather than lifted.
- Rejected: a `direction` axis on the merge. Direction has to be honoured by each ARM's paging as well as by
  the comparator — an arm handing back an ascending batch breaks the prefix property the per-arm cursor
  rests on — so it is a declaration belonging to a composite AND its arms, not a flag on a shared plug.
  Deferred with ranked mode.
- Rejected: letting the composite implement `WritesRecords` by dispatching the write on `filters['source']`.
  It would make an affordance flag legal on a resource whose create surface has no honest subject, and
  `newRecord()` has no arm to pick at all.
