<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The CONTRACT step of the particle rename (beam-particle-rename ticket 07): the `Particle`/`BeamParticle`
 * vocabulary is now CANONICAL — the physically-present class/interface/trait/event names — and the retired
 * `Record`/`SchemaRecord` names resolve back to them through the thin REVERSE back-compat shim, for the
 * external window (until T09 repoints satellites). Inverts T01's forward-alias expand step.
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

    public function test_the_retired_record_names_resolve_back_to_the_particle_classes(): void
    {
        // Each retired name resolves (lazily aliased at boot) to its new particle class/interface, so an
        // external consumer still type-hinting the old FQCN keeps resolving until T09.
        $this->assertSame(BeamParticle::class, (new \ReflectionClass('Splicewire\Beam\Models\SchemaRecord'))->getName());
        $this->assertSame(ParticleWriter::class, (new \ReflectionClass('Splicewire\Beam\Write\RecordWriter'))->getName());
        $this->assertTrue(interface_exists('Splicewire\Beam\Read\Contracts\RecordHydrator'));
        $this->assertSame(PayloadParticleReader::class, (new \ReflectionClass('Splicewire\Beam\Read\PayloadRecordReader'))->getName());
        $this->assertSame(BeamParticlePersisted::class, (new \ReflectionClass('Splicewire\Beam\Events\SchemaRecordPersisted'))->getName());
    }

    public function test_the_retired_trait_name_composes_the_canonical_particle_trait(): void
    {
        $model = new class extends Model
        {
            use PersistsSchemaRecord;
        };

        // The retired-named back-compat trait yields the canonical particle-trait behaviour (the
        // payload/meta cast seam still initializes through class_uses_recursive).
        $this->assertContains(PersistsBeamParticle::class, class_uses(PersistsSchemaRecord::class));
        $this->assertTrue(method_exists($model, 'fillFromSchemaPayload'));
    }
}
