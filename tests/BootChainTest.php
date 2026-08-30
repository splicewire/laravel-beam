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
 * `packageBooted()` used to carry a hand-written index of the provider's own parts; each part moved into
 * the trait that owns it, declaring `#[Chained('boot', order: N)]`, and the block became one
 * `chainTraitMethods('boot')` call.
 *
 * ## The chain is down to ONE link, and that is the point of what is left here
 *
 * This test was originally about ORDER: four links, of which three registered route macros onto the same
 * router, where `bootResourceFiltersMacro` (order 5) HAD to precede `bootParticleRouteMacros` (order 10)
 * because `particleResource` mounted the filter sub-surface automatically. api-surface-coherence 93
 * deleted all three macro traits, so that dependency — and the ordering constraint it justified — no
 * longer exists.
 *
 * What survives is the OTHER half, and it is the half worth keeping: a chain that silently resolves to
 * nothing is a provider that boots clean and registers nothing, which is the dead-seam shape this estate
 * has now found four times (`afterResolving`, `discover_paths`, `auto_discover_types`, the `#[TypeScript]`
 * scan). With one link the order assertion is nearly free; the behavioural assertion under it is what
 * actually catches a chain gone dead.
 *
 * ⚠️ The `order:` values still carry the sequence, not `use` position — `pint`'s Laravel preset ships the
 * `ordered_traits` fixer, which sorts a class's `use` statements alphabetically. Should a second link ever
 * join, that is why this list exists.
 */
class BootChainTest extends TestCase
{
    /**
     * Every link the chain owns, in resolution order.
     *
     * Was four. api-surface-coherence 93 deleted `bootResourceFiltersMacro` (order 5),
     * `bootParticleRouteMacros` (order 10) and `bootResourceRenderingsMacro` (order 30) along with the six
     * route macros they registered; the `Particle::` facade is the front door those macros stood in for.
     */
    private const HISTORICAL_ORDER = [
        'bootBeamRouteNamespace',
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
     * The behavioural half: the thing the chain is responsible for is actually registered after boot.
     *
     * Asserted rather than assumed, because the failure mode of a chain that silently resolves to nothing
     * is a provider that boots clean and registers nothing — exactly the dead-seam shape named in the
     * class docblock. A chain with zero links is a passing test unless something checks the work.
     */
    public function test_what_the_chain_owns_is_registered(): void
    {
        $this->assertTrue(RouteInstance::hasMacro('beam'), 'The ->beam() route-instance namespace was not registered.');
    }

    /**
     * The macros api-surface-coherence 93 deleted stay deleted. Enforcement-by-absence is the whole point
     * of having deleted them rather than deprecating them, so absence is what gets asserted.
     *
     * `resourceRenderings` is still listed although particle-operation-surface 13 has since deleted the
     * SUBSYSTEM it fronted. The two are separate facts and this one has not changed: 93 deleted the
     * macro, and a host re-adding it would be re-opening a door whose room is now gone as well.
     */
    public function test_the_retired_route_macros_are_gone(): void
    {
        foreach (['particleResource', 'particleOp', 'particleOps', 'particleRelative', 'resourceRenderings', 'resourceFilters'] as $macro) {
            $this->assertFalse(Route::hasMacro($macro), "Route::{$macro}() is back — the front door is Particle::, see api-surface-coherence 93.");
        }
    }

    public function test_the_chain_is_not_empty(): void
    {
        // Guards the same dead-seam shape from the other side: a rename that unhooks every link would
        // leave the order assertion comparing two empty arrays.
        $this->assertCount(count(self::HISTORICAL_ORDER), TraitMethods::in(BeamServiceProvider::class, 'boot'));
    }
}
