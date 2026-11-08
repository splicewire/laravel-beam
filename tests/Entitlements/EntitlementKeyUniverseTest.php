<?php

namespace Splicewire\Beam\Tests\Entitlements;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Entitlements\CanMapBuilder;
use Splicewire\Beam\Entitlements\EntitlementKeyUniverse;
use Splicewire\Beam\Tests\TestCase;

/**
 * tutorial-dx 04 (orch-lead's ruling on orch-peer's design read): one entitlement key universe, read lazily.
 *
 * Before: beam defined one `entitlement:{key}` ability per key ONCE, in its own boot, over the keys configured at that
 * moment. A host that declared its keys in its own `boot()` (calcucrypt's ch-04 move) got undefined abilities, and Exec
 * ADR-0001's "an undefined ability denies everyone" locked paying readers out, silently. `CanMapBuilder` read only
 * `app.entitlements` and resolved the principal's entitlements once per key.
 *
 * After: every key known by the time the app has booted is defined (a host's `boot()` included); a key that becomes known
 * later is defined on its first check by a `Gate::before` that only DEFINES and always returns null, so it never answers,
 * a host's own definition and every other before callback still decide, and an unknown key still meets the deny.
 * `CanMapBuilder` reads the same universe and resolves once per principal.
 */
class EntitlementKeyUniverseTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // After every package provider, as a host's AppServiceProvider is.
        return [...parent::getPackageProviders($app), HostDeclaresKeysInBootProvider::class];
    }

    private function resolverHolding(array $held): CountingEntitlementResolver
    {
        $resolver = new CountingEntitlementResolver($held);
        $this->app->instance(EntitlementResolver::class, $resolver);

        return $resolver;
    }

    private function user(): User
    {
        return new class extends User {};
    }

    public function test_a_key_a_host_declares_in_its_own_boot_is_defined_and_reaches_user_can(): void
    {
        $this->resolverHolding(['calculators.profit']);

        $this->assertTrue(Gate::has('entitlement:calculators.profit'), 'defined once the app has booted, not only at beam\'s boot');
        $this->assertTrue($this->user()->can('entitlement:calculators.profit'), 'through Gate::forUser(), which copies the abilities');
        $this->assertTrue(Gate::allows('entitlement:calculators.profit'));
    }

    public function test_an_unknown_key_is_still_denied_to_everyone(): void
    {
        $this->resolverHolding(['calculators.nope']);

        $this->assertFalse(Gate::allows('entitlement:calculators.nope'), 'Exec ADR-0001: an undefined key denies everyone');
        $this->assertFalse($this->user()->can('entitlement:calculators.nope'));
        $this->assertFalse(Gate::has('entitlement:calculators.nope'), 'nothing defines a key the universe does not know');
    }

    public function test_a_key_that_becomes_known_after_boot_is_defined_on_its_first_check(): void
    {
        $this->resolverHolding(['late.key']);
        config(['beam.core.entitlements.keys' => [...config('beam.core.entitlements.keys'), 'late.key']]);

        $this->assertFalse(Gate::has('entitlement:late.key'));
        $this->assertTrue(Gate::allows('entitlement:late.key'));
        $this->assertTrue(Gate::has('entitlement:late.key'));
    }

    public function test_the_before_callback_never_answers_so_a_later_before_callback_still_decides(): void
    {
        $this->resolverHolding(['late.key']);
        config(['beam.core.entitlements.keys' => [...config('beam.core.entitlements.keys'), 'late.key']]);
        Gate::before(fn ($user = null, $ability = null) => $ability === 'entitlement:late.key' ? false : null);

        $this->assertFalse(Gate::allows('entitlement:late.key'), 'beam\'s callback returned null, so the host\'s refusal stands');
    }

    // ── orch-peer's REVISE (2026-11-08): ADR-0001 against a later allow-all before callback ─────────────────────

    public function test_an_unknown_key_stays_denied_even_against_a_later_allow_all_before_callback(): void
    {
        $this->resolverHolding(['calculators.nope']);
        Gate::before(fn ($user = null, $ability = null) => true); // a super-admin / wildcard, registered after beam's

        $this->assertFalse(Gate::allows('entitlement:calculators.nope'), 'a key nothing declares denies everyone');
        $this->assertFalse($this->user()->can('entitlement:calculators.nope'));
        $this->assertTrue(Gate::allows('some-other-ability'), 'beam answers only for entitlement:* keys');
    }

    public function test_a_known_key_still_defers_to_a_later_allow_all_before_callback(): void
    {
        $this->resolverHolding([]);
        Gate::before(fn ($user = null, $ability = null) => true);

        $this->assertTrue(Gate::allows('entitlement:calculators.profit'), 'unchanged: beam returns null for a known key');
    }

    public function test_an_unknown_key_the_host_defined_itself_is_decided_by_the_host(): void
    {
        $this->resolverHolding([]);

        $this->assertTrue(Gate::allows('entitlement:host.unlisted.allow'), 'the host\'s own ability decides');
        $this->assertFalse(Gate::allows('entitlement:host.unlisted.deny'));
    }

    public function test_a_host_s_own_definition_of_a_known_key_is_never_replaced(): void
    {
        $this->resolverHolding(['host.owned']);

        $this->assertFalse(Gate::allows('entitlement:host.owned'), 'the host defined it to deny; beam does not redefine it');
    }

    public function test_contributed_keys_join_the_universe_lazily(): void
    {
        $this->resolverHolding(['bundle.key']);
        $this->app->make(EntitlementKeyUniverse::class)->contribute(fn (): array => ['bundle.key']);

        $this->assertContains('bundle.key', $this->app->make(EntitlementKeyUniverse::class)->keys());
        $this->assertTrue(Gate::allows('entitlement:bundle.key'));
    }

    public function test_the_can_map_reads_the_whole_universe_and_resolves_once_per_principal(): void
    {
        config(['beam.core.realm_gates' => []]);
        $resolver = $this->resolverHolding(['calculators.profit', 'bundle.key']);
        $this->app->make(EntitlementKeyUniverse::class)->contribute(fn (): array => ['bundle.key', 'bundle.other']);

        $can = $this->app->make(CanMapBuilder::class)->forPrincipal(null);

        $this->assertTrue($can['calculators.profit'], 'a beam.core.entitlements.keys key, which the map used not to read');
        $this->assertTrue($can['bundle.key']);
        $this->assertFalse($can['bundle.other']);
        $this->assertSame(1, $resolver->calls, 'one resolve for the whole feature plane, not one per key');
    }
}

class HostDeclaresKeysInBootProvider extends ServiceProvider
{
    public function boot(): void
    {
        // calcucrypt's ch-04 move: keys declared in boot(), after beam's own boot has run.
        config(['beam.core.entitlements.keys' => [
            ...(array) config('beam.core.entitlements.keys', []),
            'calculators.profit',
            'host.owned',
        ]]);
        Gate::define('entitlement:host.owned', fn ($user = null) => false);
        // Abilities the host defines under the entitlement: prefix WITHOUT listing the key in any source.
        Gate::define('entitlement:host.unlisted.allow', fn ($user = null) => true);
        Gate::define('entitlement:host.unlisted.deny', fn ($user = null) => false);
    }
}

class CountingEntitlementResolver implements EntitlementResolver
{
    public int $calls = 0;

    /** @param  list<string>  $held */
    public function __construct(private array $held) {}

    public function entitlementsFor(mixed $principal): array
    {
        $this->calls++;

        return $this->held;
    }
}
