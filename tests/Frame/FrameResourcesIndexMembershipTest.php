<?php

namespace Splicewire\Beam\Tests\Frame;

use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryIndex;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * Registry-kernel ticket 77 — beam ATTACHES its adapter as a member of `frame.resources` instead of
 * aliasing the port onto it.
 *
 * The tripwire first, twice over. Testbench does not auto-discover, so an index that is not shared is
 * an index every assertion below would pass over (27 D3); and this suite's whole subject is two
 * objects that used to differ, so proving they are one is the first thing to prove.
 */
class FrameResourcesIndexMembershipTest extends TestCase
{
    /**
     * Frame's provider, added for THIS class only.
     *
     * Beam's default list deliberately omits it — ADR-0082's layering law is that beam boots without the
     * frame rung, and `BeamBootTest` asserts it. But `frame.resources` is FRAME's root and frame's
     * provider is what binds the port onto the index, so a suite asking beam whether it joined that index
     * has to boot the rung it is joining. At a real host this is not a choice: `laravel-frame` is a
     * composer dependency and Laravel auto-discovers its provider.
     *
     * AGENTS.md's testbench rule is the reason it is spelled out rather than assumed — an omitted
     * provider here does not fail loudly, it hands back a container that cannot answer, and the natural
     * reading of that error is "beam is not wired" rather than "the harness is not".
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    public function test_the_container_and_the_index_answer_for_frame_resources_with_one_object(): void
    {
        $this->assertSame(app(RegistryIndex::class), app(RegistryIndex::class));

        $port = app(ResourceRegistry::class);

        // Ticket 77's premise, inverted. It measured `ownerOf(...) === app(port)` as FALSE at
        // `~/Herd/splicewire-app`: the port was beam's adapter holding 53 resources and the root's owner
        // was a freshly-constructed, empty `InMemoryResourceRegistry`.
        $this->assertInstanceOf(CompositeResourceRegistry::class, $port);
        $this->assertSame($port, app(RegistryIndex::class)->ownerOf(Key::of('frame.resources')));
    }

    public function test_beams_adapter_is_a_membe_r_and_the_root_never_reads_empty(): void
    {
        $port = app(ResourceRegistry::class);

        $keys = array_map(strval(...), $port->keys());

        $this->assertContains('frame.resources.beam', $keys);
        $this->assertNotSame([], $keys);
        $this->assertInstanceOf(ParticleResourceRegistryAdapter::class, $port->resolve('beam'));

        // The member is the SAME singleton the container hands out — a closure that constructed would
        // hand back a fresh forwarder per read and the boot-time discovery would be invisible.
        $this->assertSame(app(ParticleResourceRegistryAdapter::class), $port->resolve('beam'));
    }

    /**
     * The one thing ticket 77 forbids flattening: the port is a PROJECTION and is deliberately narrower
     * than the store it forwards to. A REST-only particle resource genuinely exists at
     * `beam.particle.resources` and has no frame projection, so `has()` is `false` and
     * `unfiltered()->has()` is `true` — through the adapter, and unchanged through the index.
     */
    public function test_the_projection_stays_narrower_than_the_store_through_the_index(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'rest-only-probe',
            backing: BeamSchema::class,
            label: '',
        ));

        $adapter = app(ParticleResourceRegistryAdapter::class);

        $this->assertFalse($adapter->has('rest-only-probe'));
        $this->assertTrue($adapter->unfiltered()->has('rest-only-probe'));

        $port = app(ResourceRegistry::class);

        $this->assertFalse($port->has('rest-only-probe'));
        $this->assertNull($port->find('rest-only-probe'));
        $this->assertTrue($port->unfiltered()->has('rest-only-probe'));
    }

    public function test_a_framed_resource_routes_through_the_index_to_beams_projection(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'framed-probe',
            backing: BeamSchema::class,
            data: 'Acme\\Data\\FramedProbeData',
            label: 'Framed Probe',
        ));

        $port = app(ResourceRegistry::class);

        $this->assertTrue($port->has('framed-probe'));
        $this->assertSame('framed-probe', $port->get('framed-probe')->key);
        $this->assertContains('framed-probe', array_map(
            fn ($definition): string => $definition->key,
            $port->all(),
        ));
    }
}
