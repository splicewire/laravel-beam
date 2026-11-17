<?php

namespace Splicewire\Beam\Graph;

use Splicewire\Beam\Graph\Data\GraphCyclesData;
use Splicewire\Beam\Graph\Data\GraphCyclesInput;
use Splicewire\Beam\Graph\Data\GraphNeighboursInput;
use Splicewire\Beam\Graph\Data\GraphPathData;
use Splicewire\Beam\Graph\Data\GraphPathInput;
use Splicewire\Beam\Graph\Data\GraphRefusalData;
use Splicewire\Beam\Graph\Data\GraphSliceData;
use Splicewire\Beam\Graph\Data\GraphSliceInput;
use Splicewire\Beam\Particle\Backing\GraphsRecords;
use Splicewire\Beam\Particle\OperationCall;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;

/**
 * Derives the four graph read operations for a resource whose backing graphs its records
 * (graph-operations 02, D3/D6). It is the registration-time twin of {@see GraphsRecords}: when a
 * resource with that capability registers ({@see \Splicewire\Beam\Particle\ParticleResourceRegistry::register()}),
 * these four are registered in the operation registry for its key — no declaration on the resource, no
 * mount verb of their own. They ride the existing `->ops()` spelling, which stays off unless the host
 * asks (D6), so a host that never writes `->ops()` routes none of them.
 *
 * The four, addressed under `graph` (D10, mounted by ticket 03's nested-name + subject-free rules):
 *
 * | name | address | subject |
 * |---|---|---|
 * | `graph`            | `GET {uri}/graph`                  | none — a bounded slice, optionally from roots |
 * | `graph.cycles`     | `GET {uri}/graph/cycles`           | none |
 * | `graph.neighbours` | `GET {uri}/{id}/graph/neighbours`  | the record |
 * | `graph.path`       | `GET {uri}/{id}/graph/path`        | the record, to a target id |
 *
 * NOT derived: `rank`, and no operation that passes a native query through (scope 6). Each names its
 * `input:` and `output:` from ticket 01, is `kind: Read`, `GET`, and declares its refusal body through
 * `errors:`. Each is a Read whose gate is its QUERY SCOPE (the resource read gate + the backing's scoped
 * store, applied in {@see GraphOperationHandler}), which is why `ability:` is left undeclared rather than
 * spelled `false`: it is not ungated — it is gated the way a list read is (see {@see ParticleOperation}'s
 * note on a Read's `null` ability).
 */
class GraphOperations
{
    /** The registrant the four derived operations are recorded under — beam itself (review N5). */
    private const REGISTRANT = 'splicewire/laravel-beam';

    /**
     * Register the four operations for `$resource` when — and only when — its backing graphs records. A
     * resource without the {@see GraphsRecords} capability gets none (scope 1): the check is static, so
     * registration never resolves the backing.
     *
     * Idempotent: the operation registry supersedes a re-registration of the same `{resource}.{name}`
     * key, so a resource re-registering (last-wins) re-derives harmlessly.
     */
    public static function deriveFor(ParticleResource $resource, ParticleOperationRegistry $operations): void
    {
        // The capability, or (graph-operations 04) a `graph:` slot that supplies it over the resource's model.
        if (! $resource->graphsRecords()) {
            return;
        }

        foreach (self::operationsFor($resource->key) as $operation) {
            // Never silently displace a host's own operation at this key (review N5). The op registry is
            // last-wins, so a derived `graph.path` would supersede a host's `#[ParticleOp(name: 'graph.path')]`.
            // Supersede only our OWN prior derivation (idempotent re-registration); a key another registrant
            // holds is left to that registrant.
            $registrant = $operations->registrantOf($operation->key());

            if ($registrant !== null && $registrant !== self::REGISTRANT) {
                continue;
            }

            $operations->register($operation, by: self::REGISTRANT);
        }
    }

    /**
     * The four operation declarations for a resource key — also the one place a reader (a test, an audit)
     * can enumerate exactly what the capability derives.
     *
     * @return list<ParticleOperation>
     */
    public static function operationsFor(string $resourceKey): array
    {
        $errors = [422 => GraphRefusalData::class];

        return [
            // The bounded slice, subject-free, mounted above `{id}` (ticket 03).
            new ParticleOperation(
                resource: $resourceKey,
                name: 'graph',
                kind: OperationKind::Read,
                handle: fn (OperationCall $call) => app(GraphOperationHandler::class)->slice($resourceKey, $call),
                input: GraphSliceInput::class,
                output: GraphSliceData::class,
                subject: NoSubject::class,
                method: HttpMethod::Get,
                summary: 'Graph slice',
                description: 'A bounded slice of this resource\'s graph, optionally from named roots.',
                errors: $errors,
            ),
            new ParticleOperation(
                resource: $resourceKey,
                name: 'graph.cycles',
                kind: OperationKind::Read,
                handle: fn (OperationCall $call) => app(GraphOperationHandler::class)->cycles($resourceKey, $call),
                input: GraphCyclesInput::class,
                output: GraphCyclesData::class,
                subject: NoSubject::class,
                method: HttpMethod::Get,
                summary: 'Graph cycles',
                description: 'The cycles in this resource\'s graph, at most the asked number.',
                errors: $errors,
            ),
            // The record-anchored pair: the `{id}` coordinate comes from GraphRecordAnchor, which resolves
            // NO record (review N4) — the handler authorizes first, then the scoped store's getNode is the
            // row-scope gate, so a guest gets 403 not a record-revealing 404 and any GraphsRecords backing
            // serves these (no model lookup required; review N6).
            new ParticleOperation(
                resource: $resourceKey,
                name: 'graph.neighbours',
                kind: OperationKind::Read,
                handle: fn (OperationCall $call) => app(GraphOperationHandler::class)->neighbours($resourceKey, $call),
                input: GraphNeighboursInput::class,
                output: GraphSliceData::class,
                subject: GraphRecordAnchor::class,
                method: HttpMethod::Get,
                summary: 'Graph neighbours',
                description: 'The neighbourhood of a record out to a depth, in a direction.',
                errors: $errors,
            ),
            new ParticleOperation(
                resource: $resourceKey,
                name: 'graph.path',
                kind: OperationKind::Read,
                handle: fn (OperationCall $call) => app(GraphOperationHandler::class)->path($resourceKey, $call),
                input: GraphPathInput::class,
                output: GraphPathData::class,
                subject: GraphRecordAnchor::class,
                method: HttpMethod::Get,
                summary: 'Graph path',
                description: 'The shortest path from a record to a target record.',
                errors: $errors,
            ),
        ];
    }
}
