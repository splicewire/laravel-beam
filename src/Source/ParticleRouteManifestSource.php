<?php

namespace Splicewire\Beam\Source;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The **particle-route source** — the satellite-side {@see RouteManifestSource}. Where the platform binds a
 * Tower-backed source (its `Tenant`/`AdminRouteManifest`), a satellite has no Tower: it mounts particle
 * routes directly (`Route::particleResource($uri, $key, only:)` from a host provider). This source reads
 * those MOUNTED routes off the live route table and reconstructs the enriched manifest the generator wants.
 *
 * How it works:
 *   1. Walk the live route table; keep every route whose action targets {@see ParticleController} (a CRUD
 *      verb) or {@see ParticleOperationController} (a named operation) — the macros stamp their identifying
 *      route defaults on both.
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
 *   5. A route that declares NEITHER a resolvable `returns` NOR a `streams` map is stamped `unresolved`.
 *      The degradation itself is right — never fabricate a type — but recording it is what makes the
 *      absence reportable: a silent degradation looks exactly like a verb that returns nothing.
 *
 * The prefix (`resources/`) is READ, never hardcoded — renaming the mount can't drift the client.
 */
class ParticleRouteManifestSource implements RouteManifestSource
{
    public function __construct(
        private readonly Router $router,
        private readonly ParticleResourceRegistry $registry,
        private readonly ParticleOperationRegistry $operations,
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
            } elseif ($streams = $this->streamsFor($route)) {
                // A stream route resolves to a SEQUENCE of typed events keyed by SSE `event:` name, not one
                // payload — a declared shape, so it is not negative space. Same shape Tower's manifest emits.
                $entry['streams'] = array_map(
                    fn (array $classes) => array_map($this->toTsType(...), $classes),
                    $streams
                );
            } else {
                // RECORD the absence. Degrading to a route-map-only entry is correct — never fabricate a type
                // — but a silent degradation is indistinguishable from a verb that genuinely returns nothing,
                // which is precisely what makes undeclared surface invisible to the detector.
                $entry['unresolved'] = true;
            }

            $manifest[$name] = $entry;
        }

        ksort($manifest);

        return $manifest;
    }

    /**
     * A route belongs to us when its action targets the generic {@see ParticleController} (a CRUD verb) OR
     * the {@see ParticleOperationController} (a named operation).
     *
     * Operations were previously excluded outright, which is why they reached neither the manifest nor the
     * generated client: a whole declaration site was invisible to every downstream consumer.
     */
    private function isParticleRoute(Route $route): bool
    {
        $action = $route->getAction('controller');

        if (! is_string($action)) {
            return false;
        }

        foreach ([ParticleController::class, ParticleOperationController::class] as $controller) {
            if (str_starts_with($action, $controller.'@')) {
                return true;
            }
        }

        return false;
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
        // An OPERATION route types itself from its own declared `output:` slot — the invariant's second legal
        // declaration site, keyed off the operation controller's route defaults exactly as the resource branch
        // below keys off the resource controller's. A Stream's output is an event map, handled by
        // {@see streamsFor()} instead, so only a string counts here.
        if (is_string($output = $this->operationOutputFor($route))) {
            return $this->toTsType($output);
        }

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
     * The `output:` slot declared by the particle operation this route mounts, or null when the route is not
     * an operation route or its operation was never registered. A mounted-but-unregistered operation is a
     * reportable absence (it lands `unresolved`), not an error.
     *
     * @return class-string|array<string, list<class-string>>|null
     */
    private function operationOutputFor(Route $route): string|array|null
    {
        $resource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $name = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (! is_string($resource) || ! is_string($name)) {
            return null;
        }

        return $this->operations->find($resource, $name)?->output;
    }

    /**
     * A route's event-name → payload-list map, from either declaration site: an explicit `->streams()` on the
     * route (which the `particleOp` macro's `streams` option sets) or a Stream operation's own `output:` slot.
     * The route-level declaration wins, preserving "explicit beats derived".
     *
     * @return array<string, list<class-string>>|null
     */
    private function streamsFor(Route $route): ?array
    {
        if ($declared = $route->getAction('streams')) {
            return $declared;
        }

        $output = $this->operationOutputFor($route);

        return is_array($output) ? $output : null;
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
