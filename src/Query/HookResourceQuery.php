<?php

namespace Splicewire\Beam\Query;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Rushing\DataFilters\Query\ResourceQuery;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Models\Hook;

/**
 * The base query behind the `hooks` data-filters resource — the one beam's own `#[ParticleResource]`
 * promises by being `filterable` and, until now, never shipped.
 *
 * ## Why a class at all, rather than the stock `ResourceQuery`
 *
 * beam's `ParticleController::index` applies a resource's `scope` closure **only on the
 * non-filterable path** — its own comment says so: "Applied here (not for the filterable path, whose
 * data-filters query is its own gate)." So for a filterable resource, `baseQuery()` is the *entire*
 * substitute for the declared `scope`, and the inherited `ResourceQuery::baseQuery()` is a bare
 * `Model::query()` that drops it on the floor.
 *
 * ## ⚠️ For `hooks` specifically, that scope is ORDERING, not authorization — and that is a ruling,
 * not an omission
 *
 * {@see HookData::scope()} is `$q->latest()` and nothing else. Its docblock
 * records why: api-surface-coherence ticket 12 §7 made the `owner_*` morph **audit only**, and a
 * scope keyed on it "would quietly turn it into an authorization boundary that nothing else in the
 * surface honours — which is the worse of the two failures, because it would look like it worked."
 *
 * So this class must NOT invent a row filter the declaration deliberately refuses. ⚠️ An earlier version
 * of this docblock then said the read guard was "the tenant schema the connection resolves to, and the
 * route middleware on each of the two mounts", and that "nothing in the estate registers a policy for
 * {@see Hook}". beam-docs-satellite 65 measured that sentence for what it is — prose NOMINATING a gate
 * no code enforced — and both halves are stale: {@see Hook} carries `#[UseCascadePolicy]` since
 * api-surface-coherence 135 (`5b7233f`), and that bound policy is what `ParticleController::index()`'s
 * read gate reads. A host that mounts `hooks` with no tenancy and no policy answers 403, not every row.
 * Turning the list filterable did not widen it: the non-filterable path applied `latest()`, which hides
 * no row either.
 *
 * What it DOES protect is the ordering. `defaultSort()` is null here and `HookData` declares no
 * `#[Sortable(default: true)]`, so with the stock base query an unsorted `GET /api/v1/hooks` would
 * come back in whatever order Postgres felt like — silently losing the newest-first contract the
 * declaration states, on a paginated endpoint where an unstable order also means rows can repeat or
 * vanish across pages.
 *
 * The scope is read off the DTO rather than restated, so the filterable list and every other read of
 * this resource move together. That indirection is the load-bearing part: the day `hooks` grows a
 * real authorization scope, this query inherits it without anyone remembering that it exists.
 */
class HookResourceQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        $query = ($this->definition->requireModel())::query();

        $data = $this->definition->data;

        return method_exists($data, 'scope')
            ? $data::scope($query) ?? $query
            : $query;
    }
}
