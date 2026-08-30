<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Delivery\DeliveryResolvers;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\OpenApi\RenderingDeliveryGenerator;

/**
 * Stash what a particle OPERATION actually puts on the wire, for {@see RenderingDeliveryGenerator} to
 * write at document assembly (particle-operation-surface 14).
 *
 * The sibling of {@see ResourceRenderingResponseStrategy} one declaration site over, and deliberately
 * the same shape down to the stash key: the two surfaces produce the identical contract — media types,
 * added headers, the applied default format, the format enumeration — so they share one
 * document-assembly hook rather than growing a second copy of it that drifts.
 *
 * ## Why this exists at all, rather than falling out of `output:`
 *
 * `output:` names a Data class. It answers *what JSON shape comes back*, and is structurally silent
 * about the case that needs answering: an operation that returns a PDF, a calendar, a zip, or a stream
 * of bytes. `media.download` is the estate's proof — a `kind: Read` operation whose handler returns a
 * Symfony `StreamedResponse` straight through, published as an untyped 200 with no media type at all
 * until this landed. And Scribe's own model is one-content-type-per-status
 * (`BaseGenerator::generateEndpointResponsesSpec()` silently discards a second response whose content
 * type differs), so even a hand-written `@response` could not say "200, in these six media types".
 *
 * ## Silence is the whole default
 *
 * Defers (`null`) for any non-operation route, AND returns `[]` — handled, nothing stashed — for an
 * operation that declares no `delivery:`. Every declaration in the estate is in that second state, so
 * this strategy is a no-op on all of them: the generator sees no stash and leaves the endpoint exactly
 * as the response strategies built it. The widening is one-directional by construction.
 *
 * Returns `[]` rather than a fabricated example body for the same reason its rendering sibling does:
 * there is no single example for an endpoint that answers in several media types, and Scribe's example
 * renderer would have to pick one.
 */
class ParticleOperationDeliveryStrategy extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $defaults = $endpointData->route?->defaults ?? [];

        $resource = $defaults[ParticleOperationController::RESOURCE] ?? null;
        $name = $defaults[ParticleOperationController::NAME] ?? null;

        if ($resource === null || $name === null) {
            return null; // Not an operation route — defer.
        }

        // ASK, don't demand (api-surface-coherence 102) — an op route with no registration on this
        // host documents without its delivery contract rather than failing a whole spec build.
        $operation = app(ParticleOperationRegistry::class)->find($resource, $name);

        if ($operation === null) {
            return null;
        }

        $delivery = DeliveryResolvers::contract($operation);

        if ($delivery === null) {
            return []; // Declares no delivery. Handled, nothing stashed — see the class docblock.
        }

        $endpointData->custom[RenderingDeliveryGenerator::STASH] = [
            ...$delivery,
            'resource' => $operation->resource,
            'rendering' => $operation->name,
        ];

        return [];
    }
}
