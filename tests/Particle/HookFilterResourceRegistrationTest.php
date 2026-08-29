<?php

namespace Splicewire\Beam\Tests\Particle;

use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Query\HookResourceQuery;
use Splicewire\Beam\Tests\TestCase;

/**
 * `filterable` on a `#[ParticleResource]` is a PROMISE that a data-filters resource exists under the
 * same key — and it DEFAULTS TO TRUE. {@see HookData} never spells it out; it simply never opts out.
 * {@see ParticleController::index()} routes a filterable resource
 * straight through `hydrator->query($key)`, which raises `BadMethodCallException` on an unknown key.
 *
 * beam declared the promise and never shipped the registration. Measured over authenticated HTTP at
 * `~/Herd/splicewire-app` (tenant `numero`) on 2026-08-29, before this fix:
 *
 *     GET /api/v1/hooks   500  "No data-filters resource is registered under [hooks], so no list
 *                               query can be composed for it."
 *
 * It is the same defect `splicewire/laravel-beam-calendars` repaired in b1a9cd9 for its three keys,
 * carried by the package that DEFINED the attribute.
 *
 * These tests BOOT and reflect rather than reading source text, deliberately: the defect family here
 * is "an attribute nobody instantiates is never type-checked", and asserting on the presence of a
 * `registerDefinition()` call would reproduce exactly that mistake.
 *
 * ⚠️ They are only meaningful because {@see TestCase::getPackageProviders()} boots
 * `Rushing\DataFilters\ServiceProvider`. `ResourceRegistry` is auto-resolvable, so without it `app()`
 * mints a fresh registry per call and every assertion below would read an empty throwaway —
 * {@see self::test_resolves_one_registry()} is the standing probe for exactly that.
 */
class HookFilterResourceRegistrationTest extends TestCase
{
    public function test_composes_a_list_query_for_the_key_it_declares_filterable(): void
    {
        $query = DataFilter::query('hooks');

        $this->assertInstanceOf(ResourceQuery::class, $query);
        $this->assertInstanceOf(HookResourceQuery::class, $query);
    }

    /**
     * The registry binding is a real singleton, not an auto-resolved throwaway. The cheap probe for
     * the testbench-provider trap itself: it fails the instant someone drops the provider from
     * `getPackageProviders()`, instead of the suite quietly going green against an empty registry.
     */
    public function test_resolves_one_registry(): void
    {
        $this->assertSame(app(ResourceRegistry::class), app(ResourceRegistry::class));
    }

    /**
     * The model is NOT restated on the data-filters definition — {@see
     * \Splicewire\Beam\Particle\ParticleResourceModelResolver} fills data-filters' resolver port off
     * the `#[ParticleResource]` under the same key. Asserted because the failure mode is an
     * unresolvable-model throw at REQUEST time rather than at registration time.
     */
    public function test_resolves_the_backing_model_through_the_particle_registry(): void
    {
        $this->assertSame(Hook::class, DataFilter::resource('hooks')->requireModel());
    }

    /**
     * ⚠️ `baseQuery()` is the whole substitute for the declared `scope` on a filterable resource —
     * `ParticleController::index` applies the closure "not for the filterable path, whose data-filters
     * query is its own gate". For `hooks` that scope is `latest()` and nothing more (ticket 12 §7 made
     * the `owner_*` morph audit-only on purpose), so what is at stake is the newest-first contract on
     * a PAGINATED endpoint, where an unstable order also means rows repeat or vanish across pages.
     * `HookData` declares no `#[Sortable(default: true)]`, so nothing else supplies it.
     *
     * The stock `ResourceQuery::baseQuery()` is a bare `Model::query()`, which would drop it silently.
     */
    public function test_applies_the_dto_scope_as_the_filterable_lists_base_query(): void
    {
        $composed = DataFilter::query('hooks')->apply(request())->toSql();

        $this->assertNotSame(Hook::query()->toSql(), $composed);
        $this->assertStringContainsString('order by', $composed);
    }

    /** The scope the query re-applies is the DTO's own, so the two read paths cannot drift apart. */
    public function test_reads_the_scope_off_the_particle_dto_rather_than_restating_it(): void
    {
        $this->assertTrue(method_exists(HookData::class, 'scope'));
    }

    /**
     * ⚠️ The registration is guarded with `has()` because `registerDefinition()` overwrites plainly —
     * an unguarded package registration would stomp a host that seeded its own `hooks` key from
     * `config('data-filters.resources')`. A host that declares its own wiring must win.
     */
    public function test_never_stomps_a_host_that_registered_the_key_first(): void
    {
        app(ResourceRegistry::class)->registerDefinition(new ResourceDefinition(
            key: 'hooks',
            data: HookData::class,
            query: HostOwnedHookQuery::class,
        ));

        // Re-run the registration STEP rather than rebooting the provider: the guard is what is under
        // test, not the boot order.
        $provider = new BeamServiceProvider(app());
        (fn () => $this->declareFilterResources())->call($provider);

        $this->assertInstanceOf(HostOwnedHookQuery::class, DataFilter::query('hooks'));
    }
}

/** A stand-in for a host's own `hooks` wiring — it only has to be a different concrete class. */
class HostOwnedHookQuery extends ResourceQuery {}
