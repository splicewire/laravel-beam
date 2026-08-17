<?php

namespace Splicewire\Beam\Rendering;

use Schemastud\DataSchemas\Overlay\Lens\LensAssociation;

/**
 * A rendering that offers an inverse — and therefore *applies* for a write verb.
 *
 * This is the seam a lens-backed rendering registers into (the lens registry is its own unit): the
 * association is the existing `LensAssociation` core-5, so a rendering built over
 * `Schemastud\DataSchemas\Overlay\Lens` needs no new vocabulary and gets its verb decided by the same
 * `ReversibleResolver::certify()` that already downgrades a law-breaking lens.
 *
 * Implementing this interface grants nothing on its own. {@see RenderingCertifier} runs the laws over
 * {@see reversibilityProof()} and only a surviving `LosslessEligible` association mounts a write verb;
 * everything else mounts read-only. A lossy rendering must never be round-trippable through a write
 * verb that silently discards state — so the write route does not exist rather than existing and
 * warning.
 */
interface ReversibleRendering extends ResourceRendering
{
    /**
     * The declared association binding canonical to rendering. Its `fidelity` field is the rendering's
     * CLAIM and is never read as the verdict — it only decides whether there is anything to prove: a
     * self-declared `Lossy` is already honest and is left lossy, a self-declared `LosslessEligible` must
     * survive the laws.
     */
    public function association(): LensAssociation;

    /** Representative values the laws are exercised against. An empty proof certifies nothing. */
    public function reversibilityProof(): ReversibilityProof;

    /**
     * Fold an edited rendering back onto the subject. Only ever reached through a route the certifier
     * granted, so an implementation may assume its fidelity was proven, not asserted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingest(object $subject, ?string $format, array $payload): RenderedDocument;
}
