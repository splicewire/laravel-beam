<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\RecordWriter;

/**
 * The additive expand step of the particle rename (beam-particle-rename ticket 01): the single-place
 * table-prefix helper applies the config knob, and the new `Particle` vocabulary resolves beside the
 * legacy `Record`/`SchemaRecord` names — nothing renamed, nothing broken.
 */
class ParticleExpandTest extends TestCase
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

    public function test_the_particle_class_aliases_resolve_to_their_record_originals(): void
    {
        // Each new particle name resolves (lazily aliased at boot) to the current record class/interface.
        $this->assertTrue(class_exists('Splicewire\Beam\Models\BeamParticle'));
        $this->assertSame(SchemaRecord::class, (new \ReflectionClass('Splicewire\Beam\Models\BeamParticle'))->getName());

        $this->assertTrue(class_exists('Splicewire\Beam\Write\ParticleWriter'));
        $this->assertSame(RecordWriter::class, (new \ReflectionClass('Splicewire\Beam\Write\ParticleWriter'))->getName());

        $this->assertTrue(interface_exists('Splicewire\Beam\Read\Contracts\ParticleHydrator'));
        $this->assertTrue(class_exists('Splicewire\Beam\Events\BeamParticlePersisted'));
    }

    public function test_the_particle_trait_composes_the_record_trait(): void
    {
        $model = new class extends Model
        {
            use PersistsBeamParticle;
        };

        // The particle-named trait yields the record-trait behaviour (the payload/meta cast seam).
        $this->assertContains(PersistsSchemaRecord::class, class_uses(PersistsBeamParticle::class));
        $this->assertTrue(method_exists($model, 'fillFromSchemaPayload'));
    }
}
