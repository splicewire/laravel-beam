<?php

namespace Splicewire\Beam\Particle;

use Rushing\DataFilters\Contracts\ResourceModelResolver;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;

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
 * Absence is a return value here, never an exception: an unknown key, or one whose backing declares no
 * single model, yields null and lets data-filters decide what that means. Its caller raises an error
 * naming the seam; this resolver throwing first would replace that message with a worse one.
 *
 * Reads {@see ModelResourceIndex}. It used to call `$registry->get($key)->model` inside a `try/catch`
 * for the `RuntimeException` a raw `ResourceDefinition` entry provoked — a catch that was load-bearing
 * only while two declaration types shared one registry, and that silently swallowed the very resources
 * this seam was meant to answer for (ticket 06 measured `tenants` as one of them, so `816321d` never
 * delivered the benefit it was landed for). The index answers from the declared set directly, so
 * absence is now a fact about backings rather than an exception being caught.
 */
class ParticleResourceModelResolver implements ResourceModelResolver
{
    public function __construct(private ParticleResourceRegistry $registry) {}

    /**
     * @return class-string|null
     */
    public function resolveModel(string $resourceKey): ?string
    {
        foreach ((new ModelResourceIndex($this->registry))->all() as $model => $keys) {
            if (in_array($resourceKey, $keys, true)) {
                return $model;
            }
        }

        return null;
    }
}
