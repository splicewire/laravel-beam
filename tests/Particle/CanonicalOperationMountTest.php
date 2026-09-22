<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Subject\ResolvesOperationSubject;
use Splicewire\Beam\Tests\TestCase;

class CanonicalOperationMountTest extends TestCase
{
    public function test_the_canonical_operation_answers_and_legacy_uri_and_name_do_not_exist(): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'sprockets',
            name: 'spin',
            kind: OperationKind::Write,
            subject: CanonicalOperationSubject::class,
            handle: fn ($subject) => ['data' => ['id' => $subject->id]],
        ));

        Route::prefix('resources')->group(fn () => Particle::ops('sprockets', 'sprockets', 'spin'));
        Route::getRoutes()->refreshNameLookups();

        $this->postJson('/resources/sprockets/7/spin')->assertOk()->assertJsonPath('data.id', '7');
        $this->postJson('/resources/sprockets/7/op/spin')->assertNotFound();
        $this->assertSame('resources/sprockets/{id}/spin', Route::getRoutes()->getByName('sprockets.spin')->uri());
        $this->assertNull(Route::getRoutes()->getByName('sprockets.op.spin'));
    }

    public function test_mount_name_stems_and_explicit_names_remain_authoritative(): void
    {
        Particle::ops('relative/sprockets', 'sprockets', 'spin', ['names' => 'relative.sprockets']);
        Particle::ops('named/sprockets', 'sprockets', 'spin', ['names' => 'ignored', 'name' => 'custom.spin']);
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame('relative/sprockets/{id}/spin', Route::getRoutes()->getByName('relative.sprockets.spin')->uri());
        $this->assertSame('named/sprockets/{id}/spin', Route::getRoutes()->getByName('custom.spin')->uri());
        $this->assertNull(Route::getRoutes()->getByName('ignored.spin'));
        $this->assertNull(Route::getRoutes()->getByName('sprockets.op.spin'));
    }
}

class CanonicalOperationSubject implements ResolvesOperationSubject
{
    public static function pathParameters(): array
    {
        return ['id'];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        return (object) ['id' => (string) $parameters['id']];
    }
}
