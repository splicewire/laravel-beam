<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Doctor\ScopedReadAuthenticationAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetPolicy;
use Splicewire\Beam\Tests\TestCase;

class ScopedReadAuthenticationAuditTest extends TestCase
{
    public function test_a_scoped_resource_without_authority_fails_when_its_index_is_public(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: fn ($query) => $query->where('user_id', 1),
            frame: false,
        ));

        Route::get('api/gadgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.index');

        $audit = new ScopedReadAuthenticationAudit(
            app(Router::class),
            $resources,
            ResourceReadGuard::forApp(),
        );

        $this->assertSame(['gadgets'], $audit->keys());

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('[gadgets]', $findings[0]->detail);
        $this->assertStringContainsString('GET api/gadgets', $findings[0]->detail);
    }

    public function test_authentication_makes_the_same_scoped_mount_safe_without_hiding_it_from_the_census(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: fn ($query) => $query->where('user_id', 1),
            frame: false,
        ));

        Route::middleware('auth:sanctum')->get('api/gadgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.index');

        $audit = new ScopedReadAuthenticationAudit(
            app(Router::class),
            $resources,
            ResourceReadGuard::forApp(),
        );

        $this->assertSame(['gadgets'], $audit->keys());
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }

    public function test_an_unauthenticated_record_read_is_not_hidden_by_the_absence_of_an_index(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: fn ($query) => $query->where('user_id', 1),
            frame: false,
        ));

        Route::get('api/gadgets/{gadget}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.show');

        $audit = new ScopedReadAuthenticationAudit(
            app(Router::class),
            $resources,
            ResourceReadGuard::forApp(),
        );

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('[gadgets]', $findings[0]->detail);
        $this->assertStringContainsString('GET api/gadgets/{gadget}', $findings[0]->detail);
    }

    public function test_an_unauthenticated_generic_frame_socket_fails_when_the_census_is_not_empty(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: fn ($query) => $query->where('user_id', 1),
            frame: false,
        ));

        Route::get('frame/resources/{resource}', [FrameResourceController::class, 'index'])
            ->name('frame.resources.index');

        $audit = new ScopedReadAuthenticationAudit(
            app(Router::class),
            $resources,
            ResourceReadGuard::forApp(),
        );

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('generic Frame resource socket', $findings[0]->detail);
        $this->assertStringContainsString('GET frame/resources/{resource}', $findings[0]->detail);
    }

    public function test_a_model_policy_is_authority_and_removes_the_resource_from_the_stopgap_population(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: fn ($query) => $query->where('user_id', 1),
            frame: false,
        ));
        Gate::policy(Gadget::class, GadgetPolicy::class);

        Route::get('api/gadgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.index');

        $audit = new ScopedReadAuthenticationAudit(
            app(Router::class),
            $resources,
            ResourceReadGuard::forApp(),
        );

        $this->assertSame([], $audit->keys());
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }
}
