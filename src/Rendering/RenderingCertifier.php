<?php

namespace Splicewire\Beam\Rendering;

use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Schemastud\DataSchemas\Overlay\Lens\ReversibleResolver;

/**
 * The one place a rendering's fidelity is decided — and the reason a mount can make the verb set a
 * function of PROVEN reversibility instead of a declared one.
 *
 * ⚠️ **Its caller was deleted by particle-operation-surface 13** along with the rest of the rendering
 * subsystem. It survives DELIBERATELY, and 13 §3 argues it at length: the write half had never fired
 * (zero implementors of {@see ReversibleRendering} estate-wide, so `certify()` was `Lossy` 3-of-3 and
 * no POST route ever mounted), which is the argument for dissolving the REGISTRY and emphatically not
 * for deleting this. particle-doctrine-convergence 10 built the mount explicitly to "shape the seam so
 * a lens-backed rendering can register into it later, at which point proven reversibility decides the
 * verb", and 11 built `LensRegistry`; both closed, the join never built. The intent is dormant with a
 * named landing site, and 13 moved that site from a private registry to the operation surface.
 * `Tests\Rendering\RenderingCertifierTest` is what keeps the laws exercised in the meantime.
 *
 * Certification is a downgrade-only pipeline with `Lossy` as the floor, mirroring the posture
 * `ReversibleResolver::certify()` already takes toward a law-breaking lens (it relabels the claim rather
 * than trusting it). Three gates, all of which must open before a write verb exists:
 *
 *  1. the rendering offers an inverse at all ({@see ReversibleRendering}) — no inverse, nothing to
 *     certify, `Lossy`;
 *  2. it submits a non-empty {@see ReversibilityProof} — the laws over zero samples are vacuously true,
 *     so an unexercised claim is a claim, not a certification, and is refused as `Lossy`;
 *  3. the association survives GetPut/PutGet against every submitted sample — the resolver downgrades
 *     it to `Lossy` otherwise.
 *
 * A rendering therefore CANNOT talk its way to a write verb. It may only submit evidence, and the worst
 * thing a dishonest `LosslessEligible` claim can achieve is the read-only surface it would have had
 * anyway.
 */
class RenderingCertifier
{
    public function __construct(private ReversibleResolver $resolver) {}

    /** The certified fidelity of a rendering — never the fidelity it claims. */
    public function certify(ResourceRendering $rendering): Fidelity
    {
        if (! $rendering instanceof ReversibleRendering) {
            return Fidelity::Lossy;
        }

        if ($rendering->reversibilityProof()->empty()) {
            return Fidelity::Lossy;
        }

        $proof = $rendering->reversibilityProof();

        return $this->resolver->certify(
            $rendering->association(),
            $proof->canonicalSamples,
            $proof->renderingSamples,
        )->fidelity;
    }

    /** Does the certified fidelity permit a write verb? The macro's whole decision, in one call. */
    public function permitsWrite(ResourceRendering $rendering): bool
    {
        return $this->certify($rendering) === Fidelity::LosslessEligible;
    }
}
