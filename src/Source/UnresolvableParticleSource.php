<?php

namespace Splicewire\Beam\Source;

use RuntimeException;

/**
 * Thrown when a {@see Contracts\ParticleSourceResolver} cannot decide the authority of a
 * `$ref` — an unknown scheme, an ambiguous handle, or a grammatically broken reference.
 *
 * Deliberately loud (ADR-0161 §Decisions.4, ticket 01 acceptance): there is NO silent
 * "assume local" default, because guessing local for a foreign ref would route an external
 * write through the local actor's full policy scope (the privilege-escalation the write
 * gate guards — ticket 03).
 */
class UnresolvableParticleSource extends RuntimeException
{
    public static function for(string $ref, string $why): self
    {
        return new self("Cannot resolve particle source for [{$ref}]: {$why}");
    }
}
