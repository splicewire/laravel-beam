<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Routing\Route;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;

/**
 * The default {@see RouteMetadataReader}: reads the values off the route's own action array, where
 * {@see BeamRouteProxy} wrote them.
 *
 * These bodies are verbatim what {@see BeamRouteAction}'s statics held before api-surface-coherence 126;
 * the contract prose lives on the interface, which is where a substitute has to read it. **Pure** — no
 * container, no config, no state — which is what lets `BeamRouteAction` fall back to `new self` when
 * nothing is bound instead of becoming boot-order-sensitive.
 */
class RouteActionMetadataReader implements RouteMetadataReader
{
    public function returns(Route $route): ?string
    {
        $value = $this->get($route, 'returns');

        return is_string($value) ? $value : null;
    }

    public function returnsMany(Route $route): bool
    {
        return (bool) $this->get($route, 'returnsMany');
    }

    public function streams(Route $route): array
    {
        $value = $this->get($route, 'streams');

        return is_array($value) ? $value : [];
    }

    public function visibility(Route $route): ?RouteVisibility
    {
        $value = $this->get($route, 'visibility');

        return $value instanceof RouteVisibility ? $value : null;
    }

    public function operationId(Route $route): ?string
    {
        $value = $this->get($route, 'operationId');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function resourceKey(Route $route): ?string
    {
        $value = $route->defaults[ParticleController::RESOURCE]
            ?? $route->defaults[ParticleOperationController::RESOURCE]
            ?? null;

        return is_string($value) ? $value : null;
    }

    protected function get(Route $route, string $key): mixed
    {
        return $route->getAction(BeamRouteProxy::ACTION.'.'.$key);
    }
}
