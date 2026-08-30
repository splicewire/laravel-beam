<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Splicewire\Beam\Scribe\OpenApi\RenderingDeliveryGenerator;

/**
 * Stash what a rendering route actually returns, for {@see RenderingDeliveryGenerator}
 * to write at document assembly (api-surface-coherence ticket 32 §C).
 *
 * ## Why this stashes instead of returning responses
 *
 * Scribe's response model is one-content-type-per-status. `BaseGenerator::generateEndpointResponsesSpec()`
 * keys responses by status code, and when a second response arrives for a status it already holds, it
 * merges into a `oneOf` ONLY if the content types match — a second response with a DIFFERENT content type
 * is silently discarded. So the stock path structurally cannot say "200, and here are the six media types
 * it comes back as", which is precisely what a rendering endpoint is.
 *
 * The stash-then-assemble shape is not invented here: it is what `UseDataResponse` and
 * {@see ParticleResponseStrategy} already do (`custom['dataResponseSchemas']` → `DataSchemaGenerator`) for
 * the neighbouring problem — `$ref`s, which Scribe's inline-only model also cannot express.
 *
 * ## What gets stashed
 *
 * The whole delivery contract, because the generator is downstream of the route being dropped: media
 * types, the added response headers, the applied default format, and the format enumeration. The 422 is
 * derived rather than stashed — its envelope is the framework's, identical for every rendering.
 *
 * Returns `[]` (handled, nothing for the HTML/Postman example) rather than a fabricated example body:
 * there is no single example for an endpoint that answers in six media types, and Scribe's example
 * renderer would have to pick one.
 */
class ResourceRenderingResponseStrategy extends Strategy
{
    use ReadsRenderingStamp;

    /**
     * The endpoint `custom` key the document-assembly hook reads.
     *
     * An ALIAS since particle-operation-surface 14 — the key moved to
     * {@see RenderingDeliveryGenerator::STASH}, which is the class that survives ticket 13's deletion of
     * this one. Same value, so every existing reference keeps working.
     */
    public const STASH = RenderingDeliveryGenerator::STASH;

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $stamp = $this->renderingStamp($endpointData);

        if ($stamp === null) {
            return null; // Not a rendering route — defer.
        }

        [$config, $rendering] = $stamp;

        $endpointData->custom[self::STASH] = [
            ...$this->delivery($rendering),
            'formats' => $rendering->formats(),
            'resource' => (string) $config['resource'],
            'rendering' => (string) $config['rendering'],
        ];

        return [];
    }
}
