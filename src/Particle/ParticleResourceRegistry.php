<?php

declare(strict_types=1);

namespace Splicewire\Beam\Particle;

use RuntimeException;
use Splicewire\Beam\Http\Particle\ParticleController;

/**
 * The container-singleton registry of {@see ParticleResource} declarations, keyed by resource key.
 *
 * The **inline** tier registers here (from a service provider) so that `Route::particleResource($uri,
 * $key)` can mount the generic {@see ParticleController} with only a route
 * default naming the key — the controller resolves the full declaration from this registry at request
 * time. The **extension** tier does not need the registry (a subclass returns its resource directly), but
 * may register too so the same resource is reachable both ways.
 */
class ParticleResourceRegistry
{
    /** @var array<string, ParticleResource> */
    private array $resources = [];

    public function register(ParticleResource $resource): void
    {
        $this->resources[$resource->key] = $resource;
    }

    public function get(string $key): ParticleResource
    {
        return $this->resources[$key]
            ?? throw new RuntimeException("No particle resource registered for key [{$key}].");
    }

    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }
}
