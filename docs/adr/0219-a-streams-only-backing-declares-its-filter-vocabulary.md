# ADR-0219 — A streams-only backing DECLARES its filter vocabulary, and the declaration outranks a registry stub

**Status:** accepted
**Date:** 2026-09-12
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/composite-backing/` ticket 02 (PRD "Implementation decisions");
closes the nomination at `.scratch/splicewire/laravel-beam/particle-write-surface/tickets/07-…:195`.
**Extends:** ADR-0212 (the polymorphic `backing:` slot; a capability may be absent).

## Context

`StreamsRecords::records(filters, cursor, perPage)` is the general list capability: the request's whole
`filter[...]` bag goes in opaquely and the backing owns its query semantics. That is what lets a
non-Eloquent backing implement it honestly, and it is also why beam could not LEARN such a backing's
vocabulary — there is no `Builder` to reflect allowed filters off and no `#[Filterable]` Data class to
generate a schema from. `GET {resource}/filters/schema` therefore answered a streams-only resource with
the authorized empty vocabulary (api-surface-coherence 125), and the Frame FilterPanel rendered nothing
over a list that filtered perfectly well. `StreamsRecords`' own docblock deferred "a declared filter/sort
vocabulary a backing can advertise" to a later interface (measured at `0eb534d`, `:36-40`).

The estate's workaround was measured at the flagship: `config/data-filters.php` registers `review-queue`
with `ReviewQueueFilterData` and a `ReviewQueueQuery` whose `baseQuery()` throws `LogicException` —
a data-filters registration kept solely so a schema could be reflected off a Data class nothing hydrates,
naming a `model` (`CircuitNodeRun`) the resource does not have. The Data class also declared `source` and
`parentId` while `ReviewInbox::applyFacets()` interprets a third key, `keywords`, that the panel never
learned about.

## Decision

1. **A sixth capability, `DeclaresFilterVocabulary`**, on the backing: `filterVocabulary(): FilterVocabulary`.
   A `FilterVocabulary` is an ordered, name-unique list of `DeclaredFacet`s — name, operator, control,
   an Options Source handle or an inline domain, a sortable flag — projected to the **same** `x-filter`
   / `x-sort` dialect `rushing/laravel-data-filters` emits (`Operator::keyword()`,
   `FilterableAttributesStrategy`), keyed under data-filters' own `Keywords` constants. One schema shape,
   one panel client (`@schemastud/facets` `FilterSchema`).
2. **The declaration is consulted FIRST** by `ResourceFiltersController::schema()`: declared vocabulary
   → data-filters registration → declared-empty. A backing without the capability takes exactly the
   path it took before; a backing with it is served from the declaration even when a stub registration
   survives under the same key, so the workaround retires the day the backing declares rather than
   racing it. `…/filters/options/{ref}` follows: for a declaring backing it gates the way the schema
   does and answers **only** the handles the declaration names.
3. **It is the capability of a backing WITHOUT `QueriesRecords`.** A queryable backing's vocabulary is
   its filter Data class — that is what data-filters composes, and what saved filters and sorts validate
   against. A backing carrying both is reported by `ResourceRegistryReport` /
   `particle.capability-disagreement` as two vocabularies for one resource: advisory, never refused,
   because a backing may be mid-migration.
4. **`filterable` keeps its meaning** — "the index rides the data-filters builder" — so a declaring
   backing declares `filterable: false`. `particle.filterable-promise` lists declaring keys in their own
   bucket with the repair that keeps their panel (the flag), because its generic repair (register a
   data-filters resource) is the stub this capability made unnecessary.

## Consequences

- `ReviewQueueUnionSource` (`splicewire/tower`) declares `source`, `parentId`, `keywords` — the keys
  `ReviewInbox::applyFacets()` already reads — and the flagship panel renders three controls from the
  declaration; a tower test pins the declared names against the read path so the two cannot drift the
  way `parentId`/`parent_id` did (api-surface-coherence 101 §3).
- The flagship's `review-queue` entry in `config/data-filters.php` is now redundant for the schema and
  the options handles the declaration names. It still serves saved-filter CRUD and `filters/variants`,
  which validate against a Data class and are deliberately **not** widened here; removing the stub is a
  host follow-up once composite-backing 03 re-expresses the resource. **Consequence while both exist:**
  `SavedFilterValidator` rejects a `filter[...]` key the Data class does not declare, so a facet the
  declaration adds and the Data class lacks renders in the panel and 422s on save. The Data class must
  therefore declare every facet the backing declares (`ReviewQueueFilterData` gained `keywords` in the
  same change, and tower's drift test asserts declared ⊇ Data-class facets); the review that found this
  is recorded in composite-backing 02.
- Tower's `ReviewQueueDeclaredVocabularyTest` carries the parity test: `ReviewQueueFilterData` run through
  the real `JsonSchemaGenerator` emits, per facet, the same `x-filter` keyword `DeclaredFacet` projects.
- `ResourceRegistryRow` gains a `vocabulary` capability column (`filters` in the capability set,
  `capabilities.vocabulary` in `--json`). It is a report shape, not a wire shape.
- Rejected: reusing data-filters' operator classes for the projection. `FilterOperator::toControl()`
  takes a `ReflectionProperty`, which a declaration has none of; mirroring the emitted strings costs a
  small parity test and keeps beam from constructing operators to describe a query it never runs.
- Rejected: refusing a `QueriesRecords` + `DeclaresFilterVocabulary` backing at registration. The
  write-axis refusal (`assertAffordancesWithinCapability`) guards an affordance the author could have
  gotten right in one token; two vocabularies is a migration state, and the report already names it.
