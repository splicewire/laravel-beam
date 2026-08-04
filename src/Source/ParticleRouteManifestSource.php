<?php

namespace Splicewire\Beam\Source;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The **particle-route source** — the satellite-side {@see RouteManifestSource}. Where the platform binds a
 * Tower-backed source (its `Tenant`/`AdminRouteManifest`), a satellite has no Tower: it mounts particle
 * routes directly (`Route::particleResource($uri, $key, only:)` from a host provider). This source reads
 * those MOUNTED routes off the live route table and reconstructs the enriched manifest the generator wants.
 *
 * How it works:
 *   1. Walk the live route table; keep every route whose action targets {@see ParticleController} and
 *      carries the `_particle` resource-key default (the macro stamps both).
 *   2. Group by resource key, deriving `path` (the URI, leading slash stripped) + `methods` off the route.
 *   3. Derive `returns` for INDEX/SHOW (the read verbs) from the resource's OUTPUT DATA class: the beam
 *      package already knows each resource's Data class — it lives on the {@see ParticleResource}
 *      declaration in the {@see ParticleResourceRegistry}. `data` names the read/output DTO; when it is
 *      null (the single-class default) the annotated projection class IS the output, resolved here off the
 *      registry's discovery record. The class-string is mapped to its TypeScript type path
 *      (`App\Data\Library\LyricPieceProjectData` → `App.Data.Library.LyricPieceProjectData`), matching how
 *      `spatie/laravel-typescript-transformer` mirrors the PHP sub-namespace under `App.Data`.
 *   4. INDEX is `returnsMany`; SHOW is single. Write verbs (store/update/destroy) get a route-map entry
 *      only (no `returns`) — their hooks are mutations keyed by the same route name, and the mutation's
 *      return type rides the resource's read DTO so a write still lands typed.
 *
 * The prefix (`resources/`) is READ, never hardcoded — renaming the mount can't drift the client.
 */
class ParticleRouteManifestSource implements RouteManifestSource
{
    public function __construct(
        private readonly Router $router,
        private readonly ParticleResourceRegistry $registry,
    ) {}

    public function toArray(): array
    {
        $manifest = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! $this->isParticleRoute($route)) {
                continue;
            }

            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            $entry = [
                'path' => ltrim($route->uri(), '/'),
                'methods' => $this->readMethods($route),
            ];

            $returns = $this->returnsFor($route);

            if ($returns !== null) {
                $entry['returns'] = $returns;

                // INDEX yields the collection; SHOW yields one row.
                if ($this->verbOf($name) === 'index') {
                    $entry['returnsMany'] = true;
                }
            }

            $manifest[$name] = $entry;
        }

        ksort($manifest);

        return $manifest;
    }

    /** A route belongs to us when its action targets the generic {@see ParticleController}. */
    private function isParticleRoute(Route $route): bool
    {
        $action = $route->getAction('controller');

        return is_string($action) && str_starts_with($action, ParticleController::class.'@');
    }

    /** The HTTP verbs Laravel exposes, minus the framework-auto `HEAD`. */
    private function readMethods(Route $route): array
    {
        return array_values(array_filter($route->methods(), fn (string $m) => $m !== 'HEAD'));
    }

    /** The trailing verb of a `{resourceKey}.{verb}` route name (e.g. `library-lyrics.index` → `index`). */
    private function verbOf(string $name): string
    {
        $pos = strrpos($name, '.');

        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /**
     * The response Data class for a particle route, as a TypeScript type path — or null when the resource
     * key isn't registered / its output class can't be resolved (degrade to a route-map entry only, never
     * fabricate a type). EVERY CRUD verb yields the resource's output DTO: index is a collection of it (see
     * caller's `returnsMany`), show/store/update/destroy each return one row of it — so a write renders as a
     * typed mutation, not a route-map-only line. All five verbs of one resource share the one read Data
     * class (the ParticleController hydrates the written row back through the same projection).
     */
    private function returnsFor(Route $route): ?string
    {
        $key = $route->defaults[ParticleController::RESOURCE] ?? null;

        if (! is_string($key) || ! $this->registry->has($key)) {
            return null;
        }

        // The runtime resource's `data` is ALWAYS populated: the discovery pass sets it to the annotated
        // projection class when the attribute leaves `data` null (the single-class default), so this is the
        // resource's output DTO regardless of whether it was declared explicitly.
        $dataClass = $this->registry->get($key)->data;

        return $dataClass === null ? null : $this->toTsType($dataClass);
    }

    /**
     * Map a fully-qualified PHP Data class to its TypeScript type path, matching
     * `spatie/laravel-typescript-transformer`: it mirrors the sub-namespace under `App\Data` into
     * `App.Data.*` (`App\Data\Library\LyricPieceProjectData` → `App.Data.Library.LyricPieceProjectData`).
     */
    private function toTsType(string $class): string
    {
        return str_replace('\\', '.', ltrim($class, '\\'));
    }
}
