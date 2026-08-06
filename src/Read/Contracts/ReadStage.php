<?php

namespace Splicewire\Beam\Read\Contracts;

use Closure;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Read\ReadPass;
use Splicewire\Beam\Read\Stages\ProjectStage;

/**
 * One stage of the {@see PayloadParticleReader} chain (beam-write-pipeline ticket 13,
 * DESIGN §9d). A stage is an ordinary Laravel pipe over a {@see ReadPass}: it contributes to (or transforms)
 * the projection, then calls `$next($pass)` to continue.
 *
 * The shipped default chain is a single {@see ProjectStage}. A host composes a
 * pipe AFTER it to redact fields, apply actor-scoped visibility, or attach a computed include — a fine-grained
 * read seam that sits INSIDE the reader, distinct from swapping the whole {@see ParticleHydrator} port.
 */
interface ReadStage
{
    /**
     * @param  Closure(ReadPass): ReadPass  $next  the rest of the chain
     */
    public function handle(ReadPass $pass, Closure $next): ReadPass;
}
