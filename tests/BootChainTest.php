<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\TraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods;
use Splicewire\Beam\BeamServiceProvider;

/**
 * The provider's `boot` chain — beam's first adoption of popcorn's trait-method chain.
 *
 * `packageBooted()` used to carry a hand-written index of the provider's own parts:
 *
 * ```php
 * $this->bootParticleRouteMacros();
 * $this->bootBeamRouteNamespace();
 * $this->bootResourceRenderingsMacro();
 * ```
 *
 * Each macro now lives in the trait that owns it, declaring `#[Chained('boot', order: N)]`, and the block
 * is one `chainTraitMethods('boot')` call.
 *
 * ⚠️ **This test exists because the conversion is only safe if the ORDER is pinned.** The three macros
 * mount onto the same router, and the sequence above was correct by hand. `pint`'s Laravel preset ships
 * the `ordered_traits` fixer, which sorts a class's `use` statements alphabetically — so a chain resting
 * on `use` position would be resequenced by a formatter on an unrelated commit, with nothing failing.
 * The `order:` values are what carry it, and this test is what proves they still say what the deleted
 * call block said.
 */
class BootChainTest extends TestCase
{
    /**
     * The hand-written sequence the chain replaced, verbatim. Change this only with the reason written down.
     *
     * One member has been added since, and its reason: api-surface-coherence ticket 35's per-resource
     * filter sub-surface declares `#[Chained('boot', order: 5)]`, ahead of the original three. It has to
     * be — `resourceFilters` is mounted AUTOMATICALLY from `particleResource`, so the macro must exist by
     * the time `bootParticleRouteMacros` (order 10) registers the macro that calls it. Order 5 is the
     * declaration of that dependency; this list is what stops a later edit from quietly reordering it.
     */
    private const HISTORICAL_ORDER = [
        'bootResourceFiltersMacro',
        'bootParticleRouteMacros',
        'bootBeamRouteNamespace',
        'bootResourceRenderingsMacro',
    ];

    public function test_the_boot_chain_resolves_in_the_order_the_hand_written_block_used(): void
    {
        $resolved = array_map(
            fn ($method) => $method->getName(),
            TraitMethods::in(BeamServiceProvider::class, 'boot'),
        );

        $this->assertSame(self::HISTORICAL_ORDER, $resolved);
    }

    public function test_the_provider_declares_the_chain_so_a_detector_can_find_it(): void
    {
        // The interface is the detector — a framework hook can ask whether a provider chains without
        // every provider remembering to call anything.
        $this->assertInstanceOf(ChainsTraitMethods::class, $this->app->getProvider(BeamServiceProvider::class));
    }

    /**
     * The behavioural half: every macro the chain is responsible for is actually registered after boot.
     *
     * Asserted rather than assumed, because the failure mode of a chain that silently resolves to nothing
     * is a provider that boots clean and mounts no routes — which is exactly the dead-seam shape this
     * estate has now found four times (`afterResolving`, `discover_paths`, `auto_discover_types`, the
     * `#[TypeScript]` scan). A chain with zero links is a passing test unless something checks the work.
     */
    public function test_every_macro_the_chain_owns_is_registered(): void
    {
        foreach (['particleResource', 'particleOp', 'particleOps', 'particleRelative', 'resourceRenderings', 'resourceFilters'] as $macro) {
            $this->assertTrue(Route::hasMacro($macro), "Route::{$macro}() was not registered by the boot chain.");
        }

        $this->assertTrue(RouteInstance::hasMacro('beam'), 'The ->beam() route-instance namespace was not registered.');
    }

    public function test_the_chain_is_not_empty(): void
    {
        // Guards the same dead-seam shape from the other side: a rename that unhooks every link would
        // leave the order assertion comparing two empty arrays.
        $this->assertCount(count(self::HISTORICAL_ORDER), TraitMethods::in(BeamServiceProvider::class, 'boot'));
    }
}
