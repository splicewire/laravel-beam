<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Rushing\DataFilters\Facades\DataFilter;
use Splicewire\Beam\Http\Particle\ParticleController;

/**
 * The `->beam()` route-metadata namespace (api-surface-coherence ticket 15).
 *
 * Every beam-surface declaration a route can carry hangs off ONE method, so the four macros that used
 * to sit bare on {@see Route} — `returns`, `visibility`, `streams`, and ticket 01's `inResource` — stop
 * competing for the global macro namespace. That competition was the whole reason for the move:
 * `Macroable::macro()` **silently overwrites**, so any package registering a `returns` macro would win
 * without erroring. One `beam` macro is one collision surface instead of four, and
 * {@see BeamServiceProvider::registerBeamRouteNamespace()} guards even that one loudly.
 *
 * Unknown calls forward straight back to the underlying route and return whatever IT returns, so the
 * proxy never has to mirror Laravel's route API and a chain leaves the namespace naturally:
 *
 *     Route::get('circuits/{id}/export', …)
 *         ->beam()->inResource('circuits')->returns(ExportData::class)
 *         ->name('circuits.export')   // ← forwarded; returns the Route, not the proxy
 *
 * Re-entering `->beam()` per declaration was rejected in ticket 15: it produces worse route files than
 * the bare macros it replaces.
 *
 * Read the values back through {@see BeamRouteAction}, never by string — the action keys live under a
 * single `beam` sub-array precisely so no reader has to know their spelling.
 */
class BeamRouteProxy
{
    /**
     * The route-action key every beam declaration nests under. One key, so a `dd($route->getAction())`
     * shows the whole beam surface in one place and nothing beam owns can collide with a framework or
     * host action key ever again.
     */
    public const ACTION = 'beam';

    protected Route $route;

    public function __construct(Route $route)
    {
        $this->route = $route;
    }

    /**
     * Records a bespoke endpoint's response Data class (client-sdk-codegen ticket 03). It is the one
     * residual signal codegen can't infer: particle-backed routes auto-derive their return DTO, but a
     * hand-rolled action's return type has to be declared once, next to the path.
     *
     * `many: true` makes codegen emit `App.Data.X[]` — the envelope's `data` is an array of the DTO.
     */
    public function returns(string $dataClass, bool $many = false): static
    {
        $this->set('returns', $dataClass);

        if ($many) {
            $this->set('returnsMany', true);
        }

        return $this;
    }

    /**
     * Declares a route's exposure tier as manifest metadata. Undeclared routes default to `internal`
     * (opt-in publicness) — the default is applied on the read side, not written here.
     */
    public function visibility(RouteVisibility $tier): static
    {
        $this->set('visibility', $tier);

        return $this;
    }

    /**
     * Declares an SSE route's possible event-frame DTOs, KEYED BY the SSE `event:` name — a stream emits
     * a sequence of discrete typed events under distinct wire names, not one resolved payload, and a
     * single event name may cover several branches (discriminated by a DTO field like `status`), so it
     * needs a map rather than a flat list.
     *
     * A route carries `returns` or `streams`, never both; codegen branches on which is present to choose
     * between a query/mutation hook and a stream-shaped one.
     *
     * @param  array<string, list<class-string>>  $eventDataClassesByName
     */
    public function streams(array $eventDataClassesByName): static
    {
        $this->set('streams', $eventDataClassesByName);

        return $this;
    }

    /**
     * Declares which registered resource this route is a sub-operation OF (ticket 01) — the stamp the
     * group-resolution chain reads so a route's documentation group is a property of its RESOURCE and
     * never a guess parsed from its URI.
     *
     * This deliberately writes the SAME route default `Route::particleResource()` already stamps
     * ({@see ParticleController::RESOURCE}) rather than a parallel action key. There is one stamp, so
     * ticket 17's resolver has one place to look — a hand-rolled route declaring `inResource('circuits')`
     * becomes indistinguishable, to every reader, from a route the particle macro mounted.
     *
     * It is the one beam declaration that is NOT an action key, which is why it does not go through
     * `set()`. Defaults are visible to route-parameter binding; the leading underscore is the framework's
     * convention for "not a parameter" and is why the particle stamp was spelled that way to begin with.
     */
    public function inResource(string $resourceKey, bool $filters = false): static
    {
        $this->route->defaults(ParticleController::RESOURCE, $resourceKey);

        if ($filters) {
            $this->mountFilterSubSurface($resourceKey);
        }

        return $this;
    }

    /**
     * `filters: true` says this route is the resource's INDEX at this exposure, and mounts the
     * per-resource filter sub-surface beside it (api-surface-coherence 35).
     *
     * A route the particle macro mounted needs no such flag — `Route::particleResource()` calls
     * `Route::resourceFilters()` itself, so the sub-surface follows the resource to every particle
     * exposure with nothing declared. A HAND-ROLLED exposure has no equivalent hook, because there is no
     * single moment a hand-rolled resource "is mounted"; `inResource()` is the one line ticket 01 already
     * put at that moment, so the declaration rides it rather than inventing a second one.
     *
     * It is a flag and not an inference on purpose. `inResource()` is stamped on sub-operations too
     * (`fragments/{fragment}/concept-anchors/reject-all` declares `inResource('fragments')`), and every
     * rule for telling an index from a sub-operation by inspecting its URI — no `{id}`, GET, one
     * segment — is guessable-from-the-URI reasoning, which is the exact thing ticket 01 abolished.
     *
     * The remaining gap is on the record rather than papered over: this still asks the exposure to say
     * one word. Making a hand-rolled exposure fully self-declaring is the map's own open fog —
     * *declared exposures, and the tree mount* (ticket 18 §D4, ticket 49's `Particle::mount`) — and the
     * filter sub-surface should ride that when it lands, not grow a second mechanism ahead of it.
     */
    private function mountFilterSubSurface(string $resourceKey): void
    {
        if (! DataFilter::registry()->has($resourceKey)) {
            return;
        }

        // The route's own URI, minus any group prefix the router will re-apply — `uri()` is already
        // prefix-resolved, so mounting from inside the same group would double it. Registering through
        // the Router with the group stack intact and handing it the ORIGINAL uri keeps one source of
        // truth for the mount point.
        RouteFacade::resourceFilters(
            resource: $resourceKey,
            at: $this->uriWithinCurrentGroup(),
            names: $this->nameWithinCurrentGroup($resourceKey),
        );
    }

    /**
     * The declared (pre-prefix) URI of the decorated route.
     *
     * `Route::uri()` returns the FULL path with every enclosing group prefix already folded in, but the
     * sub-surface is registered from inside those same groups, so handing the full path back would
     * produce `api/v1/api/v1/…`. The router's current group prefix is exactly what has to come off.
     */
    private function uriWithinCurrentGroup(): string
    {
        $uri = trim($this->route->uri(), '/');
        $prefix = trim((string) (RouteFacade::getLastGroupPrefix() ?? ''), '/');

        if ($prefix === '') {
            return $uri;
        }

        // A group whose prefix IS the whole route — `Route::get('/')` inside
        // `TierRoutes::mount('splice/compositions', …)`. The sub-surface mounts at the group root, so
        // the empty string is the answer, not the full path. Getting this wrong yields
        // `api/v1/splice/compositions/api/v1/splice/compositions/filters`, which route:list will happily
        // show you.
        if ($uri === $prefix) {
            return '';
        }

        if (str_starts_with($uri, $prefix.'/')) {
            return substr($uri, strlen($prefix) + 1);
        }

        return $uri;
    }

    /**
     * The declared (pre-group) route-NAME stem to hang the sub-surface's names off.
     *
     * The exact twin of {@see uriWithinCurrentGroup()}, and it exists for the same reason: a route
     * declared inside `TierRoutes::mount('splice/compositions', 'splice.compositions.', …)` reports
     * `getName()` as `splice.compositions.index`, with the group's `as` already folded in — so handing
     * that straight back produced `splice.compositions.splice.compositions.filters.index`. Measured on
     * two exposures (compositions and API tokens) before it was caught.
     *
     * The trailing `.index` comes off too: this is the stem the sub-surface appends `.filters.<verb>` to.
     *
     * Two empty-stem cases, and they want opposite answers. Inside a NAMED group whose route is the
     * group root (`Route::get('/')` under `TierRoutes::mount(…, 'splice.compositions.', …)`) the group
     * already names the surface, so the stem is empty and the macro emits a bare `filters.<verb>` that
     * the group prefixes — `splice.compositions.filters.index`. Outside one, on a route with no name at
     * all (the fragments index is exactly that), an empty stem would mint a global `filters.index` that
     * the next nameless exposure would silently overwrite, so the RESOURCE KEY stands in.
     */
    private function nameWithinCurrentGroup(string $resourceKey): string
    {
        $name = (string) $this->route->getName();
        $stack = RouteFacade::getGroupStack();
        $groupName = $stack === [] ? '' : (string) (end($stack)['as'] ?? '');

        if ($groupName !== '' && str_starts_with($name, $groupName)) {
            $name = substr($name, strlen($groupName));
        }

        $name = (string) preg_replace('/\.?index$/', '', $name);

        if ($name !== '') {
            return $name;
        }

        return $groupName !== '' ? '' : $resourceKey;
    }

    /**
     * Forwards anything the namespace doesn't own back to the route, returning the route's own result —
     * so `->name()`, `->middleware()`, `->where()` behave exactly as they do off a bare route and a chain
     * exits the namespace without ceremony.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->route->{$method}(...$arguments);
    }

    /** The route being decorated — for callers that need to step out of the namespace explicitly. */
    public function toRoute(): Route
    {
        return $this->route;
    }

    protected function set(string $key, mixed $value): void
    {
        $this->route->action[static::ACTION][$key] = $value;
    }
}
