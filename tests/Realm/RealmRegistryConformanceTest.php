<?php

namespace Splicewire\Beam\Tests\Realm;

use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The archetype **a / `PickOne`** exemplar migration (registry-kernel ticket 37).
 *
 * `RealmRegistry` was the modal shape of the census's largest cell: seeded by its own constructor AND
 * augmented by `#[Realm]` marker classes onto the same instance. What this file asserts is the part a
 * green suite otherwise cannot see — that DECLARING and INDEXING are two acts (21 D1), and that the
 * second one happened. Beam's own suite passed for months while the index held nothing but itself.
 */
class RealmRegistryConformanceTest extends TestCase
{
    public function test_the_realm_registry_conforms_to_the_kernel_contract(): void
    {
        $this->assertInstanceOf(Registry::class, $this->app->make(RealmRegistry::class));
    }

    /**
     * The index the host booted is the one beam described into — 27 D3's trap, where a harness that
     * does not boot `PopcornServiceProvider` hands every `make()` a fresh index, so describing lands on
     * a throwaway and the suite stays green over an empty index.
     */
    public function test_the_index_is_a_shared_singleton_and_holds_the_realm_root(): void
    {
        $this->assertSame($this->app->make(RegistryIndex::class), $this->app->make(RegistryIndex::class));

        $keys = array_map(strval(...), $this->app->make(RegistryIndex::class)->keys());

        $this->assertContains('beam.realm', $keys, 'beam.realm is declared but never described');
    }

    /** The index routes an absolute realm key back to this registry — the whole point of describing. */
    public function test_the_index_routes_a_realm_key_to_the_registry(): void
    {
        $index = $this->app->make(RegistryIndex::class);

        $this->assertSame(
            $this->app->make(RealmRegistry::class),
            $index->routeTo('beam.realm.operator'),
        );
    }

    /**
     * Keys go relative in and absolute out (20 D2). The port's own vocabulary — `all()`, `get()`,
     * `resolve()` — keeps speaking bare realm keys; `keys()` speaks the keyspace.
     */
    public function test_keys_go_relative_in_and_absolute_out(): void
    {
        $registry = $this->app->make(RealmRegistry::class);

        $this->assertSame(
            ['beam.realm.operator', 'beam.realm.tenant', 'beam.realm.site', 'beam.realm.user'],
            array_map(strval(...), $registry->keys()),
        );

        $this->assertSame(['operator', 'tenant', 'site', 'user'], array_keys($registry->all()));
        $this->assertInstanceOf(RealmDefinition::class, $registry->resolve('operator'));
        $this->assertInstanceOf(RealmDefinition::class, $registry->resolve('beam.realm.operator'));
    }

    /**
     * **The LIVE registrant count**, against a booted host rather than a static grep. The census slice
     * priced this registry at 7 registrants; ticket 37's acceptance asks for the measured number and
     * calls any delta a finding, not a fix.
     */
    public function test_the_live_registrant_count_is_measured_not_grepped(): void
    {
        $this->assertCount(4, $this->app->make(RealmRegistry::class)->keys());
    }

    /**
     * A miss now throws the KERNEL's exception, not this package's. That is a consumer-visible break
     * and the recipe records it: a registry whose own `resolve()` threw a package-local
     * `InvalidArgumentException` changes thrown type the moment it delegates to `BasicRegistry`.
     */
    public function test_a_miss_throws_the_kernel_exception_with_provenance(): void
    {
        $this->expectException(RegistryMiss::class);

        $this->app->make(RealmRegistry::class)->resolve('nope');
    }

    /** `tryResolve()` is the null side of the same pair, and the port's `get()` is its older spelling. */
    public function test_try_resolve_and_get_agree_on_a_miss(): void
    {
        $registry = $this->app->make(RealmRegistry::class);

        $this->assertNull($registry->tryResolve('nope'));
        $this->assertNull($registry->get('nope'));
        $this->assertFalse($registry->has('nope'));
    }

    /**
     * Seeded-plus-registered, the shape the archetype is named for: a later contribution supersedes the
     * constructor's seed by key, and the superseded entry leaves `keys()` rather than lingering.
     */
    public function test_a_contribution_supersedes_a_seeded_realm_without_growing_the_keyspace(): void
    {
        $registry = $this->app->make(RealmRegistry::class);

        $registry->register(new RealmDefinition(key: 'site', routeBase: '/preview', guard: null, central: false), by: 'a-capability-package');

        $this->assertCount(4, $registry->keys());
        $this->assertSame('/preview', $registry->resolve('site')->routeBase);
    }
}
