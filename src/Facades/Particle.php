<?php

namespace Splicewire\Beam\Facades;

use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\Particle\Mount\ParticleMountManager;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Surgeon\BareParticleMountAudit;

/**
 * The Particle facade — the sanctioned front door for mounting the particle REST + operation surface
 * (api-surface-coherence ticket 49, decided at [18 §D5]).
 *
 * ```php
 * Particle::mount('fragments')->only(['index', 'store'])->ops(true);
 * Particle::relatives('fragments');   // the DECLARED edges — #[ParticleRelative], ticket 50
 * Particle::relative('fragments', Fragment::class, via: 'media', routes: fn () => …);  // hand-placed
 * ```
 *
 * It holds NO logic: every method resolves through `__callStatic` to the container-bound
 * {@see ParticleMountManager}, exactly as {@see Beam} resolves to `BeamManager` (beam-facade ticket 05).
 *
 * ## The word
 *
 * The estate spells "particle" two ways, and this is the second: **adjectivally, for the REST surface**
 * (`#[ParticleResource]`, `#[ParticleOp]`, `ParticleController`, `_particle` route defaults), as opposed
 * to the record noun `BeamParticle`. Ticket 18 §D5 recorded that caveat when it chose the name, and a
 * pre-flight grep on 2026-08-26 re-confirmed the word is free: no class, facade or alias named
 * `Particle` exists anywhere in the estate outside two eval'd surgeon test fixtures.
 *
 * Deliberately NOT registered as a global alias, on {@see Beam}'s precedent: every call site imports
 * this class explicitly, so a bare `\Particle` can never become a second, import-free way to say
 * `Particle::mount()` that `surgeon:trace` cannot see.
 *
 * ## It is now the ONLY door
 *
 * This docblock used to say the `Route::particle*()` macros "still exist and still work" and that the
 * charter's delete had been declined on measured grounds. Both halves are retired: api-surface-coherence
 * ticket 93 re-measured the estate and **deleted all six macros**.
 *
 * The reason the delete had been declined was that beam-facade 26 put 16 family packages beyond
 * enumeration ("no local source on this machine"), making a breaking beam-core release unbounded. That
 * was wrong twice: it is 12 packages, **none of which consume beam**, and the consumer set is enumerable
 * from the org rather than the disk. The real sweep was 126 executing call sites across 10 repos, with
 * beam core holding **zero** in `src/` — one aliased `RouteFacade::resourceFilters()` in
 * {@see BeamRouteProxy} excepted, which is precisely the call every
 * `Route::`-keyed search missed and the one that would have fataled at runtime.
 *
 * {@see BareParticleMountAudit} is the ratchet that keeps them gone: a
 * leftover macro call in a consumer is now a fatal rather than a second spelling, so the audit is an
 * early warning for anything still on the old door.
 *
 * @method static \Splicewire\Beam\Particle\Mount\PendingParticleMount mount(string $uri, ?string $resourceKey = null)
 * @method static void relative(string $uri, string $model, string|\Closure $via, \Closure $routes, array $options = [])
 * @method static void relatives(string $parent, array|string|bool $relatives = true)
 * @method static void ops(string $uri, string $resourceKey, array|string $ops, array $options = [])
 * @method static void filters(?string $resource, string $at = '', ?string $names = null, array $middleware = [], string $idConstraint = 'uuid')
 *
 * @see ParticleMountManager
 */
class Particle extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ParticleMountManager::class;
    }
}
