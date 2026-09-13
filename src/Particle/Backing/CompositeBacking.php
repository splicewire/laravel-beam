<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Str;
use Splicewire\Beam\Particle\Backing\Merge\CompositeCursorPaginator;
use Splicewire\Beam\Particle\Backing\Merge\MergeStrategy;
use Splicewire\Beam\Particle\Backing\Merge\MergeStrategyRegistry;

/**
 * The one general MULTI-SOURCE backing: N arms read as one ordered, cursor-paged list, one row resolved
 * back to the arm it came from (composite-backing ticket 03).
 *
 * ## What it replaces
 *
 * Every multi-source LIST read in this estate was hand-rolled, and each one rolled it the same wrong
 * way: merge every source's `->all()` into one collection, sort it, and find "page 2" by scanning the
 * merged stream for the last id the client sent back. That is O(everything) per page, it cannot resume a
 * source where it stopped, and it has to be written again per resource. `ReviewQueueUnionSource` was the
 * shipped instance; `ActivityBacking` declined to become the second one and left the cross-connection
 * union un-built for that reason (`particle-manifest-repatriation` 09).
 *
 * This is that merge, once, as a {@see ResourceBacking} — so a resource declares
 * `backing: MyComposite::class` and gets paging it did not write.
 *
 * ## Arms are keyed by the `source` discriminator
 *
 * The key of an arm IS the value that rides `filter[source]` and the detail route's `?source=`, which is
 * the discriminator the estate's unions already publish per row. That is what makes the three verbs line
 * up without a fourth concept:
 *
 *  - `records($filters, …)` — `filter[source]` NARROWS THE ARM SET before anything is read, so a
 *    single-source view of a four-source list costs one arm, not four plus a filter pass;
 *  - `resolve($id, $filters)` — dispatches to `$filters['source']`'s arm, falling back to "the first arm
 *    that answers non-null" when the caller did not say (the same row id can exist under two arms, which
 *    is why the discriminator exists at all);
 *  - `filterVocabulary()` — always declares `source` over the arm keys, so the panel can offer the
 *    narrowing the list actually implements.
 *
 * An arm is anything {@see BackingResolver} accepts — a {@see ResourceBacking} instance, a
 * `ResourceBacking` class-string (container-resolved at request time), or a model class-string (wrapped
 * in {@see EloquentBacking}, so an Eloquent arm costs nothing to declare). Every arm must
 * {@see StreamsRecords}; an arm that also {@see ResolvesRecord} participates in detail reads.
 *
 * ## Ordered mode, and what the arms must honour
 *
 * The merge itself is a SOCKET — {@see MergeStrategy}, plugged by handle through
 * {@see MergeStrategyRegistry} — mirroring the grounding kernel's fusion socket one tier over. Beam
 * binds the `ordered` default: a k-way merge on {@see sortKey()}, descending, whose cursor is an encoded
 * `{arm => armCursor|null}` map so each arm resumes exactly where it stopped. Ranked (relevance-fused)
 * merging is a different plug and is deferred to ticket 05.
 *
 * Ordered mode's contract with an arm is one sentence: **every arm pages itself by the same declared
 * sort key, descending.** {@see CollectionBacking} satisfies it for a materialized read-model and an
 * Eloquent arm ordered by that column satisfies it for a table.
 *
 * ⚠️ `sortKey()` defaults to `id` and is declared HERE, not read off the resource's
 * `#[Sortable(default: true)]`. A backing is handed to the `backing:` slot and is never told which
 * resource key it was declared under, so it cannot look its own declaration up; and the sort key is a
 * fact about what the ARMS can page by, which the resource's column list does not know. A resource whose
 * declared default sort and whose composite disagree is a declaration bug, not something to reconcile at
 * request time.
 *
 * ## Declined: `BacksModel` and `WritesRecords`
 *
 * A composite backs no single model (that is the whole premise), and a write across arms has no
 * meaningful subject — which arm creates? `WritesRecords` stays per-arm, and the composite declining it
 * means `assertAffordancesWithinCapability()` refuses a composite resource that opens `creatable` at
 * REGISTRATION rather than 405-ing at runtime. A declined capability is an answer.
 */
class CompositeBacking implements DeclaresFilterVocabulary, ResolvesRecord, StreamsRecords
{
    /** @var array<string, ResourceBacking>|null resolved once per request-scoped instance */
    private ?array $resolvedArms = null;

    /**
     * ⚠️ The promoted properties share their names with the accessors below (`$arms` / `arms()`), which
     * PHP allows and which is the point: a subclass overrides the METHOD to build its arms from injected
     * services, an ad-hoc composite passes the PROPERTY by name (`new CompositeBacking(sortKey: 'at')`),
     * and neither spelling has to know about the other.
     *
     * @param  array<string, ResourceBacking|class-string>  $arms  keyed by `source` discriminator
     */
    public function __construct(
        protected array $arms = [],
        protected string $sortKey = 'id',
        protected string $mergeHandle = 'ordered',
    ) {}

    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        $arms = $this->selectedArms($filters);

        // `filter[source]` naming no arm this composite has is an empty list, not an error and not the
        // unnarrowed list: the caller asked for rows from a source that is not here.
        if ($arms === []) {
            return new CompositeCursorPaginator([], $perPage, null, null, false);
        }

        return $this->mergeStrategy()->merge($arms, $filters, $cursor, $perPage, $this->sortKey());
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        foreach ($this->selectedArms($filters) as $arm) {
            if (! $arm instanceof ResolvesRecord) {
                continue;
            }

            $resolved = $arm->resolve($id, $filters);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * The composite's own vocabulary: `source` over the arm keys, then the union of whatever the arms
     * declare, deduplicated by facet name in arm order.
     *
     * ⚠️ Facets are NOT prefixed with the arm they came from, and the charter's "tagged by arm" is
     * deliberately read as provenance rather than as a key prefix. A facet's name IS the `filter[<name>]`
     * key on the wire; `circuit.parentId` would publish a control no client sends, and `$filters` is
     * forwarded to every arm VERBATIM anyway, so two arms declaring `parentId` are two arms answering the
     * same question about their own rows — which is one facet, not two. `source` is what carries arm
     * provenance, and it is the one facet this class declares itself.
     *
     * Never empty: a composite always has arms, so it always declares at least `source`. That matters
     * because a declared vocabulary OUTRANKS a data-filters registration for the resource (beam
     * ADR-0219) — a capability that could answer with nothing would be able to blank a working panel.
     */
    public function filterVocabulary(): FilterVocabulary
    {
        $facets = [$this->sourceFacet()];
        $seen = ['source' => true];

        foreach ($this->resolvedArms() as $arm) {
            if (! $arm instanceof DeclaresFilterVocabulary) {
                continue;
            }

            foreach ($arm->filterVocabulary()->facets() as $facet) {
                if (! isset($seen[$facet->name])) {
                    $seen[$facet->name] = true;
                    $facets[] = $facet;
                }
            }
        }

        return FilterVocabulary::of(...$facets);
    }

    /**
     * The `source` facet, inlined over the arm keys — a finite, code-declared domain, so it needs no
     * Options Source. A composite whose arm labels come from somewhere else overrides this.
     */
    protected function sourceFacet(): DeclaredFacet
    {
        return DeclaredFacet::set('source', inline: array_map(
            fn (string $key): array => ['value' => $key, 'label' => Str::headline($key)],
            array_keys($this->arms()),
        ));
    }

    /**
     * The arms this composite merges, keyed by their `source` discriminator.
     *
     * Declared through the constructor for an ad-hoc composite, overridden by a subclass that builds its
     * arms out of injected services — which is the shape a real consumer takes, because a backing is
     * container-resolved per request precisely so it can.
     *
     * @return array<string, ResourceBacking|class-string>
     */
    protected function arms(): array
    {
        return $this->arms;
    }

    /** The field every arm pages by, and the one the merge orders on. */
    protected function sortKey(): string
    {
        return $this->sortKey;
    }

    /** The {@see MergeStrategyRegistry} handle of the merge this composite wants — bare, unrooted. */
    protected function mergeHandle(): string
    {
        return $this->mergeHandle;
    }

    /**
     * Every declared arm, resolved through {@see BackingResolver} — instances used as-is, class-strings
     * container-resolved at REQUEST time, model class-strings wrapped in {@see EloquentBacking}.
     *
     * Memoized for the life of this instance rather than per call, because `records()` and a subsequent
     * `filterVocabulary()` on the same request must be talking about the same objects.
     *
     * @return array<string, ResourceBacking>
     */
    protected function resolvedArms(): array
    {
        if ($this->resolvedArms !== null) {
            return $this->resolvedArms;
        }

        $resolver = app(BackingResolver::class);

        return $this->resolvedArms = array_map(
            fn (ResourceBacking|string $arm): ResourceBacking => $resolver->resolve($arm),
            $this->arms(),
        );
    }

    /**
     * The arms a request addresses: all of them, or just the ones `filter[source]` names.
     *
     * The value is read as Set membership (a comma list or an array), the same reading the `set`
     * operator gives every other multiselect facet, so `filter[source]=circuit,workflow` reaches two
     * arms. An absent, empty or whitespace-only value narrows nothing — which is what makes the detail
     * route's unconditional `source=''` a no-op rather than a miss.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, ResourceBacking>
     */
    protected function selectedArms(array $filters): array
    {
        $raw = $filters['source'] ?? null;

        $wanted = array_values(array_filter(array_map(
            'trim',
            is_array($raw) ? array_map(strval(...), $raw) : explode(',', (string) $raw),
        )));

        if ($wanted === []) {
            return $this->resolvedArms();
        }

        return array_intersect_key($this->resolvedArms(), array_flip($wanted));
    }

    /** The plug for this composite's declared merge handle, resolved at request time. */
    protected function mergeStrategy(): MergeStrategy
    {
        return app(MergeStrategyRegistry::class)->resolve($this->mergeHandle());
    }
}
