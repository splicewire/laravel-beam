<?php

namespace Splicewire\Beam\Tests\Fixtures\Rendering;

use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Rendering\RenderedDocument;
use Splicewire\Beam\Rendering\ResourceRendering;

/**
 * The plain case: a derived projection offering no inverse at all. Nothing to certify, so it mounts
 * read-only. Its formats come from a mutable static so a test can prove the enumeration is read live per
 * request rather than frozen into the route table.
 */
class TranscriptRendering implements DeclaresDelivery, ResourceRendering
{
    /** @var list<string> */
    public static array $formats = ['text', 'html'];

    public function name(): string
    {
        return 'transcript';
    }

    public function formats(): array
    {
        return static::$formats;
    }

    public function mediaTypes(): array
    {
        return ['text/plain', 'text/html'];
    }

    public function deliveryHeaders(): array
    {
        return ['X-Rendering' => 'Which rendering produced this body.'];
    }

    public function defaultFormat(): ?string
    {
        return 'text';
    }

    public function render(object $subject, ?string $format = null): RenderedDocument
    {
        // No default is substituted upstream: null means "mine" — and "mine" is the DECLARED default, not
        // a second spelling of it, so the documented default and the applied one cannot drift.
        $format ??= $this->defaultFormat();

        return RenderedDocument::raw(
            "{$format}:{$subject->title}",
            $format === 'html' ? 'text/html' : 'text/plain',
            ['X-Rendering' => $this->name()],
        );
    }
}
