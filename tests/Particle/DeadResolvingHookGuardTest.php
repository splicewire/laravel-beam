<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Foundation\Application;
use RuntimeException;
use Splicewire\Beam\Particle\DeadResolvingHookGuard;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-contribution-seam ticket 07 — the tripwire against hooking `afterResolving()` on a particle
 * registry the container has already resolved.
 *
 * Two packages shipped that idiom, on the stated reasoning that a resolving hook made boot order
 * irrelevant. It is the reverse: beam resolves both registries during its own `packageBooted()`, and
 * Laravel returns a cached singleton WITHOUT firing resolving callbacks, so the hook never runs and
 * whatever it registers is silently absent. Six package declarations were dark in three separate hosts
 * with nothing said about it. There is no supported way for a package to ask "will my callback fire?",
 * so the guard asks on everyone's behalf, once, after all providers have booted.
 */
class DeadResolvingHookGuardTest extends TestCase
{
    public function test_it_throws_when_a_hook_is_registered_after_the_registry_was_resolved(): void
    {
        $app = new Application;
        $app->singleton(ParticleResourceRegistry::class);

        // The real host's order: beam resolves it, and only then does a later provider boot and hook.
        $app->make(ParticleResourceRegistry::class);
        $app->afterResolving(ParticleResourceRegistry::class, fn () => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/will NEVER run/');

        (new DeadResolvingHookGuard($app))->check();
    }

    public function test_it_stays_quiet_when_the_registry_has_not_been_resolved_yet(): void
    {
        $app = new Application;
        $app->singleton(ParticleResourceRegistry::class);

        // Nothing has asked for it, so this callback still has a chance to fire. Not our business.
        $app->afterResolving(ParticleResourceRegistry::class, fn () => null);

        (new DeadResolvingHookGuard($app))->check();

        $this->assertTrue(true);
    }

    public function test_it_stays_quiet_for_the_direct_idiom(): void
    {
        // What beam-taxonomy and beam-accounts do: resolve and register, no hook anywhere.
        $app = new Application;
        $app->singleton(ParticleResourceRegistry::class);
        $app->make(ParticleResourceRegistry::class);

        (new DeadResolvingHookGuard($app))->check();

        $this->assertTrue(true);
    }

    public function test_it_sees_through_an_alias_to_a_host_subclass(): void
    {
        // splicewire-app binds its OWN registry FQN as the singleton and aliases beam's onto it. A hook
        // naming beam's FQN is stored under the ALIAS TARGET, which is exactly the setting in which this
        // failure is hardest to see — so the guard must resolve the alias before it looks.
        $app = new Application;
        $app->singleton(HostParticleResourceRegistry::class);
        $app->alias(HostParticleResourceRegistry::class, ParticleResourceRegistry::class);

        $app->make(ParticleResourceRegistry::class);
        $app->afterResolving(ParticleResourceRegistry::class, fn () => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/aliased onto it/');

        (new DeadResolvingHookGuard($app))->check();
    }

    public function test_it_covers_the_operation_registry_too(): void
    {
        // Same binding shape, same boot-time resolve, same trap.
        $app = new Application;
        $app->singleton(ParticleOperationRegistry::class);
        $app->make(ParticleOperationRegistry::class);
        $app->afterResolving(ParticleOperationRegistry::class, fn () => null);

        $this->expectException(RuntimeException::class);

        (new DeadResolvingHookGuard($app))->check();
    }
}

class HostParticleResourceRegistry extends ParticleResourceRegistry {}
