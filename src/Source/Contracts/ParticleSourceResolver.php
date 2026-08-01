<?php

namespace Splicewire\Beam\Source\Contracts;

use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Source\ParticleSource;
use Splicewire\Beam\Source\UnresolvableParticleSource;

/**
 * The port that decides the SOURCE AUTHORITY of a particle reference (ADR-0161 §Decisions.1,
 * ticket 01). Given a raw `$ref`, it returns a typed {@see ParticleSource} — local, conduit,
 * or federated — so the {@see ParticleHydrator} router and the
 * writer route by a decided value and never prefix-sniff.
 *
 * Port-in-base / binding-in-host: the interface lives in beam-core (which must NOT depend UP on
 * tower-conduit), and the host binds an implementation that delegates `{provider}:{alias}.{tool}`
 * handles to the already-built conduit reference grammar (ADR-0110) while resolving bare/local and
 * federated refs itself. Three grammars (schema id, conduit handle, source ref); one resolver
 * decides which is which (ADR-0161 §Decisions.4).
 */
interface ParticleSourceResolver
{
    /**
     * Classify `$ref` into a typed source descriptor.
     *
     * @throws UnresolvableParticleSource when the ref's authority is unknown or ambiguous — a
     *                                    loud failure, never a silent "assume local" default.
     */
    public function resolve(string $ref): ParticleSource;
}
