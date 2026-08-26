<?php

namespace Splicewire\Beam\Particle\Subject;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * One record, resolved from the URL's `{id}` — the DEFAULT, and what every declaration got implicitly
 * before the slot existed.
 *
 * ## It resolves through the RESOURCE, which is the change
 *
 * The subject is looked up through the resource's backing and then through
 * {@see ResourceRecordLookup} — the same `scope` / `includes` / `routeKey` tail
 * `ParticleController::findParticle()` applies. So an operation inherits the resource's row-level
 * gate (ADR-0156 §83) instead of needing the host to re-state it in a second vocabulary, which is
 * what hosts were doing: `audiostud`'s `timeline-projects.regenerate` carries an owner ability whose
 * comment says *"auth middleware alone would let any authenticated user regenerate any project"* —
 * a gate the resource had already declared and the operation path threw away.
 *
 * ## The `$model` fallback is load-bearing, not residue
 *
 * An operation may be registered against a resource key that is not a registered particle resource
 * at all — `beam-accounts`' `Sharing::attachTo($resourceKey, $model)` (6 operations), `beam-rank`'s
 * `Resources::attachTo()` (3), and `beam-market`'s four `market-products.*` operations, whose key
 * resolves to no registered resource. For those the declared {@see ParticleOperation::$model} IS the
 * subject class, and the lookup is today's exact line, unchanged.
 *
 * The registry is therefore consulted NON-throwingly ({@see ParticleResourceRegistry::has()}): an
 * unregistered key is an ordinary declaration, not an error.
 *
 * A registered resource whose backing cannot yield a composable query (it implements
 * {@see StreamsRecords} alone, or neither) falls through to the
 * same `$model` path rather than throwing — an operation is not a REST mount, and refusing to run it
 * because its resource is not RESTfully readable would be a new failure this ticket did not buy.
 */
class RecordSubject implements ResolvesOperationSubject
{
    public function __construct(
        protected ?ParticleResourceRegistry $resources = null,
        protected ?ResourceRecordLookup $lookup = null,
    ) {}

    /** @return list<string> */
    public function pathParameters(): array
    {
        return ['id'];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        $id = $parameters['id'] ?? null;

        $id = $id instanceof Model ? (string) $id->getKey() : (string) $id;

        $resources = $this->resources ?? app(ParticleResourceRegistry::class);

        if ($resources->has($operation->resource)) {
            $resource = $resources->get($operation->resource);
            $backing = $resource->backing();

            if ($backing instanceof QueriesRecords) {
                return ($this->lookup ?? new ResourceRecordLookup)->within($resource, $backing->query([]), $id);
            }
        }

        return $operation->model::query()->findOrFail($id);
    }
}
