<?php

namespace Splicewire\Beam\Tests\Manifest;

use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;
use Splicewire\Beam\Tests\TestCase;

/**
 * The index of indexes (beam-manifest-index): beam-core self-describes its own registries into the
 * {@see ManifestIndex}, and `splicewire:beam:manifests` renders them with their injection-point shape.
 * Proves the seam catalogue is populated foundation-first and that a consumer can append its own — the
 * same downward self-registration the install/doctor manifests use.
 */
class ManifestIndexTest extends TestCase
{
    public function test_beam_core_self_describes_its_registries_into_the_index(): void
    {
        $names = array_map(
            static fn (ManifestDescriptor $d): string => $d->name,
            $this->app->make(ManifestIndex::class)->descriptors(),
        );

        // The install/doctor manifests, the config/attribute/chained registries, and — the point of the
        // restored seams — both particle pipeline chains, are all catalogued.
        $this->assertContains('ManifestIndex', $names);
        $this->assertContains('BeamInstallManifest', $names);
        $this->assertContains('RouteManifestSource', $names);
        $this->assertContains('AdminResourceRegistry', $names);
        $this->assertContains('ParticleWriter (write chain)', $names);
        $this->assertContains('PayloadParticleReader (read chain)', $names);
    }

    public function test_the_index_orders_foundation_first_and_is_idempotent_per_name(): void
    {
        $index = $this->app->make(ManifestIndex::class);

        $orders = array_map(static fn (ManifestDescriptor $d): int => $d->order, $index->descriptors());
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders, 'descriptors render in ascending order');

        // Re-describing an existing name replaces rather than duplicates (a provider booting twice).
        $before = count($index->descriptors());
        $index->describe(new ManifestDescriptor(
            name: 'ManifestIndex', of: 'x', seam: ManifestSeam::SingletonAccumulator,
            arity: ManifestArity::RunAll, registerHint: 'x', where: 'x',
        ));
        $this->assertCount($before, $index->descriptors());
    }

    public function test_arity_is_declared_orthogonally_to_seam(): void
    {
        $byName = [];
        foreach ($this->app->make(ManifestIndex::class)->descriptors() as $d) {
            $byName[$d->name] = $d;
        }

        // The pipeline chains COMPOSE many stages; the keyed registries resolve ONE; the accumulators run
        // ALL — the axis the Registry/Manifest naming split was really tracking.
        $this->assertSame(ManifestArity::ComposeMany, $byName['ParticleWriter (write chain)']->arity);
        $this->assertSame(ManifestArity::ComposeMany, $byName['PayloadParticleReader (read chain)']->arity);
        $this->assertSame(ManifestArity::PickOne, $byName['RealmRegistry']->arity);
        $this->assertSame(ManifestArity::RunAll, $byName['BeamInstallManifest']->arity);

        // Arity is independent of seam: two attribute-scan seams, but resolution is pick-one, while two
        // singleton-accumulators resolve run-all — the suffix never told you that; the arity column does.
        $this->assertSame(ManifestSeam::AttributeScan, $byName['RealmRegistry']->seam);
        $this->assertSame(ManifestSeam::SingletonAccumulator, $byName['BeamInstallManifest']->seam);
    }

    public function test_the_command_renders_the_catalogue_and_both_axis_legends(): void
    {
        $this->artisan('splicewire:beam:manifests')
            ->assertSuccessful()
            ->expectsOutputToContain('ParticleWriter (write chain)')
            ->expectsOutputToContain('pipeline-chain')
            ->expectsOutputToContain('compose-many')
            ->expectsOutputToContain('Resolving, by arity');
    }

    public function test_the_seam_filter_narrows_to_one_injection_shape(): void
    {
        $this->artisan('splicewire:beam:manifests', ['--seam' => 'pipeline-chain'])
            ->assertSuccessful()
            ->expectsOutputToContain('PayloadParticleReader (read chain)')
            ->doesntExpectOutputToContain('BeamInstallManifest');
    }

    public function test_an_unknown_seam_fails_cleanly(): void
    {
        $this->artisan('splicewire:beam:manifests', ['--seam' => 'nope'])
            ->assertFailed();
    }
}
