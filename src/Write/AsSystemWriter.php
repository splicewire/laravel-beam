<?php

namespace Splicewire\Beam\Write;

/**
 * Run a body of work as the SYSTEM writer — under {@see PermissiveWriteGate}, restoring the host's own
 * binding afterwards.
 *
 * ## Why a console write cannot go through the default gate
 *
 * {@see GateWriteGate} is deny-by-default: it delegates to Laravel's authorization gate, which refuses any
 * ability with no matching policy, for any actor — and a console command has no actor at all. No package or
 * host in the estate declares a `BeamParticle` policy, so **every** console flow that writes a particle body
 * is refused with `The write gate refused a write to [Splicewire\Beam\Models\BeamParticle]`. That is not a
 * misconfiguration to fix per-host: a fresh install has no policies by construction.
 *
 * ## Why permissive is correct here rather than a loosened default
 *
 * {@see PermissiveWriteGate} exists for exactly this — "a server flow whose caller has ALREADY performed
 * authorization". **An operator at a shell running `artisan` IS that authorization**: there is no request,
 * no actor to check, and nothing left for a policy to decide. The deny-by-default binding is untouched for
 * every HTTP path, which is where it earns its keep. The swap is scoped to the callable and reverted in a
 * `finally`, so work that throws cannot leave a permissive gate bound.
 *
 * ## Why this is a shared seam and not a private method
 *
 * It was one, on `BeamSeedCommand` — written when `splicewire:beam:seed` was found unable to write a
 * particle body on any host (beam-docs-satellite ticket 07). The identical refusal then stopped
 * `splicewire:beam:ux:register-from-disk` dead on the first real import (ticket 08), because the argument
 * was never about seeding: it is about a console command with no actor. Any beam console flow that writes
 * through the {@see ParticleWriter} needs it, so it is stated once here rather than
 * rediscovered per command.
 *
 * Package suites do not catch this — they bind their own gate, and a testbench host has no policies to
 * disagree with. It surfaces on a real consumer or not at all.
 */
trait AsSystemWriter
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    protected function asSystemWriter(callable $work): mixed
    {
        // The bind/restore mechanism itself is {@see ScopedWriteGate}'s — shared with the after-persist
        // hook, which binds the write's OWN gate the same way. What is stated HERE is the choice of
        // gate, which is this trait's whole subject.
        return ScopedWriteGate::run(new PermissiveWriteGate, $work, $this->laravel ?? app());
    }
}
