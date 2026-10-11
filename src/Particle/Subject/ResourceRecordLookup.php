<?php

namespace Splicewire\Beam\Particle\Subject;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * The ONE implementation of "resolve this resource's `{id}` to a record".
 *
 * A READ applies three declaration slots, in this order, and the order is load-bearing:
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
 * An OPERATION applies the resource's `operationScope`, or falls back to the full read `scope` when
 * none is declared. This keeps the population boundary (tenant, owner, visibility and resource
 * identity) in front of every operation while allowing a resource to separate actor-relative LIST
 * admission from the operation's own authority contract. The fallback is deliberately fail-closed:
 * an unsplit resource never loses its existing scope merely because an operation addresses it.
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
 * authorization gap, not a stylistic one. The later operation-authority contract separates those two
 * callers at the same seam: CRUD reads apply read scope; operations apply their own declared gate.
 *
 * The caller supplies the BASE query, because the two callers legitimately differ there:
 * `findParticle()` may start from a bound relative's query (which needs the request), while
 * {@see RecordSubject} starts from the resource's backing. Everything after the base query is here.
 */
class ResourceRecordLookup
{
    /**
     * Apply the resource's READ `scope`, `includes` and `routeKey` to a base query and resolve `$id`.
     *
     * `$column` overrides step 3 for a caller that needs a different identifier. It replaces
     * `routeKey` rather than adding to it, for the same reason `routeKey` displaces the primary key:
     * one public identifier per lookup, never two. Read scope and eager loads still apply here.
     *
     * @param  Builder<Model>  $query
     * @param  string|null  $column  the identifier column to match, replacing the resource's `routeKey`;
     *                               `null` ⇒ the resource's own declaration, which is every other caller
     */
    public function within(ParticleResource $resource, Builder $query, string $id, ?string $column = null): Model
    {
        return $this->resolve($resource, $query, $id, $column, $resource->scope);
    }

    /**
     * Resolve an operation subject through its declared population boundary.
     *
     * The operation controller applies the operation's own `ability` after this returns. A declared
     * `operationScope` may omit actor-relative list admission, but must preserve tenant, owner,
     * visibility and identity predicates. Without one, the full read scope is retained.
     *
     * @param  Builder<Model>  $query
     */
    public function forOperation(
        ParticleResource $resource,
        Builder $query,
        string $id,
        ?string $column = null,
    ): Model {
        $scope = $resource->operationScope ?? $resource->scope;

        return $this->resolve($resource, $query, $id, $column, $scope);
    }

    /** @param Builder<Model> $query */
    private function resolve(
        ParticleResource $resource,
        Builder $query,
        string $id,
        ?string $column,
        ?Closure $scope,
    ): Model {
        if ($scope !== null) {
            $query = $scope($query) ?? $query;
        }

        if ($resource->includes !== []) {
            $query->with($resource->includes);
        }

        $key = $column ?? $resource->routeKey;

        if ($key !== null) {
            return $query->where($key, $id)->firstOrFail();
        }

        return $query->findOrFail($id);
    }
}
