<?php

namespace Splicewire\Beam\Tests\Frame;

use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Schemastud\Frame\Contracts\ResourceFilterProvider;
use Schemastud\Frame\Data\FilterOptionsResponseData;
use Schemastud\Frame\Data\FilterSchemaResponseData;
use Schemastud\Frame\Data\FilterVariantsData;
use Schemastud\Frame\Data\FilterVariantsResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Filters\BeamResourceFilterProvider;
use Splicewire\Beam\Filters\Data\SavedFilterData;
use Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver;
use Splicewire\Beam\Frame\UnknownFrameResource;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * A stand-in for the estate's one real bespoke handler (tower's `ConduitResourceHandler`) — present only
 * to prove the `handler:` slot is honoured and container-resolved.
 */
class BespokeTestFrameHandler implements FrameResourceHandler
{
    public function index(ResourceDefinition $definition, array $params): array
    {
        return [];
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        return [];
    }

    public function store(ResourceDefinition $definition, array $input): array
    {
        return [];
    }

    public function update(ResourceDefinition $definition, string $id, array $input): array
    {
        return [];
    }

    public function destroy(ResourceDefinition $definition, string $id): void {}
}

/**
 * Beam supplies resource handlers and per-resource filter capabilities; hosts can override either.
 */
class DefaultFrameBindingsTest extends TestCase
{
    public function test_beam_binds_the_default_particle_resolver_ootb(): void
    {
        $resolver = $this->app->make(FrameResourceHandlerResolver::class);

        $this->assertInstanceOf(DefaultParticleResourceHandlerResolver::class, $resolver);
    }

    public function test_a_declared_resource_with_no_handler_slot_gets_the_generic_beam_handler(): void
    {
        $this->declareResource('operator-customers');

        $resolver = $this->app->make(FrameResourceHandlerResolver::class);

        $handler = $resolver->handlerFor('operator-customers');

        $this->assertInstanceOf(FrameResourceHandler::class, $handler);
        $this->assertInstanceOf(ParticleFrameResourceHandler::class, $handler);
    }

    public function test_a_resource_may_declare_its_own_bespoke_handler(): void
    {
        $this->declareResource('conduit-likes', handler: BespokeTestFrameHandler::class);

        $resolver = $this->app->make(FrameResourceHandlerResolver::class);

        $this->assertInstanceOf(BespokeTestFrameHandler::class, $resolver->handlerFor('conduit-likes'));
    }

    /**
     * The whole point of the seam: an UNREGISTERED key must be distinguishable from a registered one.
     *
     * The predecessor was a constant map — it answered for every key, so "not registered on this host"
     * and "registered, wants the generic handler" were the same answer, and the host-side table it
     * replaced had the identical `?? DefaultHandler` tail. Under that tail nine of the flagship's
     * resources rode the wrong handler undetected. A read that cannot miss cannot report a miss.
     */
    public function test_an_unregistered_key_is_a_miss_rather_than_a_silent_default(): void
    {
        $resolver = $this->app->make(FrameResourceHandlerResolver::class);

        $this->expectException(UnknownFrameResource::class);

        $resolver->handlerFor('no-such-resource');
    }

    /**
     * The nullable half of the miss pair — a caller that wants a default says so at its own call site.
     */
    public function test_the_nullable_half_reports_absence_without_throwing(): void
    {
        $this->declareResource('operator-customers');

        /** @var DefaultParticleResourceHandlerResolver $resolver */
        $resolver = $this->app->make(FrameResourceHandlerResolver::class);

        $this->assertNull($resolver->handlerIfDeclared('no-such-resource'));
        $this->assertInstanceOf(
            ParticleFrameResourceHandler::class,
            $resolver->handlerIfDeclared('operator-customers'),
        );
    }

    /**
     * Register a bare declaration under $key — enough for the resolver, which reads only the handler slot.
     */
    private function declareResource(string $key, ?string $handler = null): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: BeamSchema::class,
            handler: $handler,
        ));
    }

    public function test_beam_projects_its_filter_provider_by_default(): void
    {
        $resource = new ParticleResource(key: 'subjects', backing: BeamSchema::class, data: SavedFilterData::class);
        $this->assertSame(BeamResourceFilterProvider::class, $resource->toResourceDefinition()->filterProvider);
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

    public function test_a_resource_can_override_the_projected_filter_provider(): void
    {
        $resource = AttributedParticleDiscovery::resourceFromAttribute(DeclaredProviderData::class);
        $this->assertSame(DeclaredFilterProvider::class, $resource->toResourceDefinition()->filterProvider);
    }
}

#[\Splicewire\Beam\Particle\Attributes\ParticleResource(
    key: 'declared-provider', backing: BeamSchema::class, data: DeclaredProviderData::class,
    input: false, frame: true, filterProvider: DeclaredFilterProvider::class,
)]
class DeclaredProviderData extends Data {}

class DeclaredFilterProvider implements ResourceFilterProvider
{
    public function schema(ResourceDefinition $resource, ?string $variant = null): FilterSchemaResponseData
    {
        return new FilterSchemaResponseData(['type' => 'object', 'properties' => []]);
    }

    public function options(ResourceDefinition $resource, string $ref, ?string $search = null): FilterOptionsResponseData
    {
        return new FilterOptionsResponseData([]);
    }

    public function variants(ResourceDefinition $resource): FilterVariantsResponseData
    {
        return new FilterVariantsResponseData(new FilterVariantsData($resource->key, []));
    }
}
