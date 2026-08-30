<?php

namespace Splicewire\Beam\Particle\Mount;

use Closure;
use Illuminate\Routing\Router;
use Splicewire\Beam\BeamManager;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleRelative;

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
     * This is the IMPERATIVE spelling: the edge's facts are arguments, stated at the mount. Ticket 49
     * shipped it alone because a relative edge had no declaration site; api-surface-coherence ticket 50
     * has since given it one, so {@see relatives()} is now the declared spelling and this is the
     * hand-placed one. Both are supported — this is not deprecated — but a new edge should be declared.
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
     * Mount the DECLARED relative edges of a parent — the front door for api-surface-coherence
     * ticket 50's `#[ParticleRelative]`.
     *
     * ```php
     * Particle::relatives('fragments');                                 // every edge the registry holds
     * Particle::relatives('fragments', [FragmentMediaRelative::class]); // these, discovering as it goes
     * ```
     *
     * A verb, not a noun, on this class's own rule: it mounts and returns nothing, because an edge's
     * widening surface is on the DECLARATION rather than on the call.
     *
     * This is the general form. {@see PendingParticleMount::relatives()} is the same thing hanging off a
     * particle mount of the parent, and exists only for parents that HAVE one — the flagship's
     * `fragments` does not, its CRUD being hand-written. Both drive
     * {@see ParticleMounter::relatives()}.
     *
     * `$relatives` takes the same three forms {@see ParticleMounter::ops()} documents — a bare child
     * key, a `#[ParticleRelative]` class-string, or an inline runtime declaration — or `true` for
     * everything declared against `$parent`.
     *
     * @param  array<int, string|ParticleRelative>|string|bool  $relatives
     */
    public function relatives(string $parent, array|string|bool $relatives = true): void
    {
        $this->mounter->relatives(
            $this->router,
            $parent,
            is_string($relatives) ? [$relatives] : $relatives,
        );
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
     * The per-resource hook-event catalog, mounted on its own (api-surface-coherence 106).
     *
     * Almost every caller gets this for free from {@see PendingParticleMount} — it rides the resource
     * mount, exactly as the filter sub-surface does. The standalone spelling is for a hand-rolled
     * exposure whose CRUD is not a particle mount, and it is what
     * `->beam()->inResource($key, hookEvents: true)` calls.
     *
     * Unlike {@see filters()} there is no null-resource spelling: the whole ticket was the removal of
     * the one route whose resource was a path parameter, and re-introducing that shape here would put
     * it straight back.
     *
     * ⚠️ `$at` is REQUIRED here where {@see filters()} defaults it to `''`, and that asymmetry was paid
     * for. This surface's mount point is `{$at}/hooks/events`, so an empty `$at` outside an enclosing
     * prefix resolves to the bare `hooks/events` — the UNSCOPED root catalog — and Laravel's last-wins
     * name table then hands the root's URL a resource stamp with no error anywhere. Caught by this
     * package's own suite while writing 106; a default that silently shadows another endpoint is not a
     * convenience. Pass `''` deliberately when the enclosing group already names the resource.
     */
    public function hookEvents(
        string $resource,
        string $at,
        ?string $names = null,
        array $middleware = [],
    ): void {
        $this->mounter->resourceHookEvents($this->router, $resource, $at, $names, $middleware);
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
