<?php

namespace Splicewire\Beam\Source;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteMetadataReader;
use Splicewire\Beam\Routing\RouteRequestType;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Routing\RouteVisibility;

/**
 * The **particle-route source** — the satellite-side {@see RouteManifestSource}. Where the platform binds a
 * Tower-backed source (its `Tenant`/`AdminRouteManifest`), a satellite has no Tower: it mounts particle
 * routes directly (`Route::particleResource($uri, $key, only:)` from a host provider). This source reads
 * those MOUNTED routes off the live route table and reconstructs the enriched manifest the generator wants.
 * Named controller routes also enter when they explicitly declare an operationId and a success response
 * or stream through the doctrine's attributes. See docs/client-route-manifest.md for that publication contract.
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
 *      (`Splicewire\Tower\Data\LyricPieceProjectData` → `Splicewire.Tower.Data.LyricPieceProjectData`) —
 *      the class's REAL native namespace, dots for backslashes. There is no `App.Data` rebasing; the
 *      remap was removed (surgeon-audit-viability ticket 34) precisely because it had to be hand-kept
 *      in sync with a prefix list. Native FQNs are globally unique, so the mapping is collision-proof.
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
    private RouteReturnType $returnTypes;

    private RouteRequestType $requestTypes;

    public function __construct(
        private Router $router,
        private ParticleResourceRegistry $registry,
        private ParticleOperationRegistry $operations,
        private RouteMetadataReader $meta,
        ?RouteReturnType $returnTypes = null,
        ?RouteRequestType $requestTypes = null,
    ) {
        $this->returnTypes = $returnTypes ?? new RouteReturnType($registry, $operations, $meta);
        $this->requestTypes = $requestTypes ?? new RouteRequestType;
    }

    public function toArray(): array
    {
        $manifest = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! $this->isParticleRoute($route)) {
                if (($name = $route->getName()) !== null && ($entry = $this->declaredControllerEntry($route)) !== null) {
                    $manifest[$name] = $entry;
                }

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
            } elseif ($urlFormats = $this->urlFormatsFor($route)) {
                // A document-delivery route: its operation declares a `?format` axis (`delivery:` → `formats()`),
                // so there is no typed hook but a typed URL builder. The DECLARED formats ARE the marker —
                // declared, not detected (pe08 G9). Absent here falls through to the recorded-absence branch.
                $entry['urlFormats'] = $urlFormats;
            } else {
                // RECORD the absence. Degrading to a route-map-only entry is correct — never fabricate a type
                // — but a silent degradation is indistinguishable from a verb that genuinely returns nothing,
                // which is precisely what makes undeclared surface invisible to the detector.
                $entry['unresolved'] = true;
            }

            // tutorial-dx 03: a write route's declared input, three-state as declared — a Data class (the mutation's
            // `body` type), `false` (it sends none), or no key when undeclared (the body stays `unknown`).
            $input = $this->inputFor($route, $name);

            if ($input !== null) {
                $entry['input'] = $input === false ? false : $this->toTsType($input);
            }

            // agreements 05: the Agreement KEYS this route's behavior requires, per the port. The key is omitted —
            // never an empty list — when it carries none, so a host with no Requirement reads a byte-identical manifest.
            if (($requires = $this->requiresFor($route)) !== []) {
                $entry['requires'] = $requires;
            }

            $manifest[$name] = $entry;
        }

        ksort($manifest);

        return $manifest;
    }

    /**
     * A named controller API opts in through its existing explicit operationId and the doctrine's
     * response/stream declaration site. Neither a controller's name nor its URL implies publication.
     *
     * @return array{path: string, methods: list<string>, returns?: string, returnsMany?: bool, returnsBody?: true, streams?: array<string, list<string>>, input?: string}|null
     */
    private function declaredControllerEntry(Route $route): ?array
    {
        if ($route->getName() === '' || $this->meta->visibility($route) === RouteVisibility::Deprecated) {
            return null;
        }

        $operationId = $this->meta->operationId($route);

        if ($operationId === null || trim($operationId) === '') {
            return null;
        }

        $responseClass = $this->returnTypes->fromResponseAttribute($route);

        if ($responseClass === null && $this->returnTypes->fromStreamsAttribute($route) === []) {
            return null;
        }

        $entry = ['path' => ltrim($route->uri(), '/'), 'methods' => $this->readMethods($route)];
        // The trio declares the delivery kind. A resource association or bare return override must
        // not turn a method-declared event stream into a resource-row response.
        $response = $responseClass === null ? null : $this->returnTypes->for($route);

        if ($response !== null) {
            $entry['returns'] = $response['type'];
            $entry['returnsMany'] = $response['many'];
            if (! empty($response['returnsBody'])) {
                $entry['returnsBody'] = true;
            }
        } else {
            $entry['streams'] = array_map(
                fn (array $classes) => array_map($this->toTsType(...), $classes),
                $this->returnTypes->streamsFor($route) ?? [],
            );
        }

        if (($input = $this->requestTypes->fromRequestAttribute($route)) !== null) {
            $entry['input'] = $this->toTsType($input);
        }

        return $entry;
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
        // Deprecated routes stay outside the published client surface, independently of their owner.
        if ($this->meta->visibility($route) === RouteVisibility::Deprecated) {
            return false;
        }

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

    /**
     * The HTTP verbs Laravel exposes, minus the framework-auto `HEAD`.
     *
     * @return list<string>
     */
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
     * The declared `?format` axis of a document-delivery operation (its `delivery:` contributes `formats()`),
     * keyed off the operation controller's route defaults exactly as {@see operationOutputFor()}. A non-empty
     * list marks the route for a typed URL builder (`<domain><Action>Url(params?, format?)`) rather than a hook;
     * an empty list is not a marker. Declared, not detected (pe08 G9).
     *
     * @return list<string>
     */
    private function urlFormatsFor(Route $route): array
    {
        $resource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $name = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (! is_string($resource) || ! is_string($name)) {
            return [];
        }

        return $this->operations->find($resource, $name)?->formats() ?? [];
    }

    /**
     * The input a route accepts (tutorial-dx 03): an OPERATION's own `input:` slot — on whatever method it
     * mounts — or for a resource's `store`/`update` the resource's `input:` (both go through
     * {@see ParticleController::parseInput()}). Null for a resource read/destroy, an unregistered
     * declaration, or an undeclared input.
     *
     * ⚠️ An operation carries its declared input REGARDLESS of HTTP method (graph-operations 02 review B1).
     * A GET operation's input is its query/params type, not a request body — the declaration says WHAT is
     * accepted and the mount said WHERE (the axis). The generated client renders it accordingly: the read
     * branch of {@see \Splicewire\Beam\Codegen\TsClientGenerator::renderHook()} types a read op's `input`
     * and serializes it INTO THE URL as a query string — `api.get(route(name, params) + beamQuery(input))`
     * — because the host contract's `api.get(url)` takes one argument and `route()` appends no query (r3).
     * A read with no input class stays byte-identical; the write branch sends input as the body. So a GET
     * op's `input:` reaches the client typed, which a read-anchored operation (the four graph reads) needs.
     * A resource's own `store`/`update` stays method-gated below, so `index`/`show`/`destroy` carry no input.
     *
     * @return class-string|false|null
     */
    private function inputFor(Route $route, string $name): string|false|null
    {
        $resource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $operation = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (is_string($resource) && is_string($operation)) {
            return $this->operations->find($resource, $operation)?->input;
        }

        if (! array_intersect($this->readMethods($route), ['POST', 'PUT', 'PATCH'])) {
            return null;
        }

        $key = $route->defaults[ParticleController::RESOURCE] ?? null;

        if (! is_string($key) || ! in_array($this->verbOf($name), ['store', 'update'], true) || ! $this->registry->has($key)) {
            return null;
        }

        return $this->registry->get($key)->input;
    }

    /**
     * The distinct Agreement keys the route's behavior requires (agreements 05), read through the
     * {@see RequirementDeclarations} port: an operation's `{resource}.{name}`, a resource's `index`/`show` as
     * `{key}:read` and `store`/`update`/`destroy` as `{key}:write`. Keys only — never a class name.
     *
     * @return list<string>
     */
    private function requiresFor(Route $route): array
    {
        if (! app()->bound(RequirementDeclarations::class)) {
            return [];
        }

        $behavior = $this->behaviorOf($route);

        if ($behavior === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (Requires $requirement): ?string => $requirement->agreement,
            app(RequirementDeclarations::class)->requirementsFor($behavior),
        ))));
    }

    private function behaviorOf(Route $route): ?string
    {
        $resource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $name = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (is_string($resource) && is_string($name)) {
            return "{$resource}.{$name}";
        }

        $key = $route->defaults[ParticleController::RESOURCE] ?? null;

        if (! is_string($key)) {
            return null;
        }

        return match ($route->getActionMethod()) {
            'index', 'show' => ParticleResource::behaviorFor($key, 'read'),
            'store', 'update', 'destroy' => ParticleResource::behaviorFor($key, 'write'),
            default => null,
        };
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
     * `spatie/laravel-typescript-transformer` as this estate configures it: the class's REAL native
     * namespace with dots for backslashes (`Splicewire\Tower\Data\LyricPieceProjectData` →
     * `Splicewire.Tower.Data.LyricPieceProjectData`). No `App.Data` rebasing — see the class docblock.
     */
    private function toTsType(string $class): string
    {
        return str_replace('\\', '.', ltrim($class, '\\'));
    }
}
