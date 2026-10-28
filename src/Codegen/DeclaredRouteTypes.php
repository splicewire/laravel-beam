<?php

namespace Splicewire\Beam\Codegen;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\RouteMetadataReader;
use Splicewire\Beam\Routing\RouteReturnType;

/**
 * Every DTO a route declares as its response body or stream payload, read the way the client manifest reads it.
 *
 * The route half of {@see DeclaredParticleTransformedProvider}'s seed (ts-transformer-convergence B2). The
 * client manifest names a route's types through {@see RouteReturnType::for()} and {@see RouteReturnType::streamsFor()};
 * this reads the same two declaration sites those consult first, so every type a route's client references is
 * also a root of the generated tree:
 *
 *  - the response: an explicit `->beam()->returns()`, else the lowest-2xx `#[ResponseFromData]` on the controller
 *    method (an error body never counts);
 *  - the streams: an explicit `->beam()->streams()`, else the method's `#[StreamsFromData]`, every variant of
 *    every event.
 *
 * Not read: a particle operation's `output:` and a resource's `data`, which {@see DeclaredParticleTypes} already
 * seeds, and `#[RequestFromData]`, which no reader seeds yet.
 *
 * The router is read in {@see declared()}, never in the constructor, so routes registered after this object is
 * built are seen.
 */
final class DeclaredRouteTypes
{
    public function __construct(
        private Router $router,
        private RouteReturnType $returns,
        private ?RouteMetadataReader $meta = null,   // defaults to BeamRouteAction::reader()
    ) {}

    /**
     * Normalized like {@see DeclaredParticleTypes::declared()}: class-strings only, leading `\` trimmed,
     * deduplicated by class with route order preserved.
     *
     * @return array<class-string, list<string>> class => declaration slots, route order
     */
    public function declared(): array
    {
        $meta = $this->meta ?? BeamRouteAction::reader();
        $declared = [];

        $note = function (mixed $class, string $slot) use (&$declared): void {
            if (! is_string($class) || trim($class) === '') {
                return;
            }

            /** @var class-string $class */
            $class = ltrim($class, '\\');
            $declared[$class][] = $slot;
        };

        /** @var Route $route */
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $label = $route->getName() ?? $route->uri();

            $note($meta->returns($route) ?: $this->returns->fromResponseAttribute($route), "route [{$label}] returns:");

            $streams = $meta->streams($route) ?: $this->returns->fromStreamsAttribute($route);

            foreach ($streams as $event => $payloads) {
                foreach ((array) $payloads as $payload) {
                    $note($payload, "route [{$label}] streams: [{$event}]");
                }
            }
        }

        return $declared;
    }
}
