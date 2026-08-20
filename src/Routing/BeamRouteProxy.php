<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Routing\Route;
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
    public function inResource(string $resourceKey): static
    {
        $this->route->defaults(ParticleController::RESOURCE, $resourceKey);

        return $this;
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
