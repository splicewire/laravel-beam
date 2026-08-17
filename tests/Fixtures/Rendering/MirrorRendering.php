<?php

namespace Splicewire\Beam\Tests\Fixtures\Rendering;

use Schemastud\DataSchemas\Overlay\Lens\DirectedLens;
use Schemastud\DataSchemas\Overlay\Lens\LensAssociation;
use Splicewire\Beam\Rendering\RenderedDocument;
use Splicewire\Beam\Rendering\ReversibilityProof;
use Splicewire\Beam\Rendering\ReversibleRendering;

/**
 * A lens-backed rendering that submits real evidence and survives the laws — the lossless-eligible case
 * that earns both verbs. `$lens` and `$claim` are injectable so the same fixture can also play the
 * dishonest cases (a `LosslessEligible` claim over a law-breaking lens) and the unexercised case (an
 * empty proof).
 */
class MirrorRendering implements ReversibleRendering
{
    public function __construct(
        private string $name = 'mirror',
        private ?DirectedLens $lens = null,
        private bool $proven = true,
    ) {
        $this->lens ??= new TitleLens;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function formats(): array
    {
        return ['json'];
    }

    public function association(): LensAssociation
    {
        // A LosslessEligible CLAIM — deliberately asserted even when the lens cannot back it, because the
        // claim is never the verdict.
        return LensAssociation::bijective('urn:fixture:subject', '/rendering', $this->lens);
    }

    public function reversibilityProof(): ReversibilityProof
    {
        if (! $this->proven) {
            return new ReversibilityProof;
        }

        return new ReversibilityProof(
            canonicalSamples: [['title' => 'a', 'private' => 'keep']],
            renderingSamples: [[['title' => 'edited'], ['title' => 'a', 'private' => 'keep']]],
        );
    }

    public function render(object $subject, ?string $format = null): RenderedDocument
    {
        return RenderedDocument::payload(['read' => $subject->title]);
    }

    public function ingest(object $subject, ?string $format, array $payload): RenderedDocument
    {
        $subject->title = (string) ($payload['title'] ?? $subject->title);

        return RenderedDocument::payload(['wrote' => $subject->title]);
    }
}
