<?php

namespace Splicewire\Beam\Particle\Mount;

use Closure;
use Illuminate\Routing\Router;
use Splicewire\Beam\BeamManager;
use Splicewire\Beam\Facades\Particle;

/**
 * The instance behind the {@see Particle} facade — the container-bound object
 * that hands out {@see PendingParticleMount} builders (api-surface-coherence ticket 49).
 *
 * Shaped after {@see BeamManager} on beam-facade ticket 05's ruling: the facade holds
 * no logic, an instance does, and the instance is resolvable and mockable without the facade.
 *
 * **Nouns return objects, verbs return values** (beam-facade ticket 04). `mount()` is the noun — it
 * returns a builder. `relative()` and `filters()` are verbs that mount and return nothing, because
 * neither has a widening surface to chain: a relative's children come from its callback, and the filter
 * sub-surface is a fixed nine routes.
 */
class ParticleMountManager
{
    public function __construct(
        protected Router $router,
        protected ParticleMounter $mounter,
    ) {}

    /**
     * Begin a particle resource mount at `$uri`.
     *
     * `$resourceKey` defaults to `$uri`, which covers the majority of the estate's mounts. Where they
     * diverge — `Particle::mount('extensions', 'market-extensions')` — the URL word and the registry key
     * are genuinely different facts, and ticket 10 §1 measured that divergence at roughly half the
     * estate's filter keys. It is not a smell to be normalised away.
     */
    public function mount(string $uri, ?string $resourceKey = null): PendingParticleMount
    {
        return new PendingParticleMount($this->router, $this->mounter, $uri, $resourceKey ?? $uri);
    }

    /**
     * The bound-relative mount: route-model-bind `$model` at `{$uri}/{binding}` and push it + `$via`
     * into the route defaults of everything `$routes` mounts.
     *
     * This is a separate front door rather than a `->relatives()` call on {@see PendingParticleMount},
     * and that is a measured limit rather than a design preference: the ticket's charter sketched
     * `->relatives(true)` as "mount the resource's declared relative edges", and **there is no
     * declaration to read yet** — api-surface-coherence ticket 50 is the one that gives a relative edge
     * a declaration site. Until 50 lands, a relative is stated at the mount, so it takes the mount's
     * arguments.
     */
    public function relative(
        string $uri,
        string $model,
        string|Closure $via,
        Closure $routes,
        array $options = [],
    ): void {
        $this->mounter->relative($this->router, $uri, $model, $via, $routes, $options);
    }

    /**
     * Particle operations mounted on their own, against a resource whose CRUD is mounted elsewhere (or
     * not at all).
     *
     * This exists because `mount(…)->only([])->ops(…)` is **not** the same thing, and the difference is
     * a live route-table change rather than a style preference: an empty `only` still runs the automatic
     * filter sub-surface, so the tidy-looking spelling silently publishes nine filter routes at a URI the
     * host only wanted an operation on. Naming the op-only shape is cheaper than remembering to write
     * `->filters(false)` next to every `->only([])`.
     *
     * `$ops` takes a single name or the three-form list {@see ParticleMounter::ops()} documents.
     */
    public function ops(string $uri, string $resourceKey, array|string $ops, array $options = []): void
    {
        $this->mounter->ops($this->router, $uri, $resourceKey, is_array($ops) ? $ops : [$ops], $options);
    }

    /**
     * The rendering surface mounted on its own, for a resource whose CRUD is mounted elsewhere — or, with
     * `at: ''`, for one already inside a route group that names it.
     *
     * Note `$at` defaults to the RESOURCE KEY here, matching `Route::resourceRenderings()`, where
     * {@see PendingParticleMount::renderings()} defaults it to the mount's URI. That is not an
     * inconsistency: the builder knows where it lives and this verb does not.
     */
    public function renderings(
        string $resource,
        string $subject,
        ?string $at = null,
        ?array $abilities = null,
        array $middleware = [],
        array $with = [],
        string $idConstraint = 'uuid',
    ): void {
        $this->mounter->resourceRenderings(
            router: $this->router,
            resource: $resource,
            subject: $subject,
            at: $at,
            abilities: $abilities,
            middleware: $middleware,
            with: $with,
            idConstraint: $idConstraint,
        );
    }

    /**
     * The per-resource filter sub-surface, mounted on its own.
     *
     * Almost every caller gets this for free from {@see PendingParticleMount} — it rides the resource
     * mount. The standalone spelling exists for the one shape that has no resource mount to ride: the
     * Frame resource root, where `$resource` is `null` because `{resource}` is a path parameter.
     */
    public function filters(
        ?string $resource,
        string $at = '',
        ?string $names = null,
        array $middleware = [],
        string $idConstraint = 'uuid',
    ): void {
        $this->mounter->resourceFilters($this->router, $resource, $at, $names, $middleware, $idConstraint);
    }
}
