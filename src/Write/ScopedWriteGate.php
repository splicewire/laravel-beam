<?php

namespace Splicewire\Beam\Write;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Write\Contracts\WriteGate;

/**
 * Run a body of work with a chosen {@see WriteGate} bound on the container, restoring the host's own
 * binding afterwards — the mechanism {@see AsSystemWriter} was written as, lifted out so it has one
 * implementation and two callers.
 *
 * ## Why a container rebinding rather than an argument
 *
 * Because the collaborator that performs the nested write is not the caller's to parameterize. The
 * estate's storage seam resolves its {@see ParticleWriter} PER WRITE out of the container, on purpose
 * (`ParticleStorageDriver`'s docblock records the outage that taught it: a singleton holding a captured
 * writer pinned whichever gate was bound when it was first resolved, and a later rebind could never
 * reach it). A caller that has already chosen its gate therefore states that choice where such a
 * resolution can see it — the container — for the duration of the work and no longer.
 *
 * Scoped and reverted in a `finally`, so work that throws cannot leave a gate bound.
 *
 * The two callers differ only in WHICH gate they bind: {@see AsSystemWriter} binds
 * {@see PermissiveWriteGate} for a console flow whose operator IS the authorization, and
 * {@see ParticleWriter} binds its OWN gate around an after-persist hook, so a record's immediate
 * relations are written under the same authorization the record itself passed.
 */
class ScopedWriteGate
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function run(WriteGate $gate, callable $work, ?Container $app = null): mixed
    {
        $app ??= app();
        $previous = $app->getBindings()[WriteGate::class] ?? null;

        $app->bind(WriteGate::class, fn () => $gate);

        try {
            return $work();
        } finally {
            if ($previous === null) {
                // Nothing was bound: restore beam-core's own default rather than leaving the scoped gate
                // in place. This is the provider's binding, spelled once more because a host that never
                // overrode it has no binding array entry to put back.
                $app->bind(WriteGate::class, fn ($a) => new GateWriteGate($a->make(Gate::class)));
            } else {
                // Restore the host's own binding verbatim — including its `shared` flag — rather than
                // re-asserting beam's default over the top of a host that deliberately bound something else.
                $app->bind(WriteGate::class, $previous['concrete'], $previous['shared'] ?? false);
            }
        }
    }
}
