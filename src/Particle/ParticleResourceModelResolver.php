<?php

namespace Splicewire\Beam\Particle;

use RuntimeException;
use Rushing\DataFilters\Contracts\ResourceModelResolver;

/**
 * beam's half of `rushing/laravel-data-filters`' model-resolver port (its ADR-0008) — the adapter
 * that lets a `#[ResourceFilter]` omit `model:` and have it resolved from the
 * {@see ParticleResource} already declared under the same key.
 *
 * The port/adapter split is the fleet's usual shape (`PayoutGatewayResolver`, `EntitlementGate`):
 * the foundation package declares the seam and never binds it, and the higher-tier package fills
 * it in, because beam is the one that knows {@see ParticleResourceRegistry} exists. Without this,
 * a Filter Data class would have to restate a model its sibling `#[ParticleResource]` already
 * names, under the same key — the duplication the attribute exists to remove.
 *
 * Absence is a return value here, never an exception: an unknown key, or one registered as a raw
 * Frame `ResourceDefinition` with no particle declaration behind it, yields null and lets
 * data-filters decide what that means. Its caller raises an error naming the seam; this resolver
 * throwing first would replace that message with a worse one.
 */
class ParticleResourceModelResolver implements ResourceModelResolver
{
    public function __construct(private ParticleResourceRegistry $registry) {}

    /**
     * @return class-string|null
     */
    public function resolveModel(string $resourceKey): ?string
    {
        if (! $this->registry->has($resourceKey)) {
            return null;
        }

        try {
            return $this->registry->get($resourceKey)->model;
        } catch (RuntimeException) {
            // Registered, but as a raw ResourceDefinition escape-hatch entry — `has()` is true and
            // `get()` throws. There is no particle declaration, so there is no model to hand back.
            return null;
        }
    }
}
