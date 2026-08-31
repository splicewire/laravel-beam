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
 * ## ⚠️ The `$model` fallback was defended here as load-bearing. It is not, and the count was false
 *
 * **This section used to read:** *"An operation may be registered against a resource key that is not a
 * registered particle resource at all — `beam-accounts`' `Sharing::attachTo($resourceKey, $model)`
 * (6 operations), `beam-rank`'s `Resources::attachTo()` (3), and `beam-market`'s four
 * `market-products.*` operations … For those the declared `$model` IS the subject class."* It is
 * amended rather than deleted, because the defence it made is the reason `$model` survived ticket 02
 * and the way it was wrong is the part worth keeping.
 *
 * **It counted DECLARATION SITES in source and reported them as LIVE REGISTRATIONS.** Re-measured
 * 2026-08-31 from BOOTED registries at every `~/Herd` root (21 roots resolved by realpath, 15 boot
 * with beam; 5 do not vendor beam, `numero-legacy` has no `vendor/`), comparing each registered
 * operation's `$resource` against {@see ParticleResourceRegistry}:
 *
 * ```
 * 107 registered particle operations across 15 hosts
 *   0 whose `$resource` key resolves to no registered ParticleResource
 * ```
 *
 * **Zero.** Not "few" — none. Every one of the three shapes named above is today backed by a real
 * resource: particle-operation-surface ticket 19 declared them (market `8d847a9`, accounts `e3ffceb`,
 * rank `a15a82e`), and the two factories were never the population the count implied anyway —
 * `Resources::attachTo()` has **zero** call sites estate-wide (every hit is prose), and
 * `Sharing::attachTo()` has **one**, `~/Herd/audiostud` on key `songs`, which audiostud registers as
 * an ordinary scoped resource.
 *
 * So no live registration reaches the line below. What {@see ParticleOperation::$model} is still FOR,
 * given that, is particle-operation-surface ticket 18's question and not this docblock's to answer.
 *
 * The registry is still consulted NON-throwingly ({@see ParticleResourceRegistry::has()}): an
 * unregistered key is an ordinary declaration, not an error. That part was always right, and it is a
 * fact about the HOST — by AGENTS.md's own rule a check whose answer depends on the host is an
 * advisory finding, never a throw at registration.
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
