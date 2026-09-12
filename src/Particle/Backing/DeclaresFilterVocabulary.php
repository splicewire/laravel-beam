<?php

namespace Splicewire\Beam\Particle\Backing;

/**
 * Capability: this backing can SAY what it filters and sorts on, without a `Builder` to reflect it off.
 *
 * ## The follow-up {@see StreamsRecords} named
 *
 * `StreamsRecords::records()` takes the request's opaque `filter[...]` bag and owns its own query
 * semantics; that is what makes it the general list capability. It also means beam cannot LEARN a
 * streams-only backing's vocabulary — there is no Data class carrying `#[Filterable]` and no `Builder`
 * to read allowed filters off — so the filter sub-surface answered such a resource with an empty
 * schema and the Frame FilterPanel rendered nothing, on a list that filtered perfectly well
 * (composite-backing ticket 02; the nomination is `particle-write-surface` 07).
 *
 * The estate's workaround was a data-filters registration whose `Query` class throws on use — the
 * flagship's `review-queue` stub (`ReviewQueueQuery::baseQuery()` raises `LogicException`) — kept
 * solely so a schema could be reflected off a Data class nothing hydrates. This capability is that
 * declaration moved to where the semantics already live: the backing that interprets the bag.
 *
 * ## What it declares, and what it does not
 *
 * {@see FilterVocabulary} is a list of {@see DeclaredFacet}s — name, operator, control, an options
 * source or an inline domain, a sortable flag — projected to the SAME `x-filter` / `x-sort` dialect
 * `rushing/laravel-data-filters` emits, so the panel is one client over one shape. It declares the
 * VOCABULARY only. Applying a facet stays `records()`'s job, exactly as before; a declared facet the
 * backing then ignores is a lie the declaring class owns, and the tower test over `ReviewInbox::applyFacets()`
 * is the model for pinning the two together.
 *
 * ## It is the streams-only backing's capability, and it outranks the registry for that resource
 *
 * A backing with {@see QueriesRecords} already HAS a vocabulary — its filter Data class — and the
 * data-filters path composes, validates saved filters and sorts against it. Such a backing does not
 * implement this; one that does is reported by `particle.capability-disagreement` as carrying two
 * vocabularies. For a backing without `QueriesRecords`, `ResourceFiltersController::schema()` consults
 * this capability FIRST, so a leftover stub registration under the same key stops answering the schema
 * the day the backing declares (beam ADR-0219). Saved filters and variants still require a data-filters
 * registration: they validate against a Data class, and this capability deliberately declares none.
 *
 * Resolved per request like every other capability method — the controller calls
 * `ParticleResource::backing()`, so a backing may build its vocabulary from injected state. The
 * registry report and the audits ask only `instanceof`, statically, and never construct it.
 */
interface DeclaresFilterVocabulary extends ResourceBacking
{
    public function filterVocabulary(): FilterVocabulary;
}
