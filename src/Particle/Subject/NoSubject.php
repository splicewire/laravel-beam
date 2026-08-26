<?php

namespace Splicewire\Beam\Particle\Subject;

use Splicewire\Beam\Particle\ParticleOperation;

/**
 * The operation resolves NOTHING — a collection-level operation.
 *
 * The `index`/`store`/`{resource}/schema` shape: the operation acts on the resource as a whole, so
 * there is no record to load and no `{id}` to read. Declaring it is what lets the authorization plane
 * know, without booting, that a subject-bearing ability has nothing to be checked against — an
 * entitlement (`abilityModel: false`) is the plane such an operation wants.
 *
 * Downstream is unchanged by design: the null flows through `handle`, `runTask`, `runStream` and
 * `respond` exactly as any other subject would.
 */
class NoSubject implements ResolvesOperationSubject
{
    /** @return list<string> */
    public function pathParameters(): array
    {
        return [];
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
