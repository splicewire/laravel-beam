<?php

namespace Splicewire\Beam\Frame;

use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The genuinely stateless bridge from {@see ParticleResourceRegistry} to Frame's agnostic
 * {@see ResourceRegistry} port. Exists ONLY because PHP has no method overloading: this class'
 * `get(): ParticleResource` (REST tier, every existing caller depends on that exact signature) and the
 * port's required `get(): ResourceDefinition` can't both be named `get()` on one class — so this is the
 * one-line-per-method forwarder that lets both shapes exist without either compromising.
 *
 * No storage, no logic, no state of its own — every call passes straight through to
 * {@see ParticleResourceRegistry::hasFramedResource()}/{@see ParticleResourceRegistry::definition()}/
 * {@see ParticleResourceRegistry::definitions()}. Replaces the retired `AdminResourceRegistry`, which
 * carried its OWN parallel `$declarations` store (the thing that made a resource need registering twice —
 * once here, once there); this adapter carries nothing.
 */
class ParticleResourceRegistryPort implements ResourceRegistry
{
    public function __construct(private ParticleResourceRegistry $registry) {}

    public function has(string $key): bool
    {
        return $this->registry->hasFramedResource($key);
    }

    public function get(string $key): ResourceDefinition
    {
        return $this->registry->definition($key);
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function all(): array
    {
        return $this->registry->definitions();
    }
}
