<?php

namespace Splicewire\Beam\Tests\Particle;

use Splicewire\Beam\Beam;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The CONTRACT step of the particle rename (beam-particle-rename ticket 07): the `Particle`/`BeamParticle`
 * vocabulary is the CANONICAL, physically-present class/interface/trait/event names. The retired
 * `Record`/`SchemaRecord` reverse back-compat shim (the external-consumer window) was removed at T09 —
 * `SchemaRecord` now survives only as a durable DB morph-value string (see the versions data path in
 * BeamParticleVersioningTest) and in historical ADR prose.
 */
class ParticleContractTest extends TestCase
{
    public function test_the_table_prefix_helper_applies_the_config_knob_in_one_place(): void
    {
        // Default: beam_.
        $this->assertSame('beam_particles', Beam::table('particles'));
        $this->assertSame('beam_schemas', Beam::table('schemas'));
        $this->assertSame('beam_', Beam::tablePrefix());

        // Retrofit host overrides the ONE knob → every Beam table follows.
        config()->set('beam.core.table_prefix', 'acme_beam_');
        $this->assertSame('acme_beam_particles', Beam::table('particles'));

        // A host that owns the DB from day one can drop the prefix entirely.
        config()->set('beam.core.table_prefix', '');
        $this->assertSame('particles', Beam::table('particles'));
    }

    public function test_the_schema_sources_knob_is_present_for_the_registry_collapse(): void
    {
        $this->assertSame(['db', 'file'], config('beam.core.schema.sources'));
    }

    public function test_the_particle_vocabulary_is_the_canonical_physically_present_symbol(): void
    {
        // The particle names are the real declared classes/interfaces (defined in their own files), NOT
        // aliases — their reflected name IS themselves.
        $this->assertSame(BeamParticle::class, (new \ReflectionClass(BeamParticle::class))->getName());
        $this->assertSame(ParticleWriter::class, (new \ReflectionClass(ParticleWriter::class))->getName());
        $this->assertTrue(interface_exists(ParticleHydrator::class));
        $this->assertSame(PayloadParticleReader::class, (new \ReflectionClass(PayloadParticleReader::class))->getName());
        $this->assertTrue(class_exists(BeamParticlePersisted::class));
    }
}
