<?php

namespace Splicewire\Beam\Particle\Subject;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * The ONE implementation of "resolve this resource's `{id}` to a record".
 *
 * Three declaration slots apply, in this order, and the order is load-bearing:
 *
 *   1. **`scope`** — ADR-0156 §83's row-level read gate. A resolve-by-id that skips it reaches rows
 *      the caller may not touch, and answers 200 rather than 404;
 *   2. **`includes`** — the resource's declared eager loads, so a resolved record carries the same
 *      relations whichever path resolved it;
 *   3. **`routeKey`** — a declared public identifier resolves the `{id}` segment against THAT column
 *      and the primary key stops resolving entirely (one public identifier per resource, never two).
 *      It branches BELOW the base query and the gate deliberately, so a route key need only be unique
 *      within whatever those already narrowed to — a product slug unique per SELLER under a relative
 *      mount, with the seller's own slug carrying the single global constraint.
 *
 * A null `scope`/`routeKey` and an empty `includes` leave the query untouched, so a resource declaring
 * none of the three resolves through exactly the `findOrFail($id)` it always did.
 *
 * ## Why this is a collaborator and not a method on the controller
 *
 * It had exactly one implementation and two callers that needed it, and only one of them had it:
 * `ParticleController::findParticle()` applied all three, while
 * {@see ParticleOperationController} resolved every operation's
 * subject with a bare `$operation->model::query()->findOrFail($id)`. That divergence was a live
 * authorization gap, not a stylistic one — an operation on a scoped resource reached rows the read
 * path correctly hid. Extracting the tail is what makes "the row gate is not CRUD-only" true by
 * construction rather than by two files agreeing.
 *
 * The caller supplies the BASE query, because the two callers legitimately differ there:
 * `findParticle()` may start from a bound relative's query (which needs the request), while
 * {@see RecordSubject} starts from the resource's backing. Everything after the base query is here.
 */
class ResourceRecordLookup
{
    /**
     * Apply the resource's `scope`, `includes` and `routeKey` to a base query and resolve `$id`.
     *
     * @param  Builder<Model>  $query
     */
    public function within(ParticleResource $resource, Builder $query, string $id): Model
    {
        if ($resource->scope !== null) {
            $query = ($resource->scope)($query) ?? $query;
        }

        if ($resource->includes !== []) {
            $query->with($resource->includes);
        }

        if ($resource->routeKey !== null) {
            return $query->where($resource->routeKey, $id)->firstOrFail();
        }

        return $query->findOrFail($id);
    }
}
