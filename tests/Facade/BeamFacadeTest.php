<?php

namespace Splicewire\Beam\Tests\Facade;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\BeamManager;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The compatibility check the whole beam-facade sweep rests on (ticket 06 done-condition 4).
 *
 * ~289 call sites across 16 repos are about to have their `use` line moved from the old static helper to
 * {@see Beam}. That move is only safe if `Beam::table()` / `Beam::tablePrefix()` behave IDENTICALLY through
 * the facade — same syntax, same return, same default `beam_`, same `''`-override for a retrofit host. This
 * asserts that rather than leaving it to inspection.
 *
 * It also covers the two NEW members ({@see BeamManager::tableFor()}, {@see BeamManager::write()}), the
 * `scoped()` binding, and — since ticket 18 — that the deprecated static bridge stays deleted.
 */
class BeamFacadeTest extends TestCase
{
    public function test_table_applies_the_default_prefix(): void
    {
        $this->assertSame('beam_particles', Beam::table('particles'));
        $this->assertSame('beam_', Beam::tablePrefix());
    }

    public function test_table_honours_a_retrofit_hosts_empty_prefix(): void
    {
        config()->set('beam.core.table_prefix', '');

        $this->assertSame('', Beam::tablePrefix());
        $this->assertSame('particles', Beam::table('particles'));
    }

    public function test_table_honours_a_retrofit_hosts_custom_prefix(): void
    {
        config()->set('beam.core.table_prefix', 'sw_');

        $this->assertSame('sw_particles', Beam::table('particles'));
    }

    /** Lookup-only: the prefix is re-read per call, never cached on the instance. */
    public function test_the_prefix_is_re_read_on_every_call(): void
    {
        $this->assertSame('beam_particles', Beam::table('particles'));

        config()->set('beam.core.table_prefix', 'later_');

        $this->assertSame('later_particles', Beam::table('particles'));
    }

    public function test_the_model_table_seam_still_resolves_through_the_facade(): void
    {
        config()->set('beam.core.table_prefix', 'host_');

        $this->assertSame('host_particles', (new BeamParticle)->getTable());
    }

    public function test_table_for_prefers_the_sibling_config_key(): void
    {
        config()->set('beam.threads.tables.threads', 'custom_threads');

        $this->assertSame('custom_threads', Beam::tableFor('beam.threads.tables.threads', 'threads'));
    }

    public function test_table_for_falls_back_to_the_prefixed_stem(): void
    {
        $this->assertSame('beam_threads', Beam::tableFor('beam.threads.tables.threads', 'threads'));

        config()->set('beam.core.table_prefix', '');

        $this->assertSame('threads', Beam::tableFor('beam.threads.tables.threads', 'threads'));
    }

    public function test_write_forwards_to_the_particle_writer_and_returns_the_persisted_model(): void
    {
        $writer = new class extends ParticleWriter
        {
            public array $seen = [];

            public function __construct() {}

            public function write(Model|string $target, array $payload, mixed $actor = null, ?\Closure $after = null, bool $emit = true): Model
            {
                $this->seen = compact('target', 'payload', 'actor', 'emit');
                $this->seen['after'] = $after;

                return $target instanceof Model ? $target : new $target;
            }
        };

        $this->app->instance(ParticleWriter::class, $writer);

        $hook = fn (Model $m) => null;
        $model = Beam::write(BeamParticle::class, ['a' => 1], after: $hook);

        $this->assertInstanceOf(BeamParticle::class, $model);
        $this->assertSame(BeamParticle::class, $writer->seen['target']);
        $this->assertSame(['a' => 1], $writer->seen['payload']);
        $this->assertSame($hook, $writer->seen['after'], 'a named `after:` argument must survive the variadic forward');
        $this->assertTrue($writer->seen['emit']);
    }

    /**
     * The writer is bound `bind()`, not `singleton()`, so the instance must resolve it PER CALL — holding
     * one would pin a single writer for the scope and defeat per-call gate rebinding.
     */
    public function test_write_resolves_the_writer_per_call(): void
    {
        $resolved = 0;
        $this->app->bind(ParticleWriter::class, function ($app) use (&$resolved) {
            $resolved++;

            return new ParticleWriter(
                $app->make(WriteGate::class),
                $app->make(SchemaTargetResolver::class),
                new AcceptanceGate,
                $app->make(Dispatcher::class),
            );
        });

        $instance = $this->app->make(BeamManager::class);
        $this->assertSame(0, $resolved, 'the instance must not resolve the writer at construction');

        try {
            $instance->write(BeamParticle::class, []);
        } catch (\Throwable) {
            // The gate/validation outcome is ParticleWriterTest's business; only the resolve count matters here.
        }

        try {
            $instance->write(BeamParticle::class, []);
        } catch (\Throwable) {
        }

        $this->assertSame(2, $resolved, 'each write() must resolve a fresh writer');
    }

    public function test_the_facade_resolves_the_scoped_instance(): void
    {
        $this->assertInstanceOf(BeamManager::class, Beam::getFacadeRoot());
        $this->assertSame($this->app->make(BeamManager::class), Beam::getFacadeRoot());
    }

    /** Free with the facade, and the reason ticket 11 ruled out a bespoke `Beam::fake()`. */
    public function test_the_facade_is_swappable_for_interception(): void
    {
        Beam::shouldReceive('table')->once()->with('particles')->andReturn('stub_particles');

        $this->assertSame('stub_particles', Beam::table('particles'));
    }

    /**
     * The cutover (ticket 18): the deprecated static bridge is gone, and staying gone is the point.
     * Ticket 19's conformance audits watch host code; nothing there can see whether the class this
     * package once shipped came back, so the guarantee is asserted here, where it is owned.
     */
    public function test_the_deprecated_static_bridge_no_longer_exists(): void
    {
        $this->assertFalse(
            class_exists(\Splicewire\Beam\Beam::class),
            'The static bridge was deleted at ticket 18 — the facade is the only Beam:: entry point.'
        );
    }
}
