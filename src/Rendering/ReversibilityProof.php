<?php

namespace Splicewire\Beam\Rendering;

/**
 * The evidence a {@see ReversibleRendering} submits so its fidelity can be *certified* rather than
 * believed: the representative values the well-behavedness laws are actually exercised against.
 *
 * - `canonicalSamples` — canonical values, checked with GetPut: `put(get(S), S) = S`.
 * - `renderingSamples` — `[editedRendering, priorCanonical]` pairs, checked with PutGet:
 *   `get(put(V, S)) = V`.
 *
 * An EMPTY proof is not a passing proof. `LensLaws` over zero samples is vacuously true, so treating
 * "submitted nothing" as "survived everything" would hand a write verb to any rendering that simply
 * declined to be tested — the exact self-description the certifier exists to refuse. {@see empty()} is
 * therefore a hard fail upstream in {@see RenderingCertifier}.
 */
class ReversibilityProof
{
    /**
     * @param  list<mixed>  $canonicalSamples
     * @param  list<array{0: mixed, 1: mixed}>  $renderingSamples
     */
    public function __construct(
        public array $canonicalSamples = [],
        public array $renderingSamples = [],
    ) {}

    /** No samples at all — nothing was exercised, so nothing is certified. */
    public function empty(): bool
    {
        return $this->canonicalSamples === [] && $this->renderingSamples === [];
    }
}
