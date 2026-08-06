<?php

namespace Splicewire\Beam\Write\Contracts;

use Closure;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\WriteContext;

/**
 * One stage of the {@see ParticleWriter} chain (beam-write-pipeline ticket 03,
 * DESIGN §3a). A stage is an ordinary Laravel pipe over a {@see WriteContext}: it does its bit, then either
 * calls `$next($context)` to continue the chain or throws to abort it (an unauthorised or non-conforming
 * write never reaches persist).
 *
 * The shipped default chain is `AuthorizeStage → ValidateStage → PersistStage → EmitStage`; a host composes
 * a new stage into that list to layer a cross-cutting concern (audit trail, tenancy stamp, rate limit,
 * idempotency key) WITHOUT wrapping or subclassing the writer. This is the chainable write seam the original
 * particle design called for — flattened away when the writer was a single method, restored here.
 */
interface WriteStage
{
    /**
     * @param  Closure(WriteContext): WriteContext  $next  the rest of the chain
     */
    public function handle(WriteContext $context, Closure $next): WriteContext;
}
