<?php

namespace Splicewire\Beam\Concerns;

use Closure;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * The `Route::particleResource()` / `particleOp()` / `particleOps()` / `particleRelative()` macros — the
 * declarative mount for the generic particle REST + operation surface, so a host stops hand-mounting the
 * generic controllers against the RESOURCE/NAME route defaults.
 *
 * A link in {@see BeamServiceProvider}'s `boot` chain rather than a line in its `packageBooted()`.
 *
 * ⚠️ It declares `order: 10` — FIRST — rather than relying on where its `use` statement happens to sit.
 * `pint`'s Laravel preset includes the `ordered_traits` fixer, which sorts a class's `use` statements
 * alphabetically; a mount sequence resting on that would be resequenced by a formatter on an unrelated
 * commit with nothing failing. See {@see Chained} for the measurement behind that rule.
 */
trait BootsParticleRouteMacros
{
    #[Chained('boot', order: 10)]
    protected function bootParticleRouteMacros(): void
    {
        if (Route::hasMacro('particleResource')) {
            return;
        }

        Route::macro('particleResource', function (
            string $uri,
            string $resourceKey,
            array $options = [],
        ): void {
            /** @var Router $this */
            $only = $options['only'] ?? ['index', 'show', 'store', 'update', 'destroy'];
            $name = $options['names'] ?? str_replace('-', '_', $resourceKey);
            $idConstraint = $options['idConstraint'] ?? null;
            // 'controller' — route THROUGH a dedicated ParticleController subclass (e.g. SiloController) so it
            // gets the `_particle` default + auto-`@group` like the generic surface, instead of hand-rolled
            // explicit routes. Defaults to the generic controller (fully backward-compatible).
            $controller = $options['controller'] ?? ParticleController::class;

            $withId = function (RouteInstance $route) use ($idConstraint): RouteInstance {
                return $idConstraint === 'uuid' ? $route->whereUuid('id') : $route;
            };

            $stamp = function (RouteInstance $route, string $verb) use ($resourceKey, $name): RouteInstance {
                return $route
                    ->defaults(ParticleController::RESOURCE, $resourceKey)
                    ->name("{$name}.{$verb}");
            };

            if (in_array('index', $only, true)) {
                $stamp($this->get($uri, [$controller, 'index']), 'index');
            }

            if (in_array('show', $only, true)) {
                $stamp($withId($this->get("{$uri}/{id}", [$controller, 'show'])), 'show');
            }

            if (in_array('store', $only, true)) {
                $stamp($this->post($uri, [$controller, 'store']), 'store');
            }

            if (in_array('update', $only, true)) {
                $verbs = ($options['legacyPostUpdate'] ?? false) ? ['put', 'patch', 'post'] : ['put', 'patch'];
                $stamp($withId($this->match($verbs, "{$uri}/{id}", [$controller, 'update'])), 'update');
            }

            if (in_array('destroy', $only, true)) {
                $stamp($withId($this->delete("{$uri}/{id}", [$controller, 'destroy'])), 'destroy');
            }
        });

        Route::macro('particleOp', function (
            string $uri,
            string $resourceKey,
            string $op,
            array $options = [],
        ): void {
            /** @var Router $this */
            $verb = strtolower($options['method'] ?? 'post');

            $route = $this->{$verb}("{$uri}/{id}/op/{$op}", [ParticleOperationController::class, 'invoke'])
                ->defaults(ParticleOperationController::RESOURCE, $resourceKey)
                ->defaults(ParticleOperationController::NAME, $op)
                ->name($options['name'] ?? "{$resourceKey}.op.{$op}");

            if (($options['idConstraint'] ?? null) === 'uuid') {
                $route->whereUuid('id');
            }

            // A Stream-kind op (ADR-0160) has no single resolved response — it emits a sequence of
            // typed SSE events instead. `particleOp` mounts through the generic controller, so there's
            // no per-route call site to chain `->streams()` onto directly; an `'streams'` option lets
            // the caller declare it here (surgeon-audit-viability ticket 28).
            if ($options['streams'] ?? null) {
                $route->streams($options['streams']);
            }
        });

        // `Route::particleOps` (HTTP-02) — the plural loop-collapse sibling of `particleOp`. Takes a LIST of
        // op declarations and mounts each; the `group()` (middleware/prefix) stays the CALLER's. Each entry is
        // one of three forms, the op NAME derived from the declaration (you pass the list, not restated names):
        //   'reorder'                          a bare name — already registered elsewhere, mount only.
        //   DownloadMedia::class               a #[ParticleOp] class-string — discovered (register) + mounted.
        //   new ParticleOperation(name: …, …)  an inline object — registered here + mounted (today's 3 sites).
        Route::macro('particleOps', function (
            string $uri,
            string $resourceKey,
            array $ops,
            array $options = [],
        ): void {
            /** @var Router $this */
            $discovery = app(AttributedParticleDiscovery::class);
            $operations = app(ParticleOperationRegistry::class);

            foreach ($ops as $op) {
                $name = match (true) {
                    // An inline runtime object — register it, mount by its own name.
                    $op instanceof ParticleOperation => tap($op->name, fn () => $operations->register($op)),
                    // A #[ParticleOp] class-string — discover (registers) + read the attribute's name to mount.
                    is_string($op) && class_exists($op) => tap(
                        (new \ReflectionClass($op))->getAttributes(ParticleOp::class)[0]?->newInstance()->name
                            ?? throw new \InvalidArgumentException("Class [{$op}] carries no #[ParticleOp] to mount as a particle op."),
                        fn () => $discovery->registerClass($op),
                    ),
                    // A bare name — already registered elsewhere; mount only.
                    default => $op,
                };

                Route::particleOp($uri, $resourceKey, $name, $options);
            }
        });

        // `Route::particleRelative` (HTTP-02) — the bound-relative mount (the one genuinely new capability).
        // Route-model-binds a RELATIVE (the related model an operation is scoped/associated THROUGH — parent
        // is the common flavor, but hasManyThrough / pivot / an arbitrary scope are all relatives) and pushes
        // it + its `$via` into the route defaults of everything the `$routes` callback mounts, so the generic
        // ParticleController reads them (index/find base on the relative; create goes through the relation).
        //
        //   $via 'media'   (string relation) — controller scopes `$relative->media()` and AUTO-ASSOCIATES the
        //                                        inverse on create (`->media()->make()`); covers hasMany /
        //                                        hasManyThrough / belongsToMany (Eloquent handles "through").
        //   $via fn($rel, $q) => …  (Closure)  — an arbitrary scope (computed joins, polymorphic, cross-tenant).
        //                                        CANNOT auto-associate on create — pairs with the resource's
        //                                        own `prepare` hook for the FK.
        //
        // `opts['binding']` overrides the `{param}` name (default: the model's kebab basename). Authorize the
        // bound relative via a `can:` middleware on the caller's `group()` — resolved once, children inherit.
        Route::macro('particleRelative', function (
            string $uri,
            string $model,
            string|Closure $via,
            Closure $routes,
            array $options = [],
        ): void {
            /** @var Router $this */
            $binding = $options['binding'] ?? Str::kebab(class_basename($model));

            // Route-model-bind the relative (findOrFail → 404 for a stranger id), then mount the child routes
            // under the `{$uri}/{binding}` prefix, stamping each with the binding name + its via so
            // ParticleController resolves the bound instance per-request off the route parameter.
            $this->bind($binding, fn ($value) => $model::query()->findOrFail($value));

            $before = $this->getRoutes()->getRoutes();
            $beforeIds = [];
            foreach ($before as $existing) {
                $beforeIds[spl_object_id($existing)] = true;
            }

            $this->group(['prefix' => "{$uri}/{{$binding}}"], $routes);

            foreach ($this->getRoutes()->getRoutes() as $route) {
                if (! isset($beforeIds[spl_object_id($route)])) {
                    $route->defaults(ParticleController::RELATIVE, $binding);
                    $route->defaults(ParticleController::RELATIVE_MODEL, $model);
                    $route->defaults(ParticleController::VIA, $via);
                }
            }
        });
    }
}
