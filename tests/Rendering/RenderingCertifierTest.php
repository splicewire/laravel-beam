<?php

namespace Splicewire\Beam\Tests\Rendering;

use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Splicewire\Beam\Rendering\RenderingCertifier;
use Splicewire\Beam\Tests\Fixtures\Rendering\LossyTitleLens;
use Splicewire\Beam\Tests\Fixtures\Rendering\MirrorRendering;
use Splicewire\Beam\Tests\Fixtures\Rendering\TranscriptRendering;
use Splicewire\Beam\Tests\TestCase;

/**
 * The certifier's four laws, kept alive across the dissolution (particle-operation-surface 13 §3).
 *
 * These assertions used to live inside `ResourceRenderingsRouteTest`, interleaved with the route
 * mount they gated: each one certified a fixture and then asserted which verbs the mount produced.
 * Ticket 13 deleted that mount, the registry it read and the controller behind it — and the certifier
 * SURVIVES on purpose, as a dormant declaration-time gate with a named landing site
 * (particle-doctrine-convergence 10/11 built the seam and the `LensRegistry`; the join was never
 * built). A survivor whose only tests were deleted alongside its caller is a survivor in name only, so
 * the four law assertions are lifted here, verbatim in substance, minus the route half that no longer
 * exists.
 *
 * What they pin is the certifier's whole design: **a rendering cannot talk its way to a write verb.**
 *
 *   1. an inverse with a surviving proof certifies `LosslessEligible`;
 *   2. no inverse at all certifies `Lossy`;
 *   3. a `LosslessEligible` CLAIM over a lens that fails the laws is downgraded, not trusted;
 *   4. a claim submitted with ZERO samples is refused — the laws over an empty sample set are
 *      vacuously true, so "submitted nothing" must never read as "survived everything".
 *
 * Zero classes in the estate implement `ReversibleRendering` today, which is the argument that
 * dissolved the registry and is emphatically NOT an argument for deleting this. The fixtures below are
 * the only implementors that have ever existed.
 */
class RenderingCertifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TranscriptRendering::$formats = ['text', 'html'];
    }

    public function test_certifies_a_rendering_whose_reversibility_survives_the_laws(): void
    {
        $this->assertSame(
            Fidelity::LosslessEligible,
            app(RenderingCertifier::class)->certify(new MirrorRendering),
        );

        $this->assertTrue(app(RenderingCertifier::class)->permitsWrite(new MirrorRendering));
    }

    public function test_floors_a_rendering_with_no_inverse_at_lossy(): void
    {
        $this->assertSame(
            Fidelity::Lossy,
            app(RenderingCertifier::class)->certify(new TranscriptRendering),
        );

        $this->assertFalse(app(RenderingCertifier::class)->permitsWrite(new TranscriptRendering));
    }

    public function test_refuses_a_lossless_claim_its_lens_cannot_back(): void
    {
        // Claims LosslessEligible (LensAssociation::bijective) over a lens that fails GetPut.
        $liar = new MirrorRendering('boastful', new LossyTitleLens);

        $this->assertSame(Fidelity::LosslessEligible, $liar->association()->fidelity);
        $this->assertTrue($liar->association()->isLosslessEligible());

        // The claim is not the verdict: exercising the laws downgrades it.
        $this->assertSame(Fidelity::Lossy, app(RenderingCertifier::class)->certify($liar));
    }

    public function test_treats_an_unexercised_lossless_claim_as_uncertified_rather_than_as_proven(): void
    {
        // A lawful lens and an honest-looking claim — but zero samples.
        $unproven = new MirrorRendering('unexercised', proven: false);

        $this->assertSame(Fidelity::LosslessEligible, $unproven->association()->fidelity);
        $this->assertTrue($unproven->reversibilityProof()->empty());
        $this->assertSame(Fidelity::Lossy, app(RenderingCertifier::class)->certify($unproven));
    }
}
