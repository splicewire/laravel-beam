<?php

namespace Splicewire\Beam\Particle\Mount;

use Closure;
use Illuminate\Routing\Router;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * The fluent particle mount — the sanctioned front door behind `Particle::mount()`
 * (api-surface-coherence ticket 49, decided at [18 §D5]).
 *
 * ```php
 * Particle::mount('fragments')
 *     ->only(['index', 'store'])
 *     ->ops(true)                    // widens the SAME mount — not a second act
 *     ->renderings(Fragment::class);
 * ```
 *
 * ## Opt-in is the one rule that does not bend
 *
 * `ops()` and `renderings()` mount **nothing** unless asked. A package that adds a `#[ParticleOp]`
 * declaration or registers a rendering can therefore never silently add routes to a host that did not
 * ask for them. The one exception predates this class and is argued in
 * {@see ParticleMounter::resource()}: the per-resource **filter** sub-surface mounts automatically,
 * because a filter vocabulary is a fact about the resource rather than an offer the host makes.
 * `->filters(false)` opts out of that.
 *
 * ## Registration is deferred to destruction, deliberately
 *
 * Nothing mounts until the builder is released — the same mechanism Laravel's own
 * `Illuminate\Routing\PendingResourceRegistration` uses, and for the same reason: `only()` has to be
 * knowable before the CRUD verbs are mounted. In practice a statement like the one above releases the
 * builder at its own semicolon, so routes land exactly where the call sits in the file and mount ORDER
 * is preserved against the macro spelling it replaces — which is what makes ticket 49's "route table
 * byte-identical" acceptance criterion true by construction. {@see register()} forces it early.
 *
 * ## ⚠️ What this front door does and does not enforce
 *
 * The charter for this class said the facade would kill bare mounting. **It closes half the hole, and
 * the measured half is worth naming precisely.** Two route-name collisions are on this map's record:
 *
 * - `laravel-beam-market`'s four review transitions (`3df956c`) — a real `Route::particleOps()` call
 *   whose DERIVED names (`{resourceKey}.op.{op}`) carried no owning namespace. A front door can see
 *   this one, because the name is the front door's own output.
 * - A hand-written duplicate `GET /api/v1/search` whose closure never served a request while its
 *   `->name('search')` still won the flat name table. **No front door can see this one.** It is
 *   `Route::get()`, not a particle mount, and deleting every particle macro in the estate would not
 *   have prevented it.
 *
 * So: bare mounting is **discouraged, not impossible**, and the thing that makes the collision class
 * impossible is a duplicate-route-name guard on the assembled table — not a builder. Recorded here
 * rather than in a ticket comment because the next session to reach for "make it a fatal" should read
 * it at the code.
 */
class PendingParticleMount
{
    protected array $options = [];

    /** @var array<int, array{0: array, 1: array}> queued op declarations, each with its own options */
    protected array $ops = [];

    /** @var array<int, array> queued rendering mounts */
    protected array $renderings = [];

    protected bool $registered = false;

    public function __construct(
        protected Router $router,
        protected ParticleMounter $mounter,
        protected string $uri,
        protected string $resourceKey,
    ) {}

    /** Which of the five CRUD verbs to mount. Defaults to all five. */
    public function only(array $verbs): static
    {
        $this->options['only'] = $verbs;

        return $this;
    }

    /** The route-name stem. Defaults to the resource key with `-` folded to `_`. */
    public function names(string $names): static
    {
        $this->options['names'] = $names;

        return $this;
    }

    /** Accept `POST {uri}/{id}` as an update alongside PUT/PATCH. */
    public function legacyPostUpdate(bool $legacy = true): static
    {
        $this->options['legacyPostUpdate'] = $legacy;

        return $this;
    }

    /** Constrain `{id}` — `'uuid'` is the only understood value; `null` leaves it unconstrained. */
    public function idConstraint(?string $constraint): static
    {
        $this->options['idConstraint'] = $constraint;

        return $this;
    }

    /** Route through a dedicated {@see ParticleController} subclass. */
    public function controller(string $controller): static
    {
        $this->options['controller'] = $controller;

        return $this;
    }

    /**
     * Opt OUT of the automatic per-resource filter sub-surface — for an exposure whose route group is
     * narrower than the resource, where the saved-filter half has no owner to scope to.
     */
    public function filters(bool $filters = true): static
    {
        $this->options['filters'] = $filters;

        return $this;
    }

    /**
     * Widen this mount with particle operations. **Off unless asked.**
     *
     * - `ops(true)` mounts every operation the {@see ParticleOperationRegistry} already holds for this
     *   resource key — the declaration-driven spelling, and the one that makes a host's route file stop
     *   restating what the `#[ParticleOp]` attributes already say.
     * - `ops([DownloadMedia::class, 'reorder', new ParticleOperation(…)])` mounts an explicit list, in
     *   the three forms {@see ParticleMounter::ops()} documents. A bare string is the one-op spelling.
     *
     * `$options` carries `method`, `idConstraint`, `name` and `streams` through to each mounted op,
     * exactly as the `Route::particleOps()` spelling did.
     */
    public function ops(array|bool|string $ops = true, array $options = []): static
    {
        if ($ops === false) {
            return $this;
        }

        $this->ops[] = [is_string($ops) ? [$ops] : $ops, $options];

        return $this;
    }

    /**
     * Widen this mount with the resource's declared renderings + the ticket-33 catalog route.
     * **Off unless asked.**
     *
     * `$at` defaults to this mount's URI rather than to the resource key, which is the one place this
     * builder is deliberately not a transcription of `Route::resourceRenderings()`: that macro defaults
     * `$at` to `$resource`, and here the mount already knows where it lives. Pass `at: ''` to mount at
     * the current group's root, as the macro spelling does.
     */
    public function renderings(
        string $subject,
        ?string $at = null,
        ?array $abilities = null,
        array $middleware = [],
        array $with = [],
        string $idConstraint = 'uuid',
    ): static {
        $this->renderings[] = [
            'subject' => $subject,
            'at' => $at ?? $this->uri,
            'abilities' => $abilities,
            'middleware' => $middleware,
            'with' => $with,
            'idConstraint' => $idConstraint,
        ];

        return $this;
    }

    /**
     * Mount now rather than at destruction. Returns nothing to chain onto on purpose — a builder that
     * has already fired is not a builder.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $this->mounter->resource($this->router, $this->uri, $this->resourceKey, $this->options);

        foreach ($this->ops as [$declared, $options]) {
            $ops = $declared === true
                ? array_map(
                    fn (ParticleOperation $operation): string => $operation->name,
                    app(ParticleOperationRegistry::class)->forResource($this->resourceKey),
                )
                : $declared;

            $this->mounter->ops($this->router, $this->uri, $this->resourceKey, $ops, $options);
        }

        foreach ($this->renderings as $rendering) {
            $this->mounter->resourceRenderings(
                router: $this->router,
                resource: $this->resourceKey,
                subject: $rendering['subject'],
                at: $rendering['at'],
                abilities: $rendering['abilities'],
                middleware: $rendering['middleware'],
                with: $rendering['with'],
                idConstraint: $rendering['idConstraint'],
            );
        }
    }

    public function __destruct()
    {
        $this->register();
    }

    /**
     * Guard against the one shape a fluent builder cannot express: a mount that has to interleave with
     * hand-written routes between its own calls. Kept as a named affordance rather than left to a
     * caller reaching for `Route::` mid-chain.
     */
    public function then(Closure $callback): void
    {
        $this->register();

        $callback($this->router);
    }
}
