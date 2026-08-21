<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use ReflectionProperty;
use RuntimeException;

/**
 * The tripwire against a registration idiom that CANNOT work: hooking `afterResolving()` on a particle
 * registry that the container has already resolved (particle-contribution-seam ticket 07).
 *
 * ## The trap
 *
 * Two packages (beam-tenancy, beam-accounts) registered their resource declarations inside
 * `$app->afterResolving(ParticleResourceRegistry::class, …)`, called from `boot()`, on the stated
 * reasoning that this made "boot order between beam and the package irrelevant." It is the exact
 * opposite. Beam binds the registry as a singleton in `packageRegistered()` and RESOLVES it in its own
 * `packageBooted() → discoverResources()` — position 2 in a real host's provider order. Laravel returns a
 * cached singleton from `$this->instances` WITHOUT firing resolving callbacks, so a callback registered by
 * any provider that boots later never fires. Measured: those two packages' 6 declarations dark in three
 * independent hosts, with nothing said about it. Packages using the direct idiom were unaffected — which
 * is what made the failure look structural rather than idiom-specific for as long as it did.
 *
 * ## Why a hook was never needed
 *
 * The registry is BOUND in the register phase, and Laravel runs `register()` on every provider before it
 * runs `boot()` on any provider. So `$app->bound(ParticleResourceRegistry::class)` is already true when any
 * package boots, whatever the provider order. The direct idiom is order-safe by construction:
 *
 *     public function packageBooted(): void
 *     {
 *         if (! $this->app->bound(ParticleResourceRegistry::class)) {
 *             return;
 *         }
 *
 *         $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(…));
 *     }
 *
 * That is what beam-taxonomy and satellite-training do, and their declarations are live.
 *
 * ## Where this runs
 *
 * From `Application::booted()`, NOT from beam's own `packageBooted()` — a package that boots after beam
 * has not registered its callback yet at the time beam boots, so checking early would see nothing. By the
 * booted callback every provider has run and the container's callback table is final.
 *
 * Alias-aware: splicewire-app binds `Splicewire\Tower\Particle\ParticleResourceRegistry` as the singleton
 * and ALIASES beam's FQN onto it. `afterResolving()` stores under `getAlias($abstract)`, so a hook naming
 * beam's FQN lands under tower's — which is precisely the setting where this failure is hardest to see.
 */
class DeadResolvingHookGuard
{
    /**
     * The particle registries a package might reasonably try to hook. Both are bound in beam's register
     * phase and resolved during beam's boot, so both carry the identical trap.
     *
     * @var list<class-string>
     */
    protected array $abstracts = [
        ParticleResourceRegistry::class,
        ParticleOperationRegistry::class,
    ];

    public function __construct(protected Application $app) {}

    /**
     * Arm the guard: check once, after every provider has booted.
     */
    public function arm(): void
    {
        $this->app->booted(function (): void {
            $this->check();
        });
    }

    /**
     * @throws RuntimeException when a resolving hook is registered against an already-resolved registry.
     */
    public function check(): void
    {
        $container = $this->app;

        // The reflection read and getAlias() both live on the concrete container, not the contract. A host
        // running some other container implementation simply gets no guard rather than a hard failure.
        if (! $container instanceof Container) {
            return;
        }

        foreach ($this->abstracts as $abstract) {
            $alias = $container->getAlias($abstract);

            if (! $container->resolved($alias)) {
                // Nothing has asked for it yet, so a resolving callback still has a chance to fire.
                continue;
            }

            if ($this->callbackCount($container, $alias) === 0) {
                continue;
            }

            throw new RuntimeException($this->message($abstract, $alias));
        }
    }

    /**
     * How many `afterResolving` callbacks the container holds for an abstract.
     *
     * Read by reflection because `Container::$afterResolvingCallbacks` has no accessor. That is the whole
     * reason this trap was invisible: there is no supported way to ask "will my callback ever fire?", so a
     * package cannot self-check and a host cannot audit it. Reading the property is the narrowest thing
     * that answers the question, and it is read-only.
     */
    protected function callbackCount(Container $container, string $alias): int
    {
        $property = new ReflectionProperty(Container::class, 'afterResolvingCallbacks');
        $property->setAccessible(true);

        /** @var array<string, array<int, callable>> $callbacks */
        $callbacks = $property->getValue($container);

        return count($callbacks[$alias] ?? []);
    }

    protected function message(string $abstract, string $alias): string
    {
        $aliasNote = $alias === $abstract
            ? ''
            : " (bound in this host as [{$alias}], with [{$abstract}] aliased onto it)";

        return "A resolving hook is registered against [{$abstract}]{$aliasNote}, but the container "
            ."resolved it during beam's own boot — Laravel returns the cached singleton without firing "
            .'resolving callbacks, so that hook will NEVER run and whatever it registers is silently '
            ."absent. Register directly from your provider's boot instead; the registry is bound in the "
            ."register phase, so bound() is already true whatever the provider order:\n\n"
            ."    if (! \$this->app->bound(\\{$abstract}::class)) return;\n"
            ."    \$this->app->make(\\{$abstract}::class)->register(...);\n\n"
            .'See particle-contribution-seam ticket 07.';
    }
}
