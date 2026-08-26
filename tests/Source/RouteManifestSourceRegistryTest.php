<?php

namespace Splicewire\Beam\Tests\Source;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Source\RouteManifestSourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * `RouteManifestSource` is an interface over the objectless `beam.client.sources` array, so
 * registry-kernel ticket 21 had no class to declare `#[IsRegistry]` on and left this beam-core registry
 * `deferred` — the one descriptor of nineteen with no home. Ticket 25's adapter closes the deferral;
 * these pin that it did so without moving the config key or changing what a consumer reads.
 */
class RouteManifestSourceRegistryTest extends TestCase
{
    public function test_it_is_bound_as_a_singleton_by_beam_core(): void
    {
        $this->assertSame(
            app(RouteManifestSourceRegistry::class),
            app(RouteManifestSourceRegistry::class),
        );
    }

    /**
     * DECLARING and INDEXING are two acts (registry-kernel 21 D1). Ticket 25 landed the declaration;
     * ticket 37 lands the act that makes `beam.client.sources` reachable through the index.
     */
    public function test_it_is_described_into_the_shared_index(): void
    {
        $this->assertSame(app(RegistryIndex::class), app(RegistryIndex::class));

        $keys = array_map(strval(...), app(RegistryIndex::class)->keys());

        $this->assertContains('beam.client.sources', $keys);
    }

    public function test_it_declares_itself_so_the_gate_can_read_it(): void
    {
        $declaration = IsRegistry::of(RouteManifestSourceRegistry::class);

        $this->assertNotNull($declaration);
        $this->assertSame('beam.client.sources', $declaration->root);
        $this->assertSame([RegistryArity::PickOne], $declaration->arity);
    }

    public function test_it_reads_the_default_tenant_binding_off_the_real_config_key(): void
    {
        $registry = app(RouteManifestSourceRegistry::class);

        // The shipped default: a fresh satellite generates from its mounted `#[ParticleResource]`
        // routes with no further wiring.
        $this->assertSame(
            ParticleRouteManifestSource::class,
            $registry->resolve('defaults'),
        );

        $this->assertSame(
            ['beam.client.sources.defaults'],
            array_map(strval(...), $registry->keys()),
        );
    }

    public function test_an_unbound_operator_realm_reads_as_absent_not_as_a_null_entry(): void
    {
        // `env('BEAM_CLIENT_OPERATOR_SOURCE')` with nothing set — which GenerateClientSdkCommand
        // already reads as "no operator tier", emitting an empty operatorDefaults map and no hooks.
        config(['beam.client.sources.operator' => null]);

        $registry = app(RouteManifestSourceRegistry::class);

        $this->assertFalse($registry->has('operator'));
        $this->assertNull($registry->tryResolve('operator'));
    }

    public function test_registering_a_realm_lands_where_the_consumers_read_it(): void
    {
        app(RouteManifestSourceRegistry::class)
            ->register('operator', 'App\Sources\TowerOperatorSource');

        // GenerateClientSdkCommand, SdkReturnsCoverageAudit and SdkReturnsTypeScriptResolutionAudit
        // all read this path directly, and the adapter must leave it exactly as they expect.
        $this->assertSame('App\Sources\TowerOperatorSource', config('beam.client.sources.operator'));
    }
}
