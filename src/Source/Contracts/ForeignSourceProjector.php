<?php

namespace Splicewire\Beam\Source\Contracts;

use Splicewire\Beam\Source\ParticleShadower;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The beam-core seam for "project a foreign source payload onto a local target schema" (ADR-0161
 * §Projector contract; sourced-particles ticket 06). It is the thin adapter over ticket-04's
 * projection entry — `Schemastud\DataSchemas\Migration\MigrationLadder::forForeignSource()->project()` —
 * behind a port, so {@see ParticleShadower} can be unit-tested with a fake
 * projector (beam's own testbench boot is mid-migration) and a host can swap the projection strategy
 * (declarative `x-source` rung vs. a bespoke custom-transform projector) without touching the shadower.
 *
 * The contract is the target schema `$id`, not the projector (MAP §Projector contract): the returned
 * array MUST validate against `$targetSchema`'s `$id`, or the underlying ladder QUARANTINES / throws.
 * An UNSCHEMATIZED target (no `$id`) is ephemeral-only and the projection throws — a foreign payload
 * that cannot be schema-reconciled never becomes a persisted shadow.
 */
interface ForeignSourceProjector
{
    /**
     * Project `$foreignPayload` (any shape) onto `$targetSchema`, returning the reconciled, local-schema
     * -conforming payload ready for {@see ParticleWriter}.
     *
     * @param  array<string, mixed>  $foreignPayload  the raw foreign source payload
     * @param  array<string, mixed>  $targetSchema  the LOCAL target schema document (its `$id` is the contract)
     * @return array<string, mixed> the projected payload conforming to the target schema
     *
     * @throws \InvalidArgumentException the target is unschematized (no `$id`) — ephemeral-only, never a shadow
     * @throws \RuntimeException the foreign payload could not be projected onto the target (quarantined)
     */
    public function project(array $foreignPayload, array $targetSchema): array;
}
