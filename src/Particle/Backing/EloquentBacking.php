<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The backing for the ordinary case: one Eloquent model, queried and written the ordinary way.
 *
 * ## Why a resource does not hand-roll a backing to say "I am a model"
 *
 * ~30 of this estate's resources are exactly this. Ticket 11 deleted the `model` / `source` /
 * `sourceKind` triple from the declaration in favour of one polymorphic `backing:` slot, and the risk
 * that came with it was making the common case verbose — every ordinary resource writing out a backing
 * class to state the least interesting thing about itself.
 *
 * It does not, because the slot accepts **either** a `ResourceBacking` **or** an Eloquent model
 * class-string, and a model class-string is wrapped in this. So the ordinary declaration stays
 * `backing: Silo::class` — one word longer than the `model: Silo::class` it replaces — while the
 * declaration genuinely carries no model FIELD, which is what let the two declaration types merge (the
 * blocker being `ParticleResource::$model`'s required, non-nullable `string` against `members` and
 * `review-queue`, which ship no model at all).
 *
 * ## Capabilities
 *
 * All four. It queries ({@see QueriesRecords}), streams ({@see StreamsRecords}, via
 * {@see BacksEloquent}), resolves for write ({@see WritesRecords}) and backs a model
 * ({@see BacksModel}) — so an ordinary resource may open any affordance, and the
 * capability-is-the-ceiling check never fires for it.
 *
 * ⚠️ It does NOT implement {@see ResolvesRecord}. That capability yields a projected
 * {@see ResolvedRecord} for a union-style detail read; a model-backed detail runs through the
 * declaration's own read projection (`data:` / `project:`), which is a Data-class concern and stays
 * ticket 12's. Adding it here would give the model path a second, competing projection route — the
 * exact duplication ticket 08 found when `hydrate()` and `project()` turned out to be one job.
 *
 * ## `$filters` is accepted and not interpreted
 *
 * The bag reaches the declaration's filter surface (data-filters, saved filters, owner scoping) through
 * the caller, not through here. This backing hands back the base query for the model; interpreting
 * `filter[...]` is the filterable path's job and is unchanged by ticket 11.
 */
class EloquentBacking implements BacksModel, QueriesRecords, StreamsRecords, WritesRecords
{
    use BacksEloquent;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(protected string $model) {}

    public function modelClass(): string
    {
        return $this->model;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters): Builder
    {
        return $this->model::query();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function resolveForWrite(string $id, array $filters): ?Model
    {
        return $this->model::query()->find($id);
    }

    public function newRecord(): Model
    {
        return new $this->model;
    }
}
