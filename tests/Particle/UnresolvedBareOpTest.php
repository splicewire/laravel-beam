<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * tutorial-dx 05: a bare `->ops(['name'])` mounts "an op already registered elsewhere". When nothing registered it, the
 * mount used to succeed anyway: `route:list` listed `POST api/calculators/{id}/resolve` and only the request failed, a
 * 500 with "No particle operation [resolve] on resource [calculators]" (ParticleOperationRegistry::get()). It now fails
 * AT REGISTRATION, naming the fix.
 */
class UnresolvedBareOpTest extends TestCase
{
    public function test_a_bare_op_name_nothing_registered_fails_at_registration_naming_the_fix(): void
    {
        try {
            Particle::ops('calculators', 'calculators', ['resolve']);
            $this->fail('an unresolved bare op name must not mount');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('No particle operation [resolve] on resource [calculators] to mount', $e->getMessage());
            // The fix, named: register the op first, or pass what registers it.
            $this->assertStringContainsString('#[ParticleOp]', $e->getMessage());
            $this->assertStringContainsString('beam.core.particle', $e->getMessage());
        }

        $this->assertNull(Route::getRoutes()->getByName('calculators.resolve'), 'nothing was mounted');
    }

    public function test_the_fluent_mount_fails_the_same_way(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No particle operation [resolve] on resource [calculators] to mount');

        Particle::mount('calculators')->ops(['resolve'])->register();
    }

    public function test_a_bare_op_name_that_IS_registered_still_mounts(): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'calculators',
            name: 'resolve',
            kind: OperationKind::Task,
            model: 'App\\Models\\Calculator',
            handle: fn () => null,
        ));

        Particle::ops('calculators', 'calculators', ['resolve']);
        Route::getRoutes()->refreshNameLookups();

        $this->assertNotNull(Route::getRoutes()->getByName('calculators.resolve'));
    }
}
