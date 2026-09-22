<?php

namespace Splicewire\Beam\Particle\Mount;

use Closure;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Splicewire\Beam\Discovery\Data\ResourceDiscoveryData;
use Splicewire\Beam\Discovery\Http\ResourceDiscoveryController;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Discovery\ResourceMount;
use Splicewire\Beam\Doctor\ParticleSlotCollisionAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleRelative as ParticleRelativeAttribute;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleRelative;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;
use Splicewire\Beam\Particle\Subject\SubjectResolvers;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\IdConstraint;
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
 * The Particle facade and its pending builder delegate route registration here.
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

        // The per-resource hook-event catalog, mounted AUTOMATICALLY at this exposure
        // (api-surface-coherence 106, decided by 41 D7). Concrete mounts carry their resource stamp,
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
            $stamp($withId($router->match(['put', 'patch'], "{$uri}/{id}", [$controller, 'update'])), 'update');
        }

        if (in_array('destroy', $only, true)) {
            $stamp($withId($router->delete("{$uri}/{id}", [$controller, 'destroy'])), 'destroy');
        }
    }

    /**
     * One particle operation at `{method} {uri}[/{coordinate}…]/{name}`.
     *
     * The body behind one op of `Particle::ops(…)`. Was `Route::macro('particleOp', …)` until 93 deleted the macro.
     *
     * ## The coordinates are the SUBJECT's (particle-operation-surface 20, decided by 16 §D1)
     *
     * This method used to spell its URI from the literal `{uri}/{id}/{op}`, so a `NoSubject` op mounted
     * with an `{id}` it never read and an `ActorSubject` op landed at `users/{id}/me`. The declared
     * subject already said its coordinates — `ResolvesOperationSubject::pathParameters()`, *"declarative
     * rather than inspected, so a mount, the published reference and the client codegen can all know an
     * operation's URL shape"* — and nothing read it. Now the mount does:
     *
     *   `{uri}` + one `/{param}` per `pathParameters()` entry, in order + `/{name}`
     *
     * with two rules that keep the existing population byte-identical and the edge case honest:
     *
     * - **`subject: null` is `RecordSubject`, whose list is `['id']`** — so every declaration that predates
     *   the slot (every shipped one) spells exactly what it spelled before. That is by construction, and
     *   `Tests\Particle\OperationCoordinatesMountTest` pins it with a route-table comparison.
     * - **A parameter the enclosing group already carries is NOT re-emitted.** The group prefix under a
     *   relative edge is `hulls/{hull}`; a resolver listing `['hull', 'id']` there emits only `{id}`, and a
     *   hand-written `Particle::ops('hulls/{hull}/holds', …)` is read the same way. Carried parameters are
     *   read off {@see Router::getLastGroupPrefix()} and `$uri` itself.
     *
     * The published reference and the client codegen read the ROUTE TABLE this produces
     * (`ParticleRouteManifestSource`, `ResourceMountMap::rootOf()`, Scribe's URL strategy), so they see the
     * same list without a second reader of the declaration — which is the whole reason the list is on
     * the port and not on a mount option.
     *
     * ⚠️ **Read at boot, without constructing the resolver.** `SubjectResolvers` rules that a class-string
     * resolver resolves per request and never at registration; the read here is
     * {@see SubjectResolvers::coordinates()}, a STATIC call on the declared class, so no constructor runs.
     * AGENTS.md's shape for a boot-time fact is the same: computed on read from the declaration, never
     * stamped at `register()` — an op registered before its resource, or on a host that cannot resolve
     * the resolver's dependencies yet, mounts at the right URL regardless.
     *
     * The `{id}` uuid constraint is applied only when `{id}` is among the EMITTED coordinates: a
     * `whereUuid('id')` on a route with no `{id}` is a constraint on nothing, and Laravel would
     * quietly keep it.
     *
     * The operation mounts once, named `{stem}.{op}`. `names` supplies the enclosing mount's
     * name stem (including relative edges); `name` overrides the complete name. No legacy
     * URI, route name or alias option is mounted. Slot collisions are audited against the
     * actual route table by {@see ParticleSlotCollisionAudit}.
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

        $stem = $options['names'] ?? $resourceKey;
        $name = $options['name'] ?? "{$stem}.{$op}";

        // The subject's coordinates (see the docblock) — a static read of the declaration, so a
        // class-string resolver is never constructed at boot. `null` declaration ⇒ `['id']`.
        $coordinates = SubjectResolvers::coordinates($declaration);
        $carried = $this->carriedParameters($router, $uri);
        $emitted = array_values(array_filter($coordinates, fn (string $parameter) => ! in_array($parameter, $carried, true)));

        $path = $uri;

        foreach ($emitted as $parameter) {
            $path .= '/{'.$parameter.'}';
        }

        $route = $router->{$verb}("{$path}/{$op}", [ParticleOperationController::class, 'invoke'])
            ->defaults(ParticleOperationController::RESOURCE, $resourceKey)
            ->defaults(ParticleOperationController::NAME, $op)
            ->name($name);

        // Only `Uuid` is enforced — {@see IdConstraint} states why `Ulid`/`Int` are declared-but-inert.
        // Apply it only when this mount emits `{id}`.
        if ($idConstraint?->enforced() && in_array('id', $emitted, true)) {
            $route->whereUuid('id');
        }

    }

    /**
     * The route parameters the enclosing route group and the mount URI already carry — `{hull}` in a
     * relative edge's `hulls/{hull}` prefix, or in a hand-written `hulls/{hull}/holds` URI — so
     * {@see op()} does not emit a coordinate twice.
     *
     * @return list<string>
     */
    protected function carriedParameters(Router $router, string $uri): array
    {
        $prefix = $router->getLastGroupPrefix();

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\??\}/', $prefix.'/'.$uri, $matches);

        return array_values(array_unique($matches[1]));
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
     * survives that; a Closure does not — `route:cache` dies on it. The sibling rendering mount
     * (dissolved by particle-operation-surface 13) disciplined against exactly this in its own docblock
     * ("per-route config rides `->defaults()` as a plain serializable array with NO closures, so the
     * table survives `route:cache`"), and this macro was the one place that broke the rule.
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

                // particle-operation-surface 15 (07 §D1): the two builder slots the edge could not
                // reach. Both are off unless the declaration asks, so a package shipping a new
                // `#[ParticleOp]` or `#[ParticleRelative]` never widens an edge the host mounted
                // without it (01's opt-in rule, granted at the EDGE per 07 §D2). The ops mount inside
                // this `routes:` closure, so the edge stamps them like the child's CRUD and the
                // subject resolves inside the bound parent's set (07 §D3); a sub-edge mounts inside this
                // group with its ordinary `of:`/`at:` and so composes under this prefix, the nesting
                // guard in {@see relative()} keeping its own binding (07 §D4).
                $mount->ops($relative->ops)->relatives($relative->relatives);

                $mount->idConstraint($relative->idConstraint)->register();
            },
            options: ['binding' => $relative->bindingName()],
        );
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
        // passed) falls back to the resource key.
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
}
