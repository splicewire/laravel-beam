<?php

namespace Splicewire\Beam\Rendering;

use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Schemastud\DataSchemas\Overlay\Lens\ReversibleResolver;

/**
 * The one place a rendering's fidelity is decided — and the reason `Route::resourceRenderings()` can
 * make the verb set a function of PROVEN reversibility instead of a declared one.
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
