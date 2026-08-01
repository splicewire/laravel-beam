<?php

namespace Splicewire\Beam\Source;

use Schemastud\DataSchemas\Migration\MigrationLadder;
use Schemastud\DataSchemas\Migration\TransformRegistry;
use Splicewire\Beam\Source\Contracts\ForeignSourceProjector;

/**
 * The default {@see ForeignSourceProjector}: the thin beam-side adapter that maps a foreign-source
 * projection onto ticket-04's ladder entry (`MigrationLadder::forForeignSource()->project()`,
 * schemastud). The projection IS a migration whose bottom rung is a foreign shape rather than a prior
 * version (MAP §Projection-format wheel verdict: "the ladder IS the wheel"): the declarative `x-source`
 * rung projects nested foreign values, the required-fields floor fills the rest, and the custom-transform
 * escape hatch handles the bespoke tail — all reusing the same {@see MigrationLadder} machinery.
 *
 * An UNSCHEMATIZED target (no `$id`) makes `project()` throw at the ladder — enforcing the
 * "unschematized source → ephemeral `Data` only, never a persisted shadow" floor without any check here.
 * A payload the ladder cannot reconcile is QUARANTINED (`->migrated === null`); this adapter surfaces
 * that as a `RuntimeException` so the shadower never persists an un-reconciled shadow.
 */
class LadderForeignSourceProjector implements ForeignSourceProjector
{
    public function __construct(private ?TransformRegistry $transforms = null) {}

    public function project(array $foreignPayload, array $targetSchema): array
    {
        // Throws InvalidArgumentException on an unschematized target ($id-less) — the ephemeral floor.
        $result = MigrationLadder::forForeignSource($this->transforms)->project($foreignPayload, $targetSchema);

        if ($result->migrated === null) {
            throw new \RuntimeException(
                'Foreign source payload could not be projected onto target schema "'
                .($targetSchema['$id'] ?? '?').'" (quarantined): the projected payload does not '
                .'validate, so it may not become a persisted shadow.'
            );
        }

        return $result->migrated;
    }
}
