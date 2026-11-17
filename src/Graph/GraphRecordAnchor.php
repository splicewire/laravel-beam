<?php

namespace Splicewire\Beam\Graph;

use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\Subject\RecordSubject;
use Splicewire\Beam\Particle\Subject\ResolvesOperationSubject;

/**
 * The subject of a record-anchored graph operation (`graph.neighbours`, `graph.path`): it declares the
 * `{id}` coordinate — so the op mounts at `{uri}/{id}/graph/…`, within the record's routes — but resolves
 * NOTHING (graph-operations 02, review N4).
 *
 * ## Why not {@see RecordSubject}
 *
 * The default {@see RecordSubject} would `findOrFail($id)` through the resource's scoped query at the
 * controller's subject step, BEFORE the handler's read gate runs — so an actor the resource would refuse
 * gets a 404 that reveals whether the record exists, instead of a 403. And it requires a `QueriesRecords`
 * (model-backed) resource, which a graph-only backing need not be (review N6). This resolver sidesteps
 * both: it emits the coordinate and returns null, deferring EVERY existence decision to the handler, which
 * authorizes FIRST ({@see GraphOperationHandler} step 1) and only then asks the ACTOR-SCOPED store whether
 * the anchor is a node ({@see \Rushing\Graphine\Contracts\StructureStore::getNode()}). So the row-scope
 * gate is the store's, exactly as D5 says ("a record the actor cannot list is not a node"), a guest gets
 * 403 not 404, and neighbours/path work on any {@see \Splicewire\Beam\Particle\Backing\GraphsRecords}
 * backing, not only a model-backed one.
 */
class GraphRecordAnchor implements ResolvesOperationSubject
{
    /** @return list<string> */
    public static function pathParameters(): array
    {
        return ['id'];
    }

    public function yieldsSubject(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        return null;
    }
}
