<?php

namespace Splicewire\Beam\Particle;

use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Registry\RouteContextEntry;

/**
 * The ONE derivation of a resource's list route name: its declared `routeName`, else `{key}.index`.
 *
 * Frame's {@see RouteContextEntry} keys the router's leaves by this name and
 * the nav joins on it, so every place that needs "which leaf is this resource's list" — the collector
 * attaching resource children, the dashboard backing stamping hrefs, the participation rule matching a
 * rail leaf back to a resource — reads it from here rather than re-spelling the fallback.
 */
final class ListRouteName
{
    public static function of(ResourceDefinition|ParticleResource $resource): string
    {
        return $resource instanceof ResourceDefinition
            ? self::for($resource->key, $resource->nav->routeName)
            : self::for($resource->key, $resource->routeName);
    }

    public static function for(string $key, ?string $routeName = null): string
    {
        return $routeName ?? $key.'.index';
    }
}
