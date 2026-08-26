<?php

namespace Splicewire\Beam\Particle\Subject;

use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * The acting principal IS the subject — the `me` shape.
 *
 * It consumes no path parameter and still yields a subject, which is the case that makes
 * {@see ResolvesOperationSubject::yieldsSubject()} irreducible to `pathParameters() === []`.
 *
 * The actor arrives as an argument, from the transport's
 * {@see ActorPort} — never from `Auth::user()`, so an operation
 * declaring this resolves identically over MCP, which has no ambient HTTP user to read.
 */
class ActorSubject implements ResolvesOperationSubject
{
    /** @return list<string> */
    public function pathParameters(): array
    {
        return [];
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
        return is_object($actor) ? $actor : null;
    }
}
