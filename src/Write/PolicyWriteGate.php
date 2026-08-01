<?php

namespace Splicewire\Beam\Write;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\Contracts\WriteGate;

/**
 * A {@see WriteGate} for writes that are ALREADY authorized by their surrounding surface (an
 * authenticated, realm-guarded route) — the generalized, beam-resident form of the retired
 * `Splicewire\Beam\Frame\FrameWriteGate` (ADR-0156; it only ever used Illuminate + beam contracts, so it
 * moves down into beam cleanly).
 *
 * Unlike {@see GateWriteGate} (which is deny-by-default via the model's `create`/`update` policy — the
 * right default for the public/anonymous world), this gate is for the authenticated editor world where
 * the route middleware IS the gate:
 *
 *  - **permits** when no `$policy` is declared — the surrounding auth already authorized the write, so a
 *    plain model-backed resource needs no per-model policy;
 *  - **delegates** to the declared `$policy` ability through the Laravel gate when one IS set — so a
 *    resource that opts into a policy has it enforced against the subject.
 *
 * This preserves the exact Frame-editor behaviour while making "respects the WriteGate policy" true for
 * any resource that declares one, without imposing the anonymous deny-default on an already-authorized
 * edit.
 */
class PolicyWriteGate implements WriteGate
{
    public function __construct(
        private Gate $gate,
        private ?string $policy = null,
    ) {}

    public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
    {
        // No per-resource policy: the surrounding auth/realm middleware already authorized the write.
        if ($this->policy === null || $this->policy === '') {
            return true;
        }

        $gate = $actor instanceof Authenticatable ? $this->gate->forUser($actor) : $this->gate;

        return $gate->allows($this->policy, $subject);
    }
}
