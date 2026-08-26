<?php

namespace Splicewire\Beam\Facades;

use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\Mount\ParticleMountManager;
use Splicewire\Beam\Particle\Mount\PendingParticleMount;

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
 * ## It is a front door, not a gate
 *
 * The `Route::particle*()` macros still exist and still work; they are one-line delegations onto the
 * same {@see ParticleMounter} this facade drives, so there is exactly
 * one implementation and the route table cannot diverge between the two spellings. The charter for this
 * class called for deleting them outright; the measured reason that did not happen is on the ticket and
 * summarised in {@see PendingParticleMount}'s docblock — read it before
 * reaching for the delete.
 *
 * @method static \Splicewire\Beam\Particle\Mount\PendingParticleMount mount(string $uri, ?string $resourceKey = null)
 * @method static void relative(string $uri, string $model, string|\Closure $via, \Closure $routes, array $options = [])
 * @method static void relatives(string $parent, array|string|bool $relatives = true)
 * @method static void ops(string $uri, string $resourceKey, array|string $ops, array $options = [])
 * @method static void renderings(string $resource, string $subject, ?string $at = null, ?array $abilities = null, array $middleware = [], array $with = [], string $idConstraint = 'uuid')
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
