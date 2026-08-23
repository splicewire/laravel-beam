<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The generic particle REST surface, promoted from splicewire-app into beam-core (ADR-0116). This asserts
 * the base is self-sufficient in a headless beam app: the two declaration registries resolve as container
 * singletons, the neutral {@see ArrayResponseEnvelope} is the default response seam, and its wire shape
 * (`{ data }` / `{ data, limit, offset, total }`) matches the common envelope a host adapter also emits.
 */
class ParticleSurfaceTest extends TestCase
{
    public function test_the_declaration_registries_are_container_singletons(): void
    {
        $resources = $this->app->make(ParticleResourceRegistry::class);
        $operations = $this->app->make(ParticleOperationRegistry::class);

        $this->assertSame($resources, $this->app->make(ParticleResourceRegistry::class));
        $this->assertSame($operations, $this->app->make(ParticleOperationRegistry::class));
    }

    public function test_a_resource_registered_once_is_retrievable_by_key(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->register(new ParticleResource(key: 'widget', backing: 'App\\Models\\Widget'));

        $this->assertTrue($registry->has('widget'));
        $this->assertSame('widget', $registry->get('widget')->key);
    }

    public function test_an_operation_keys_by_resource_and_name(): void
    {
        $registry = $this->app->make(ParticleOperationRegistry::class);
        $op = new ParticleOperation(
            resource: 'batch',
            name: 'run',
            kind: OperationKind::Task,
            model: 'App\\Models\\Batch',
            handle: fn () => null,
        );
        $registry->register($op);

        $this->assertSame('batch:run', $op->key());
        $this->assertSame($op, $registry->get('batch', 'run'));
    }

    public function test_the_default_response_envelope_is_the_neutral_array_adapter(): void
    {
        $this->assertInstanceOf(ArrayResponseEnvelope::class, $this->app->make(ResponseEnvelope::class));
    }

    public function test_the_neutral_envelope_wraps_a_single_record_as_data(): void
    {
        $envelope = new ArrayResponseEnvelope;

        $response = $envelope->item(['id' => 7])->toResponse(Request::create('/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['id' => 7]], $response->getData(true));
    }

    public function test_the_neutral_envelope_marks_a_created_record_201(): void
    {
        $envelope = new ArrayResponseEnvelope;

        $response = $envelope->created(['id' => 7])->toResponse(Request::create('/'));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['data' => ['id' => 7]], $response->getData(true));
    }

    public function test_the_neutral_envelope_shapes_a_page_as_data_limit_offset_total(): void
    {
        $envelope = new ArrayResponseEnvelope;
        $page = new LengthAwarePaginator([['id' => 1], ['id' => 2]], total: 12, perPage: 2, currentPage: 3);

        $response = $envelope->paginated($page)->toResponse(Request::create('/'));

        $this->assertSame([
            'data' => [['id' => 1], ['id' => 2]],
            'limit' => 2,
            'offset' => 4,
            'total' => 12,
        ], $response->getData(true));
    }

    public function test_the_promoted_surface_classes_carry_their_route_default_consts(): void
    {
        // The sibling Scribe strategy keys off these consts; they are the wire between mount + controller.
        $this->assertSame('_particle', ParticleController::RESOURCE);
        $this->assertSame('_particle_op_resource', ParticleOperationController::RESOURCE);
        $this->assertSame('_particle_op_name', ParticleOperationController::NAME);
    }
}
