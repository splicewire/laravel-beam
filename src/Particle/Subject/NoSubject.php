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
 *
 * ⚠️ **Emitting no coordinate puts the mount in the host's `{uri}/{id}` slot.** With
 * {@see pathParameters()} empty the primary URL is the flat `{uri}/{op}`, which is the same two-segment
 * shape as an ordinary record read. Laravel matches first-registered, so a host that declares an
 * UNCONSTRAINED `{uri}/{id}` before the `Particle::ops()` call swallows the operation and answers its
 * own controller with `id = "{op}"` — no error, both routes still in the table. Measured 2026-09-12
 * (`fragment-producers-and-chunking` 02, the flagship's `fragments/search`). Mount a subject-free op
 * ABOVE the resource's `{id}` routes, beside the literal siblings (`{uri}/schema`, `{uri}/filters`) that
 * already had to go there, and pin the order in a test: `Doctor\ParticleSlotCollisionAudit` simulates
 * the THREE-segment `{uri}/{id}/{segment}` slot and cannot see this one. The doctrine's
 * "Where an operation MOUNTS" section carries the same warning.
 */
class NoSubject implements ResolvesOperationSubject
{
    /** @return list<string> */
    public static function pathParameters(): array
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
