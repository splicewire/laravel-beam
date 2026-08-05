<?php

namespace Splicewire\Beam\Tests\Frame;

use Schemastud\Frame\Contracts\FrameFilterProvider;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver;
use Splicewire\Beam\Frame\NullFrameFilterProvider;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-ux-uplift ticket 09: the two Frame host-facing seams ship OOTB from BeamServiceProvider, so a fresh
 * host gets a working operator area with no `app/Frame/` glue — AND the host can still override either.
 */
class DefaultFrameBindingsTest extends TestCase
{
    public function test_beam_binds_the_default_particle_resolver_ootb(): void
    {
        $resolver = $this->app->make(FrameResourceHandlerResolver::class);

        $this->assertInstanceOf(DefaultParticleResourceHandlerResolver::class, $resolver);
    }

    public function test_the_default_resolver_maps_every_key_to_the_one_beam_handler(): void
    {
        $resolver = $this->app->make(FrameResourceHandlerResolver::class);

        // A constant map: any resource key resolves to the singular beam-native handler.
        $handler = $resolver->handlerFor('operator-customers');

        $this->assertInstanceOf(FrameResourceHandler::class, $handler);
        $this->assertInstanceOf(ParticleFrameResourceHandler::class, $handler);
        $this->assertSame($handler, $resolver->handlerFor('anything-else'));
    }

    public function test_beam_binds_the_null_filter_provider_ootb(): void
    {
        $provider = $this->app->make(FrameFilterProvider::class);

        $this->assertInstanceOf(NullFrameFilterProvider::class, $provider);
        $this->assertSame(['properties' => []], $provider->for('operator-customers'));
        $this->assertSame([], $provider->options('anything'));
    }

    public function test_a_host_resolver_binding_wins(): void
    {
        // Simulate a host app provider registering AFTER beam-core (last-binding-wins). The host resolver
        // still delegates to the beam handler, but is a DISTINCT resolver type — proving the host's binding,
        // not beam's default, is what the socket resolves.
        $hostHandler = $this->app->make(ParticleFrameResourceHandler::class);
        $hostResolver = new class($hostHandler) implements FrameResourceHandlerResolver
        {
            public function __construct(private FrameResourceHandler $handler) {}

            public function handlerFor(string $resource): FrameResourceHandler
            {
                return $this->handler;
            }
        };

        $this->app->bind(FrameResourceHandlerResolver::class, fn () => $hostResolver);

        $resolved = $this->app->make(FrameResourceHandlerResolver::class);

        $this->assertSame($hostResolver, $resolved);
        $this->assertNotInstanceOf(DefaultParticleResourceHandlerResolver::class, $resolved);
    }

    public function test_a_host_filter_provider_binding_wins(): void
    {
        $hostProvider = new class implements FrameFilterProvider
        {
            public function for(string $resource): array
            {
                return ['properties' => ['status' => ['type' => 'string']]];
            }

            public function options(string $ref): array
            {
                return [['value' => 'open', 'label' => 'Open']];
            }
        };

        $this->app->bind(FrameFilterProvider::class, fn () => $hostProvider);

        $resolved = $this->app->make(FrameFilterProvider::class);

        $this->assertSame($hostProvider, $resolved);
        $this->assertNotInstanceOf(NullFrameFilterProvider::class, $resolved);
        $this->assertArrayHasKey('status', $resolved->for('x')['properties']);
    }
}
