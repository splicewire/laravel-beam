<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Routing\Route;
use ReflectionMethod;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Resolves the client-facing return type for one route (client-sdk-codegen tickets 03–04) — the single
 * seam both route manifests call so the rule lives in one place.
 *
 * Four sources, override-ordered (particle-doctrine-convergence ticket 04):
 *   1. an explicit `Route::...->beam()->returns(DataClass::class)` (ticket 03) — always wins;
 *   2. a `#[ResponseFromData]` on the controller METHOD — the same attribute the OpenAPI pipeline already
 *      reads through its composing Scribe strategies (ADR-0181). Reusing it is the whole point: the
 *      attribute path was already live on the OpenAPI half while this TypeScript half stayed macro-only,
 *      and that asymmetry IS the drift — one endpoint could describe itself two ways and disagree. It also
 *      unblocks package-side declaration. (When this was written the `returns` macro was defined host-side,
 *      so two of the three target repos had no way to declare a response shape at all; api-surface-coherence
 *      ticket 15 moved it into beam core as `->beam()->returns()`, which closes that gap from the other
 *      side. The attribute path still matters — it declares on the METHOD, reachable without a route.);
 *   3. a particle OPERATION's declared `output:` slot, keyed off the operation controller's resource/name
 *      route defaults — mirroring how (4) keys off the resource controller's. An operation gets its type for
 *      free, the way a CRUD resource already does;
 *   4. a particle RESOURCE's declared output DTO (ticket 04) — its `data` class. A resource with only a
 *      `project` projector is NOT auto-derived: the projector's return may be a package-owned wire type, so
 *      that call stays explicit (`->beam()->returns()`) or hand-written.
 *
 * Precedence preserves the existing "explicit beats derived" invariant (ADR-0181) and keeps the OpenAPI and
 * TypeScript consumers in lockstep — both now read the same declaration rather than each reading its own.
 *
 * Returns the TS-qualified name (via {@see ClientTypeName}) or null when nothing is declared (the route
 * still gets a route-map entry; its hooks stay hand-written).
 *
 * Descended from `splicewire/tower` by api-surface-coherence 24, completing the move ticket 15 started:
 * 15 brought the `returns`/`visibility`/`streams` macros and `RouteVisibility` down and deliberately left
 * this reader behind. Once the declaration it reads is a beam macro and every source it consults is a beam
 * class, staying above beam bought nothing — and kept `ReturnsResponseStrategy` anchored to tower for one
 * import. `Splicewire\Tower\Routing\RouteManifest` still consumes it, importing DOWN, which is the direction
 * the estate already runs.
 *
 * {@see streamsFor()} is the same seam for STREAMING routes (particle-doctrine-convergence ticket 05). A
 * stream has no single response type — it emits discrete typed events under distinct wire names — so it
 * deliberately resolves nothing from `for()` and instead yields an `event name → payload variants` map from
 * three sources in the same override order: an explicit `->beam()->streams([...])`, a `#[StreamsFromData]` on the
 * controller method, then a Stream operation's declared `output:` map.
 */
class RouteReturnType
{
    /**
     * The attribute-borne declarations of one action name (`Class@method`), resolved once. A manifest build
     * iterates the whole router and revisits the same action across contracts; reflection is the expensive
     * step, so it happens once per action and yields BOTH declarations in one pass — a route is either a
     * response or a stream, but which one is not knowable before reflecting. `response` is null for "checked,
     * nothing declared"; `streams` is `[]` for the same.
     *
     * @var array<string, array{response: class-string|null, streams: array<string, list<class-string>>}>
     */
    private array $declarations = [];

    private int $reflectionCount = 0;

    public function __construct(
        private ParticleResourceRegistry $particles,
        private ParticleOperationRegistry $operations,
    ) {}

    /**
     * @return array{type: string, many: bool}|null the TS type + whether the endpoint returns a list
     */
    public function for(Route $route): ?array
    {
        // (1) Explicit annotation wins; its cardinality is declared (`->beam()->returns(X::class, many: true)`).
        if ($explicit = BeamRouteAction::returns($route)) {
            return [
                'type' => ClientTypeName::for($explicit),
                'many' => BeamRouteAction::returnsMany($route),
            ];
        }

        // (2) The method attribute. It declares a body shape, not a cardinality, so `many` is false — a
        // collection response still declares its item type and says so explicitly via the macro.
        if ($declared = $this->fromResponseAttribute($route)) {
            return ['type' => ClientTypeName::for($declared), 'many' => false];
        }

        // (3) A particle operation's declared output slot.
        if ($fromOperation = $this->fromOperation($route)) {
            return $fromOperation;
        }

        // (4) Particle-derived: the `particleResource` macro stashes the resource key as a route default.
        return $this->fromResource($route);
    }

    /**
     * The event-name → payload-variants map for a STREAMING route, TS-qualified, or null when the route
     * declares no stream (which is every non-streaming route).
     *
     * Same three-source override order as {@see for()}: an explicit `->beam()->streams([...])` route macro, then a
     * `#[StreamsFromData]` on the controller method, then a Stream particle operation's `output:` slot —
     * explicit beats derived, exactly as beam's satellite-side source already resolves it.
     *
     * One event name may carry several payload variants (a discriminated union narrowed by a DTO field, e.g.
     * `node_status` covering running/complete/failed/skipped/needs_review), which is why every value is a
     * list even when it holds one class.
     *
     * @return array<string, list<string>>|null
     */
    public function streamsFor(Route $route): ?array
    {
        // (1) The explicit route macro wins.
        if (($declared = BeamRouteAction::streams($route)) !== []) {
            return $this->qualify($declared);
        }

        // (2) The method attribute — the declaration site that needs no route at all.
        if (($declared = $this->fromStreamsAttribute($route)) !== []) {
            return $this->qualify($declared);
        }

        // (3) Derived from a Stream operation's declared `output:`, which IS an event-name map (ticket 01
        // rejects a bare class-string there, so an array here is always the event map).
        $output = $this->operationOutput($route);

        return is_array($output) && $output !== [] ? $this->qualify($output) : null;
    }

    /** How many actions this instance has reflected — diagnostic, so the per-build cache is assertable. */
    public function reflectionCount(): int
    {
        return $this->reflectionCount;
    }

    /**
     * The response Data class declared by `#[ResponseFromData]` on the route's controller method.
     *
     * @return class-string|null
     */
    private function fromResponseAttribute(Route $route): ?string
    {
        return $this->declarationsFor($route)['response'];
    }

    /**
     * The event map declared by `#[StreamsFromData]` on the route's controller method.
     *
     * @return array<string, list<class-string>>
     */
    private function fromStreamsAttribute(Route $route): array
    {
        return $this->declarationsFor($route)['streams'];
    }

    /**
     * @return array{response: class-string|null, streams: array<string, list<class-string>>}
     */
    private function declarationsFor(Route $route): array
    {
        $action = $route->getAction('controller');

        if (! is_string($action)) {
            return ['response' => null, 'streams' => []];
        }

        return $this->declarations[$action] ??= $this->reflectDeclarations($action);
    }

    /**
     * Reflect one `Class@method` action for its response and stream declarations. Anything without a
     * resolvable class-and-method — a closure, an invokable single-action controller, a stale action naming a
     * class or method that no longer exists — yields the empty record rather than raising: a manifest build
     * walks the whole live route table and must not die on one unreflectable entry.
     *
     * `#[ResponseFromData]` is repeatable per status code, so the SUCCESS shape is selected — the lowest 2xx.
     * An error-body declaration (a 4xx/5xx envelope) must never become the client's response type.
     *
     * `#[StreamsFromData]` is repeatable per event name, and repeating ONE name appends variants rather than
     * replacing them: `['node_status' => [A]]` plus `['node_status' => [B]]` is a two-variant union, not B.
     * That makes the attribute spelling and the operation's map spelling interchangeable.
     *
     * @return array{response: class-string|null, streams: array<string, list<class-string>>}
     */
    private function reflectDeclarations(string $action): array
    {
        $this->reflectionCount++;

        $empty = ['response' => null, 'streams' => []];

        if (! str_contains($action, '@')) {
            return $empty;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return $empty;
        }

        $reflection = new ReflectionMethod($class, $method);
        $best = null;

        foreach ($reflection->getAttributes(ResponseFromData::class) as $attribute) {
            $declared = $attribute->newInstance();

            if ($declared->status < 200 || $declared->status > 299) {
                continue;
            }

            if ($best === null || $declared->status < $best->status) {
                $best = $declared;
            }
        }

        $streams = [];

        foreach ($reflection->getAttributes(StreamsFromData::class) as $attribute) {
            $declared = $attribute->newInstance();

            foreach ($declared->dataClasses as $dataClass) {
                if (! in_array($dataClass, $streams[$declared->event] ?? [], true)) {
                    $streams[$declared->event][] = $dataClass;
                }
            }
        }

        return ['response' => $best?->dataClass, 'streams' => $streams];
    }

    /**
     * Map an event-name → class-list declaration onto its TS-qualified twin, the one shape the manifests
     * emit under `streams`.
     *
     * @param  array<string, list<class-string>>  $events
     * @return array<string, list<string>>
     */
    private function qualify(array $events): array
    {
        return array_map(
            fn (array $classes) => array_values(array_map(ClientTypeName::for(...), $classes)),
            $events
        );
    }

    /**
     * The type declared by the particle OPERATION this route mounts, keyed off the operation controller's
     * resource + name route defaults.
     *
     * A Stream operation resolves nothing here on purpose: its `output:` is an event-name map, so it has no
     * single response type — the manifest carries that shape under `streams`, not `returns`.
     *
     * @return array{type: string, many: bool}|null
     */
    private function fromOperation(Route $route): ?array
    {
        $output = $this->operationOutput($route);

        if (! is_string($output)) {
            return null;
        }

        return ['type' => ClientTypeName::for($output), 'many' => false];
    }

    /**
     * The raw `output:` slot of the particle operation this route mounts — a class-string for the three
     * resolved kinds, an event-name map for a Stream, null when the route is not an operation route or its
     * operation was never registered (a reportable absence for the negative-space detector, not an exception).
     *
     * @return class-string|array<string, list<class-string>>|null
     */
    private function operationOutput(Route $route): string|array|null
    {
        $resource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $name = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (! is_string($resource) || ! is_string($name)) {
            return null;
        }

        return $this->operations->find($resource, $name)?->output;
    }

    /**
     * The type declared by the particle RESOURCE this route mounts.
     *
     * @return array{type: string, many: bool}|null
     */
    private function fromResource(Route $route): ?array
    {
        $key = $route->defaults[ParticleController::RESOURCE] ?? null;

        if (! is_string($key) || ! $this->particles->has($key)) {
            return null;
        }

        $class = $this->particles->get($key)->data;

        if (! $class) {
            return null;
        }

        // A particle `index` route returns a paginated list of the DTO; `show`/`store`/`update` return one.
        return [
            'type' => ClientTypeName::for($class),
            'many' => str_ends_with((string) $route->getName(), '.index'),
        ];
    }
}
