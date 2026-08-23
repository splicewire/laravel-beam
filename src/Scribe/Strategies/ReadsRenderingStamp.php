<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Rendering\Http\RenderingsController;
use Splicewire\Beam\Rendering\ResourceRendering;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;

/**
 * The one seam the three rendering strategies share: resolve a Scribe endpoint back to the
 * {@see ResourceRendering} its route was mounted from.
 *
 * `Route::resourceRenderings()` already stamps its per-route config onto the route's `defaults` as a
 * closure-free array (so `route:cache` survives) — the same seam `_particle` gives the particle
 * strategies. Nothing new is declared to make a rendering documentable; the mount-time stamp is read at
 * doc-generation time exactly as {@see RenderingsController} reads it at request time, and the rendering
 * itself is looked up LIVE from the registry so its format enumeration is never frozen into the artifact.
 *
 * Every strategy using this defers (`null`) for a route carrying no stamp, so all three compose
 * transparently alongside Scribe's stock strategies.
 */
trait ReadsRenderingStamp
{
    /**
     * @return array{0: array<string, mixed>, 1: ResourceRendering}|null the mount config + the live rendering
     */
    protected function renderingStamp(ExtractedEndpointData $endpointData): ?array
    {
        $config = $endpointData->route?->defaults[RenderingsController::CONFIG] ?? null;

        if (! is_array($config) || ! isset($config['resource'], $config['rendering'])) {
            return null;
        }

        $rendering = app(ResourceRenderingRegistry::class)->find(
            (string) $config['resource'],
            (string) $config['rendering'],
        );

        // Mounted from the registry, so absence means the registration was removed after the route table
        // was built. A reportable inconsistency the runtime raises on its own — not a reason to fail a
        // whole spec build, which is the call ParticleResponseStrategy already makes for a missing op.
        return $rendering === null ? null : [$config, $rendering];
    }

    /**
     * What the rendering says it puts on the wire, with the not-declared case spelled out rather than
     * guessed: the wildcard media type, no added headers, no default. Documenting "delivers something,
     * has not said what" is honest; inventing `application/json` because most things are JSON is the
     * exact class of guess this whole effort removes.
     *
     * @return array{mediaTypes: list<string>, headers: array<string, string>, default: ?string}
     */
    protected function delivery(ResourceRendering $rendering): array
    {
        if (! $rendering instanceof DeclaresDelivery) {
            return ['mediaTypes' => ['*/*'], 'headers' => [], 'default' => null];
        }

        $types = array_values(array_unique($rendering->mediaTypes()));

        return [
            'mediaTypes' => $types === [] ? ['*/*'] : $types,
            'headers' => $rendering->deliveryHeaders(),
            'default' => $rendering->defaultFormat(),
        ];
    }

    /** The display noun for a resource key — `splice-compositions` is not a word, "Composition" is. */
    protected function renderingSubject(string $resourceKey): string
    {
        return Str::singular(Str::headline($resourceKey));
    }
}
