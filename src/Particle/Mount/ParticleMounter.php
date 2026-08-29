<?php

namespace Splicewire\Beam\Particle\Mount;

use Closure;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Rushing\DataFilters\Facades\DataFilter;
use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Splicewire\Beam\Discovery\Data\ResourceDiscoveryData;
use Splicewire\Beam\Discovery\Http\ResourceDiscoveryController;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Discovery\ResourceMount;
use Splicewire\Beam\Doctor\ParticleSlotCollisionAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Filters\Data\ResourceFilterVariantsData;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\LegacyOperationAlias;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleRelative as ParticleRelativeAttribute;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleRelative;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;
use Splicewire\Beam\Rendering\Data\ResourceRenderingCatalogData;
use Splicewire\Beam\Rendering\Http\RenderingCatalogController;
use Splicewire\Beam\Rendering\Http\RenderingsController;
use Splicewire\Beam\Rendering\RenderingCertifier;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Routing\IdConstraint;
use Splicewire\Beam\Routing\RouteVisibility;
use Splicewire\Beam\Webhooks\Data\EventCatalogData;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;

/**
 * **The one implementation of every particle mount shape** (api-surface-coherence ticket 49).
 *
 * Before this class the six mount shapes lived as six `Route::` macro closures, and the only way to
 * offer a second front door was to reimplement them. The bodies below are **verbatim moves** of those
 * closures — `$this` (the Router, inside a macro) became an explicit `Router $router` parameter and
 * nothing else changed, which is what makes the route table byte-identical across the refactor by
 * construction rather than by diffing.
 *
 * Two callers, one body:
 *
 * - {@see Particle}`::mount()` — the sanctioned front door, a fluent builder
 *   ({@see PendingParticleMount}) whose widening calls are opt-in.
 * - The `Route::particle*()` / `Route::resourceRenderings()` / `Route::resourceFilters()` macros, which
 *   are now one-line delegations here.
 *
 * ⚠️ **This class is not the enforcement seam and cannot be one.** See {@see PendingParticleMount}'s
 * docblock for the measured reason: a facade closes the *derived-name* half of the route-name collision
 * hazard and cannot touch the hand-written half.
 */
class ParticleMounter
{
    /**
     * The app-global route-parameter claims this mounter has made, `binding => model class`.
     *
     * A ledger, not a registry: it exists so an app-global side effect of mounting is *inspectable*
     * (api-surface-coherence ticket 51 §1 — "declared rather than incidental") and so a second,
     * conflicting claim on one parameter name can be reported. {@see relative()} writes it.
     *
     * @var array<string, class-string>
     */
    protected array $bindingClaims = [];

    /**
     * Every app-global route-parameter claim made by a relative mount, `binding => model class`.
     *
     * Read it to answer "what does `{fragment}` resolve to, and who decided that" without grepping
     * route files across a host and its packages.
     *
     * @return array<string, class-string>
     */
    public function bindingClaims(): array
    {
        return $this->bindingClaims;
    }

    /**
     * Register the app-global route-model binding a relative mount needs, and ledger the claim.
     *
     * Re-claiming a binding for the SAME model is idempotent — one child mounted under two parents of
     * the same class is a legitimate shape, and ticket 50's edge declarations make it the common one.
     * Re-claiming it for a DIFFERENT model is a defect: the second claim wins the map and every
     * `{$binding}` in the estate silently changes meaning. It is reported and NOT thrown — see
     * {@see relative()} for why a boot-time fatal is the wrong instrument here.
     */
    protected function claimBinding(Router $router, string $binding, string $model): void
    {
        $claimed = $this->bindingClaims[$binding] ?? null;

        if ($claimed !== null && $claimed !== $model) {
            Log::warning('[beam] Particle relative mount re-claims the app-global route binding {'.$binding.'} for '.$model.', which is already claimed for '.$claimed.'. The later claim wins for EVERY {'.$binding.'} route in the app. Pass a distinct `binding` option to one of the two mounts.');
        }

        $this->bindingClaims[$binding] = $model;

        $router->model($binding, $model);
    }

    /**
     * The five CRUD verbs, stamped `_particle`, plus the automatic per-resource filter sub-surface.
     *
     * The body behind `Particle::mount(…)`. Was `Route::macro('particleResource', …)` until api-surface-coherence 93 deleted the macro.
     */
    public function resource(Router $router, string $uri, string $resourceKey, array $options = []): void
    {
        $only = $options['only'] ?? ['index', 'show', 'store', 'update', 'destroy'];
        // The route-name stem IS the resource key, verbatim — kebab, no transliteration.
        // api-surface-coherence 104: this was `str_replace('-', '_', $resourceKey)`, and that one
        // substitution was the whole CASE axis of the estate's route-name inconsistency — it minted
        // `context_scopes.index` / `fragment_url_batches.filters.show` beside kebab siblings, and the
        // convention then told authors to pass `'names'` explicitly to defeat it, so half the mounts
        // carried a redundant `->names('<the key again>')`. Dropping it makes the default correct and
        // those calls redundant (deleted in the same change). `'names'` survives for the case it is
        // actually for: a stem that is genuinely NOT the key — a nested exposure that needs its
        // parent's scope in the name (`circuits.guest-tokens`).
        $name = $options['names'] ?? $resourceKey;
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

        // The per-resource filter sub-surface, mounted AUTOMATICALLY at this exposure
        // (api-surface-coherence ticket 10 §3, build 35). Not opt-in the way
        // `Particle::renderings()` is: a rendering is something a host chooses to offer, while
        // a filter vocabulary is a fact about the resource — it either has a data-filters
        // registration or it does not. Mounting it here is what makes ticket 10's *registration is
        // one, exposure is many* true for filters: a resource exposed twice
        // (`/guest-tokens` and `/circuits/{circuit}/guest-tokens`) gets the sub-surface at both, and
        // a nested exposure gets it under its parent's binding, with no second declaration.
        //
        // ⚠️ FIRST, above the CRUD block, and that is load-bearing rather than tidy. Laravel matches
        // in REGISTRATION order, and `{uri}/{id}` with no `idConstraint` swallows the literal
        // `{uri}/filters`. Mounted after `show`, three of the estate's particle resources answered
        // their filter index with `silos.show` / `agents.show` / `market_extensions.show` — measured,
        // not theorised. This is the same rule the host route files already state by hand ("`order`
        // precedes `{id}` so the literal wins"); here it is paid once, in the mounter.
        //
        // Gated on the registry rather than mounted blind, so a particle resource with no filter
        // declaration does not publish nine routes that all 404. `has()` is a read of an already-
        // seeded registry, and `resourceRenderings` sets the precedent for reading one at mount time.
        //
        // `filters: false` opts out — for the one shape this cannot serve: an exposure whose route
        // group is narrower than the resource (a public/unauthenticated mount, say), where the
        // saved-filter half has no owner to scope to.
        if (($options['filters'] ?? true) && DataFilter::registry()->has($resourceKey)) {
            $this->resourceFilters(
                router: $router,
                resource: $resourceKey,
                at: $uri,
                names: $name,
                idConstraint: $idConstraint ?? 'uuid',
            );
        }

        // The per-resource hook-event catalog, mounted AUTOMATICALLY at this exposure
        // (api-surface-coherence 106, decided by 41 D7). Same driver as the filter sub-surface above,
        // and that is the whole point of the ticket: `GET /{resource}/hooks/events` used to be ONE
        // wildcard route whose resource arrived as a request-time path parameter, so
        // `BeamRouteAction::resourceKey()` — a route-LEVEL reader consumed by grouping and doc
        // extraction — returned null for it and could not be fixed by stamping. A wildcard route has
        // no single key by construction; it has 39. Concrete mounts give each one a resource, the
        // stamp works, and the null disappears without a fifth arm in `resourceKey()`'s `??` chain.
        //
        // ⚠️ UNGATED, unlike the filter block above, and the asymmetry is deliberate.
        //
        //  1. An eventless resource here answers with an EMPTY catalog, which is already the declared
        //     legal read (ticket 91: `withPrefix()` on an unknown key is not an error). An unfiltered
        //     resource, by contrast, would publish nine routes that all 404 — hence that gate.
        //  2. `EventTypeRegistry` is filled by providers that may not have run yet. Its own docblock
        //     records the case: tower defers its `compositions.*` declarations to
        //     `Application::booted()`, which is AFTER route registration. Gating the mount on a
        //     boot-order-dependent read would silently drop a host's own scoped catalog, and the
        //     symptom — a missing route — is far quieter than an empty response body.
        //
        // Mounted with the literal segments FIRST for the same reason the filter block is, though the
        // two-segment `hooks/events` tail is not swallowable by `{uri}/{id}` in any case.
        if ($options['hookEvents'] ?? true) {
            $this->resourceHookEvents($router, $resourceKey, $uri, $name);
        }

        if (in_array('index', $only, true)) {
            $stamp($router->get($uri, [$controller, 'index']), 'index');
        }

        if (in_array('show', $only, true)) {
            $stamp($withId($router->get("{$uri}/{id}", [$controller, 'show'])), 'show');
        }

        if (in_array('store', $only, true)) {
            $stamp($router->post($uri, [$controller, 'store']), 'store');
        }

        if (in_array('update', $only, true)) {
            $verbs = ($options['legacyPostUpdate'] ?? false) ? ['put', 'patch', 'post'] : ['put', 'patch'];
            $stamp($withId($router->match($verbs, "{$uri}/{id}", [$controller, 'update'])), 'update');
        }

        if (in_array('destroy', $only, true)) {
            $stamp($withId($router->delete("{$uri}/{id}", [$controller, 'destroy'])), 'destroy');
        }
    }

    /**
     * One particle operation: `POST {uri}/{id}/{name}`, plus the deprecated `POST {uri}/{id}/op/{name}`
     * alias it replaced (particle-operation-surface 12).
     *
     * The body behind one op of `Particle::ops(…)`. Was `Route::macro('particleOp', …)` until 93 deleted the macro.
     *
     * ## Why `/op/` left, and why the old spelling is still mounted
     *
     * `/op/` was a segment nothing needed. Measured across the whole estate on 2026-08-29 — 21 bootable
     * `~/Herd/*` roots (Herd symlinks resolved with `pwd -P`, so the three starter-backed roots are not
     * double-counted), **3,135 routes, 61 of them under `/op/`** — the estate already writes 118
     * `{id}/<verb>` routes without it against 21 with it, so the segment was the minority spelling of a
     * thing the same estate spells plainly everywhere else.
     *
     * Dropping it lands every operation in the slot `{uri}/{id}/{segment}`, which is occupied — by
     * renderings, by CRUD, and (the class that decides the design) by hand-written routes in no registry
     * at all. {@see ParticleSlotCollisionAudit} is the instrument that watches
     * that slot; simulated over all 21 route tables immediately before this landed, on BOTH axes (URI
     * with parameters normalised, and route name), the drop created **zero** collisions.
     *
     * **A URL that shipped is a published contract**, so the old spelling stays mounted as a deprecated
     * alias rather than being deleted. It is not decoration: eight files across five roots hand-write
     * `…/op/…` as a template literal and reach no generated client at all
     * (`~/Herd/audiostud/resources/js/…`, `~/Herd/splicewire/resources/js/editor/…`, and
     * `resources/js/editor/transport.ts` in all three starters). Deleting the segment outright would
     * have left those as live 404s that every test suite, type-check and doctor audit in the estate
     * reports as green.
     *
     * ## The alias keeps the OLD name, and the primary takes the new one
     *
     * Two routes cannot share one name — `RouteCollection::addLookups()` overwrites silently and Laravel
     * only refuses the pair at `route:cache` — so the pair has to split the two spellings:
     *
     *   primary  `{uri}/{id}/{op}`      named `{resourceKey}.{op}`
     *   alias    `{uri}/{id}/op/{op}`   named `{resourceKey}.op.{op}`   (deprecated)
     *
     * The alias keeping the *old* name is what makes this a non-event for PHP callers: every
     * `route('users.op.login-as')`, `URL::temporarySignedRoute('sigils.op.assume', …)` and
     * `getByName('beam-ux-entry.op.body')` in the estate keeps resolving, to a URL that still answers.
     * They are, deliberately, now resolving to the deprecated spelling — that is the migration signal,
     * and it is visible rather than silent because the alias is stamped
     * {@see RouteVisibility::Deprecated} and therefore vanishes from the generated client.
     *
     * `$options['name']` overrides the PRIMARY name only. Zero call sites in the estate pass it — swept
     * the package `src` roots, every `~/Herd` `routes` and `app` tree, and the starters, with the globs
     * written literally rather than through a variable (zsh does not glob after parameter expansion). If
     * an override happens to equal the alias's own derived name, the alias is skipped rather than
     * mounted into a name collision.
     */
    public function op(Router $router, string $uri, string $resourceKey, string $op, array $options = []): void
    {
        // ## The DECLARATION is asked first, and the option is what is left of the old world
        //
        // particle-operation-surface 14. `method:` and `idConstraint:` moved onto the operation, which
        // is where they always belonged — the verb of `beam-ux-entry.body` is a fact about the read it
        // performs, and it was being restated once per host in five different repositories.
        //
        // The registry read is safe here and is not a boot-order gamble: `ops()` registers each
        // declaration immediately before calling this, `find()` is the non-throwing lookup, and a
        // `null` means "mounted by bare name, registered elsewhere" — which is the pre-existing shape
        // and falls straight through to the option.
        //
        // ⚠️ The option arm is a MIGRATION state with a deletion condition, not a permanent fallback.
        // 14's end state is that this method stops reading either key; it cannot until the last mount
        // site moves, and `laravel-beam-accounts`' `Concerns\WiresDemo` (the imperative `LogInAsUser`,
        // mounted GET) was in a package that landing did not own. Deleting the arm early turns a signed
        // login-as link into a 405 that no suite in the estate would have seen.
        $declaration = app(ParticleOperationRegistry::class)->find($resourceKey, $op);

        $verb = $declaration?->method?->value
            ?? strtolower($options['method'] ?? 'post');

        $idConstraint = $declaration?->idConstraint
            ?? (is_string($options['idConstraint'] ?? null)
                ? IdConstraint::tryFrom($options['idConstraint'])
                : null);

        $name = $options['name'] ?? "{$resourceKey}.{$op}";
        $legacyName = "{$resourceKey}.op.{$op}";

        $mount = function (string $path, string $routeName) use ($router, $verb, $idConstraint, $resourceKey, $op, $options) {
            $route = $router->{$verb}($path, [ParticleOperationController::class, 'invoke'])
                ->defaults(ParticleOperationController::RESOURCE, $resourceKey)
                ->defaults(ParticleOperationController::NAME, $op)
                ->name($routeName);

            // Only `Uuid` is enforced — {@see IdConstraint} states why `Ulid`/`Int` are declared-but-inert
            // and what has to read zero before that flips.
            if ($idConstraint?->enforced()) {
                $route->whereUuid('id');
            }

            // A Stream-kind op (ADR-0160) has no single resolved response — it emits a sequence of
            // typed SSE events instead. `op()` mounts through the generic controller, so there's
            // no per-route call site to chain `->streams()` onto directly; an `'streams'` option lets
            // the caller declare it here (surgeon-audit-viability ticket 28).
            if ($options['streams'] ?? null) {
                $route->streams($options['streams']);
            }

            return $route;
        };

        $mount("{$uri}/{id}/{$op}", $name);

        if ($legacyName === $name) {
            return;
        }

        $alias = $mount("{$uri}/{id}/op/{$op}", $legacyName);

        // Constructed rather than chained off `->beam()`: the namespace is a route MACRO booted by
        // {@see \Splicewire\Beam\Concerns\BootsBeamRouteNamespace}, and the mounter should not depend on
        // provider boot order to stamp its own route.
        (new BeamRouteProxy($alias))->visibility(RouteVisibility::Deprecated);

        // The measurement half of "when is the alias removed?". Nothing in this estate could previously
        // answer whether a deprecated URL was still being called; this middleware answers it two ways —
        // a `Deprecation`/`Link` header pair on every response (RFC 8594, so an integrator's own client
        // can see it) and one log line per call (so the host can, without instrumenting anything).
        $alias->middleware(LegacyOperationAlias::class);
    }

    /**
     * A LIST of op declarations, each mounted by {@see op()}. The `group()` (middleware/prefix) stays the
     * caller's. Each entry is one of three forms, the op NAME derived from the declaration:
     *
     *   'reorder'                          a bare name — already registered elsewhere, mount only.
     *   DownloadMedia::class               a #[ParticleOp] class-string — discovered (register) + mounted.
     *   new ParticleOperation(name: …, …)  an inline object — registered here + mounted.
     *
     * The body behind `Particle::ops(…)`. Was `Route::macro('particleOps', …)` until 93 deleted the macro.
     */
    public function ops(Router $router, string $uri, string $resourceKey, array $ops, array $options = []): void
    {
        $discovery = app(AttributedParticleDiscovery::class);
        $operations = app(ParticleOperationRegistry::class);

        foreach ($ops as $op) {
            $name = match (true) {
                // An inline runtime object — register it, mount by its own name.
                $op instanceof ParticleOperation => tap($op->name, fn () => $operations->register($op)),
                // A #[ParticleOp] class-string — discover (registers) + read the attribute's name to mount.
                is_string($op) && class_exists($op) => tap(
                    (new ReflectionClass($op))->getAttributes(ParticleOp::class)[0]?->newInstance()->name
                        ?? throw new InvalidArgumentException("Class [{$op}] carries no #[ParticleOp] to mount as a particle op."),
                    fn () => $discovery->registerClass($op),
                ),
                // A bare name — already registered elsewhere; mount only.
                default => $op,
            };

            $this->op($router, $uri, $resourceKey, $name, $options);
        }
    }

    /**
     * The bound-relative mount. Route-model-binds a RELATIVE and pushes it + its `$via` into the route
     * defaults of everything the `$routes` callback mounts.
     *
     * The body behind `Particle::relative(…)`. Was `Route::macro('particleRelative', …)` until 93 deleted the macro; the binding line is the one thing that
     * has since changed, and api-surface-coherence ticket 51 §1 is the ruling.
     *
     * ## The route-model binding is app-global, and that is now DECLARED rather than incidental
     *
     * `$binding` claims a route parameter name **app-wide**: mounting `fragments`/`media` with
     * `binding: 'fragment'` means every `{fragment}` parameter in the estate — including the four
     * hand-written `fragments/{fragment}/concept-anchors` routes in the tower host, which this mount
     * never saw — resolves through the claim this line registers.
     *
     * **It stays global, because there is no scoped alternative to move it to.** Laravel's explicit
     * binding is one `Router::$binders` map, written by `bind()`/`model()`; there is no group- or
     * route-scoped spelling of it. `->scopeBindings()` is not the counterpart it sounds like — it
     * constrains *implicit* binding of a nested parameter to its parent's relation and does nothing to
     * an explicit binder. So the honest options were "global and declared" or "not explicit at all",
     * and the first one keeps the 404-for-a-stranger-id behaviour the relative mount depends on.
     *
     * Two things make the global claim safe enough to keep:
     *
     * - **`model()`, not a hand-rolled `bind()` closure.** The original stamped
     *   `fn ($value) => $model::query()->findOrFail($value)`, which resolves on the PRIMARY KEY and
     *   ignores everything a model can say about its own addressing. `Router::model()` routes through
     *   `RouteBinding::forModel()` → `resolveRouteBinding()`, so `getRouteKeyName()`, a model's own
     *   `resolveRouteBinding()` override, and `withTrashed()` are all honoured. That is exactly the
     *   divergence ticket 51 §1 measured between this claim and the implicit binding it displaced, and
     *   it closes it: a route the mount never saw now behaves as though the claim were not there.
     * - **A conflicting re-claim is reported.** {@see $bindingClaims} ledgers who claimed what. Two
     *   mounts claiming one parameter for the SAME model is idempotent and silent; claiming it for a
     *   different model is a real defect and gets a warning. **Advisory, never fatal** — this runs at
     *   boot inside route registration, and a boot-time throw is the shape that took a host down on
     *   2026-08-25 (AGENTS.md carries the rule).
     *
     * ## A Closure `$via` makes the route table uncacheable
     *
     * `$via` lands in the route DEFAULTS, and `route:cache` serializes defaults. A relation-name string
     * survives that; a Closure does not — `route:cache` dies on it. The sibling
     * {@see resourceRenderings()} disciplines against exactly this in its own docblock ("per-route
     * config rides `->defaults()` as a plain serializable array with NO closures, so the table survives
     * `route:cache`"), and this macro was the one place that broke the rule.
     *
     * Ticket 51 §2 settled it against a green `route:cache` rather than against reasoning: **the
     * Closure form is a documented limitation, not a supported shape.** Prefer the relation-name
     * string. Where the edge genuinely needs behaviour, ticket 50's `#[ParticleRelative]` gives it a
     * home — a `public static` convention method on the edge class, whose route default is the edge
     * CLASS NAME, a serializable reference. `Tests\Particle\RelativeBindingClaimTest` pins both halves.
     */
    public function relative(
        Router $router,
        string $uri,
        string $model,
        string|Closure $via,
        Closure $routes,
        array $options = [],
    ): void {
        $binding = $options['binding'] ?? Str::kebab(class_basename($model));

        $this->claimBinding($router, $binding, $model);

        $before = $router->getRoutes()->getRoutes();
        $beforeIds = [];
        foreach ($before as $existing) {
            $beforeIds[spl_object_id($existing)] = true;
        }

        $router->group(['prefix' => "{$uri}/{{$binding}}"], $routes);

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (isset($beforeIds[spl_object_id($route)])) {
                continue;
            }

            // ⚠️ THE INNERMOST EDGE WINS, and this guard is the only thing that makes nesting mean
            // anything (particle-operation-surface 07 §A4).
            //
            // Edges compose: an edge declared `at: ''` mounts inside an enclosing `relative()` group,
            // because Laravel's `RouteGroup::formatPrefix` is `trim($old,'/').'/'.trim($new,'/')` — so
            // `compositions/{composition}/cells` + `''` yields `.../cells/{cell}/…` with no doubled
            // segment. The INNER call stamps its routes first, while the outer group's callback is
            // still running; the outer loop then sees those same routes as "new" and, without this
            // guard, re-stamps them — `Route::defaults()` overwrites, so the child would resolve its
            // parent as the OUTERMOST binding rather than its immediate one.
            //
            // The failure is silent and reads as a scoping bug three layers away: the route matches,
            // the controller resolves a parent, and it is the wrong one. Checking RELATIVE alone is
            // sufficient — the three defaults are written together and there is no path that sets one
            // without the others.
            if (isset($route->defaults[ParticleController::RELATIVE])) {
                continue;
            }

            $route->defaults(ParticleController::RELATIVE, $binding);
            $route->defaults(ParticleController::RELATIVE_MODEL, $model);
            $route->defaults(ParticleController::VIA, $via);
        }
    }

    /**
     * Mount the DECLARED relative edges of one parent — api-surface-coherence ticket 50's mount side.
     *
     * ```php
     * Particle::relatives('fragments');                              // every declared edge
     * Particle::relatives('fragments', [FragmentMediaRelative::class]); // these, discovering as it goes
     * ```
     *
     * Each entry is one of the three forms {@see ops()} already established, and for the same reasons:
     *
     *   'media'                             a bare CHILD key — already registered elsewhere, mount only.
     *   FragmentMediaRelative::class        a #[ParticleRelative] class-string — discovered + mounted.
     *   new ParticleRelative(child: …, …)   an inline runtime object — registered here + mounted.
     *
     * `true` mounts everything {@see ParticleRelativeRegistry::forParent()} holds. That spelling needs
     * boot-time discovery to have run (`beam.core.particle.discover_paths`); a host that registers its
     * particle classes explicitly — which is most of the estate today — passes class-strings, exactly as
     * it already does for ops.
     *
     * ## Why this is not `Particle::mount($parent)->relatives(true)` and nothing else
     *
     * The builder slot exists too ({@see PendingParticleMount::relatives()}), but it cannot be the only
     * spelling, and the flagship's own edge is why: `fragments` is **not** a particle mount. Its CRUD is
     * hand-written `Route::get()`/`Route::post()` in the host route file, so there is no
     * `Particle::mount('fragments')` for a `->relatives(true)` to hang off. An edge is a fact about a
     * PARENT RESOURCE KEY, not about a particular mount of it, so the standalone verb is the general
     * form and the builder slot is the convenience for parents that happen to be particle-mounted.
     *
     * Both go through this one method, so the two spellings cannot produce different route tables.
     *
     * @param  array<int, string|ParticleRelative>|bool  $relatives
     */
    public function relatives(Router $router, string $parent, array|bool $relatives = true): void
    {
        $registry = app(ParticleRelativeRegistry::class);
        $discovery = app(AttributedParticleDiscovery::class);

        if ($relatives === true) {
            $declarations = $registry->forParent($parent);
        } else {
            $declarations = [];

            foreach ((array) $relatives as $entry) {
                if ($entry instanceof ParticleRelative) {
                    // An inline runtime object — register it, mount it.
                    $registry->register($entry);
                    $declarations[] = $entry;

                    continue;
                }

                if (is_string($entry) && class_exists($entry)) {
                    // A #[ParticleRelative] class-string — discover (which registers), then read the
                    // runtime declaration back out by key. Deliberately NOT reflected again here: the
                    // discovery class is the single reader of the attribute (the rule
                    // `AttributedParticleDiscovery::resourceFromAttribute()` states as RDU-02), and two
                    // readers of one attribute is how the two drift.
                    $discovery->registerClass($entry);
                    $declarations[] = $this->declaredEdge($registry, $entry);

                    continue;
                }

                // A bare child key — already registered elsewhere; mount only.
                $declarations[] = $registry->get($parent, $entry);
            }
        }

        foreach ($declarations as $relative) {
            $this->mountRelative($router, $relative);
        }
    }

    /**
     * Read a just-discovered edge back out of the registry, addressed by its own attribute.
     *
     * The attribute is read here for its ADDRESS only — the two resource keys — never for the
     * declaration itself, which discovery has already built and registered.
     */
    protected function declaredEdge(ParticleRelativeRegistry $registry, string $class): ParticleRelative
    {
        $attribute = (new ReflectionClass($class))->getAttributes(ParticleRelativeAttribute::class)[0] ?? null;

        if ($attribute === null) {
            throw new InvalidArgumentException("Class [{$class}] carries no #[ParticleRelative] to mount as a relative edge.");
        }

        $attribute = $attribute->newInstance();

        return $registry->get($attribute->of, $attribute->child);
    }

    /**
     * One declared edge → the same {@see relative()} call the hand-written mount made, with the child
     * mounted through the ordinary resource front door.
     *
     * The child goes through {@see PendingParticleMount} rather than straight to {@see resource()} so a
     * declared edge and a hand-written one are the same mount in the same vocabulary — which is what
     * makes "its routes are byte-identical to the hand-written form" a property rather than a
     * coincidence to be re-diffed. The builder is `register()`ed explicitly instead of being left to
     * `__destruct`, because inside a route-group callback the destruction point is the callback's, and
     * an edge that mounts *after* the group closes would land its child routes outside the bound prefix.
     */
    protected function mountRelative(Router $router, ParticleRelative $relative): void
    {
        $this->relative(
            router: $router,
            uri: $relative->parentUri(),
            model: $relative->model,
            via: $relative->routeVia(),
            routes: function () use ($router, $relative): void {
                $mount = new PendingParticleMount($router, $this, $relative->childUri(), $relative->child);

                if ($relative->only !== null) {
                    $mount->only($relative->only);
                }

                if ($relative->names !== null) {
                    $mount->names($relative->names);
                }

                $mount->idConstraint($relative->idConstraint)->register();
            },
            options: ['binding' => $relative->bindingName()],
        );
    }

    /**
     * Every rendering the {@see ResourceRenderingRegistry} holds for `$resource` — one read route each,
     * plus a write route only where {@see RenderingCertifier} could prove reversibility, plus the
     * catalog route (ticket 33), which mounts even for zero renderings.
     *
     * The body behind `Particle::renderings(…)`. Was `Route::macro('resourceRenderings', …)` until 93 deleted the macro.
     */
    public function resourceRenderings(
        Router $router,
        string $resource,
        string $subject,
        ?string $at = null,
        ?array $abilities = null,
        array $middleware = [],
        array $with = [],
        string $idConstraint = 'uuid',
    ): void {
        $at = $at ?? $resource;
        $abilities = $abilities ?? ['view' => 'view', 'mutate' => 'update'];

        $registry = app(ResourceRenderingRegistry::class);
        $certifier = app(RenderingCertifier::class);

        $grants = [];

        foreach ($registry->for($resource) as $rendering) {
            $fidelity = $certifier->certify($rendering);
            $writable = $fidelity === Fidelity::LosslessEligible;

            $config = [
                'resource' => $resource,
                'rendering' => $rendering->name(),
                'subject' => $subject,
                'with' => array_values($with),
                'abilities' => $abilities,
                'fidelity' => $fidelity->value,
                'writable' => $writable,
            ];

            $uri = ($at === '' ? '' : $at.'/').'{id}/'.$rendering->name();
            $name = ($at === '' ? '' : $at.'.').$rendering->name();

            $mount = function (RouteInstance $route) use ($config, $middleware, $idConstraint): RouteInstance {
                $route->defaults(RenderingsController::CONFIG, $config);

                if ($idConstraint === 'uuid') {
                    $route->whereUuid('id');
                }

                if ($middleware !== []) {
                    $route->middleware($middleware);
                }

                return $route;
            };

            $mount($router->get($uri, [RenderingsController::class, 'show']))->name($name);

            if ($writable) {
                $mount($router->post($uri, [RenderingsController::class, 'store']))->name($name.'.ingest');
            }

            $grants[$rendering->name()] = [
                'fidelity' => $fidelity->value,
                'writable' => $writable,
            ];
        }

        // The discovery route (api-surface-coherence ticket 33). OUTSIDE the loop deliberately: a
        // resource that mounts this shape and has declared no rendering still answers, with an empty
        // set. Absence of renderings is not absence of resource.
        //
        // It carries the mount-time grant map — the same certified verdict the read/write routes
        // freeze — while leaving the format enumeration to be re-read per request.
        //
        // It does NOT inherit `$middleware`. That parameter gates the RENDERING (compositions pass
        // `consume.engine`, which meters the dogfood loopback); metering a metadata read as engine
        // consumption would be a new cost on an endpoint that touches no engine. The route group's
        // own middleware still applies, which is where `abilities: []` says the gate lives.
        $catalogUri = ($at === '' ? '' : $at.'/').'renderings';
        $catalogName = ($at === '' ? '' : $at.'.').'renderings';

        $router->get($catalogUri, [RenderingCatalogController::class, 'index'])
            ->defaults(RenderingCatalogController::CONFIG, [
                'resource' => $resource,
                'subject' => $subject,
                'abilities' => $abilities,
                'renderings' => $grants,
            ])
            ->name($catalogName)
            ->beam()->returns(ResourceRenderingCatalogData::class);
    }

    /**
     * The per-resource discovery listing: `GET {mount}/discovery` (api-surface-coherence 105, 41 D5).
     *
     * Takes a {@see ResourceMount} rather than a URI and a key, because the mount is exactly the unit
     * the listing is published per — 41 D5's *"per-MOUNT, not per-resource"* — and re-deriving one from
     * two loose strings at the call site is how the two halves drift apart.
     *
     * The route carries the mount's COMMON middleware, and it has to: this is called from a boot-time
     * pass over the finished route table ({@see ResourceDiscoveryAutoMounter}), so there is no enclosing
     * route group to inherit `auth:sanctum` and the tenancy stack from. Without it the listing would be
     * the one unauthenticated door onto an authenticated resource.
     */
    public function resourceDiscovery(Router $router, ResourceMount $mount): RouteInstance
    {
        $route = $router->get($mount->uri(), [ResourceDiscoveryController::class, 'index'])
            ->defaults(ResourceDiscoveryController::CONFIG, [
                'resource' => $mount->resource,
                'mount' => $mount->root,
            ])
            // ALSO the ordinary `_particle` stamp (ticket 01), so the listing groups, documents and
            // name-checks as a sub-operation of its resource exactly as the filter sub-surface does.
            ->defaults(ParticleController::RESOURCE, $mount->resource)
            ->name($mount->routeName());

        if ($mount->middleware !== []) {
            $route->middleware($mount->middleware);
        }

        $route->beam()->returns(ResourceDiscoveryData::class);

        return $route;
    }

    /**
     * The per-resource hook-event catalog: `GET {at}/hooks/events` (api-surface-coherence 106).
     *
     * The body behind `Particle::hookEvents(…)` and behind the automatic mount in {@see resource()}.
     * It replaces the single wildcard `{resource}/hooks/events` route: the resource is frozen HERE, in
     * the route defaults, rather than read off a path parameter at request time, which is what makes
     * the route answerable by {@see BeamRouteAction::resourceKey()}.
     *
     * The stamp is the ordinary `_particle` default — no new one. Ticket 33 flagged "a fifth sub-surface
     * means a fifth arm" in `resourceKey()`'s `??` chain as a real cost of non-convergence; writing an
     * existing default is how this refuses to pay it (41 D2).
     */
    public function resourceHookEvents(
        Router $router,
        string $resource,
        string $at = '',
        ?string $names = null,
        array $middleware = [],
    ): void {
        $prefix = $at === '' ? 'hooks/events' : rtrim($at, '/').'/hooks/events';

        // An EMPTY `$names` says the enclosing route group already names this surface; `null` (nothing
        // passed) falls back to the resource key. Same rule as `resourceFilters()`, stated the same way.
        $stem = $names ?? $resource;
        $name = $stem === '' ? 'hooks.events' : $stem.'.hooks.events';

        $route = $router->get($prefix, [HookEventCatalogController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, $resource)
            ->name($name);

        if ($middleware !== []) {
            $route->middleware($middleware);
        }

        $route->beam()->returns(EventCatalogData::class);
    }

    /**
     * The per-resource filter sub-surface (api-surface-coherence ticket 10, build 35).
     *
     * The body behind `Particle::filters(…)`. Was `Route::macro('resourceFilters', …)` until 93 deleted the macro.
     */
    public function resourceFilters(
        Router $router,
        ?string $resource,
        string $at = '',
        ?string $names = null,
        array $middleware = [],
        string $idConstraint = 'uuid',
    ): void {
        // A NULL resource is the Frame-resource-root case and only that: `{resource}` there is the
        // registration key by construction, so the controller reads it off the route parameter. For
        // every other mount the key is frozen here and the URI segment is never consulted — half
        // the estate's filter keys diverge from their URL word (ticket 10 §1) and that divergence
        // is legitimate.
        $config = ['resource' => $resource];

        $prefix = $at === '' ? 'filters' : rtrim($at, '/').'/filters';

        // An EMPTY `$names` is meaningful, not missing: it says the enclosing route group already
        // names this surface, so the sub-surface's names are a bare `filters.<verb>` the group
        // prefixes. `null` (nothing passed) falls back to the resource key.
        $stem = $names ?? ($resource ?? 'frame.resources');
        $name = $stem === '' ? 'filters' : $stem.'.filters';

        $mount = function (RouteInstance $route) use ($config, $middleware, $resource): RouteInstance {
            $route->defaults(ResourceFiltersController::CONFIG, $config);

            // ALSO stamp the ordinary particle resource default (ticket 01), so the group-resolution
            // chain sees a filter route exactly as it sees any other sub-operation of the resource
            // and the sub-surface inherits its resource's documentation group with nothing declared.
            // This is the same trick `resourceRenderings()` leans on — the export routes' glob
            // was retired from the host's backlog because the route gained this stamp.
            //
            // Skipped for the frame-root mount, where the resource is a path parameter: there is no
            // one resource to stamp, and a stamp naming `{resource}` would be a lie the chain would
            // then try to resolve.
            if ($resource !== null) {
                $route->defaults(ParticleController::RESOURCE, $resource);
            }

            if ($middleware !== []) {
                $route->middleware($middleware);
            }

            return $route;
        };

        // ORDER IS LOAD-BEARING. `schema`, `variants` and `options/{ref}` are literal segments that
        // would otherwise be swallowed by `{id}`. The uuid constraint below makes that impossible
        // as well, but relying on a constraint alone would break the moment a host mounts with
        // `idConstraint: null` — so the literals are declared first AND constrained.
        $mount($router->get($prefix.'/schema', [ResourceFiltersController::class, 'schema']))
            ->name($name.'.schema');

        $mount($router->get($prefix.'/variants', [ResourceFiltersController::class, 'variants']))
            ->name($name.'.variants')
            ->beam()->returns(ResourceFilterVariantsData::class);

        $mount($router->get($prefix.'/options/{ref}', [ResourceFiltersController::class, 'options']))
            ->name($name.'.options');

        $mount($router->get($prefix.'/{variant}/schema', [ResourceFiltersController::class, 'variantSchema']))
            ->name($name.'.variant-schema');

        $mount($router->get($prefix, [ResourceFiltersController::class, 'index']))
            ->name($name.'.index');

        $mount($router->post($prefix, [ResourceFiltersController::class, 'store']))
            ->name($name.'.store');

        $withId = function (RouteInstance $route) use ($mount, $idConstraint): RouteInstance {
            $route = $mount($route);

            return $idConstraint === 'uuid' ? $route->whereUuid('id') : $route;
        };

        $withId($router->get($prefix.'/{id}', [ResourceFiltersController::class, 'show']))
            ->name($name.'.show');

        $withId($router->match(['put', 'patch'], $prefix.'/{id}', [ResourceFiltersController::class, 'update']))
            ->name($name.'.update');

        $withId($router->delete($prefix.'/{id}', [ResourceFiltersController::class, 'destroy']))
            ->name($name.'.destroy');
    }
}
