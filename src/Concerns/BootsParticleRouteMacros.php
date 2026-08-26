<?php

namespace Splicewire\Beam\Concerns;

use Closure;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Mount\ParticleMounter;

/**
 * The `Route::particleResource()` / `particleOp()` / `particleOps()` / `particleRelative()` macros — the
 * declarative mount for the generic particle REST + operation surface, so a host stops hand-mounting the
 * generic controllers against the RESOURCE/NAME route defaults.
 *
 * A link in {@see BeamServiceProvider}'s `boot` chain rather than a line in its `packageBooted()`.
 *
 * ⚠️ **These are no longer the implementation** (api-surface-coherence ticket 49). Every body moved
 * verbatim into {@see ParticleMounter}, which the sanctioned front door
 * {@see Particle}`::mount()` also drives — so there is one implementation and
 * two spellings that cannot diverge. What survives here is the macro registration and the delegation.
 *
 * Ticket 49's charter said to DELETE these outright and sweep every call site in the same change, on
 * ticket 15's finding that no other repo used them. **That finding was re-measured on 2026-08-26 and is
 * false**: 11 repos hold roughly 140 real call sites, six of them independently-released sibling
 * packages, and beam-facade ticket 26 established that the estate resolves family packages from git by
 * default — so the blast radius includes sites and packages with no source on any one machine and is
 * not enumerable, let alone atomically sweepable. The delete is a real piece of work with a measured
 * cost, not a line in this ticket.
 *
 * ⚠️ It declares `order: 10` — FIRST — rather than relying on where its `use` statement happens to sit.
 * `pint`'s Laravel preset includes the `ordered_traits` fixer, which sorts a class's `use` statements
 * alphabetically; a mount sequence resting on that would be resequenced by a formatter on an unrelated
 * commit with nothing failing. See {@see Chained} for the measurement behind
 * that rule.
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
            app(ParticleMounter::class)->resource($this, $uri, $resourceKey, $options);
        });

        Route::macro('particleOp', function (
            string $uri,
            string $resourceKey,
            string $op,
            array $options = [],
        ): void {
            /** @var Router $this */
            app(ParticleMounter::class)->op($this, $uri, $resourceKey, $op, $options);
        });

        Route::macro('particleOps', function (
            string $uri,
            string $resourceKey,
            array $ops,
            array $options = [],
        ): void {
            /** @var Router $this */
            app(ParticleMounter::class)->ops($this, $uri, $resourceKey, $ops, $options);
        });

        Route::macro('particleRelative', function (
            string $uri,
            string $model,
            string|Closure $via,
            Closure $routes,
            array $options = [],
        ): void {
            /** @var Router $this */
            app(ParticleMounter::class)->relative($this, $uri, $model, $via, $routes, $options);
        });
    }
}
