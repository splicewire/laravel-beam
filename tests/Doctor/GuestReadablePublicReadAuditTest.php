<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Splicewire\Beam\Doctor\GuestReadablePublicReadAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/** ADR-0230 — the doctor counts a guest-reachable resource that declares no Public Read. */
class GuestReadablePublicReadAuditTest extends TestCase
{
    private function audit(ParticleResourceRegistry $resources): GuestReadablePublicReadAudit
    {
        return new GuestReadablePublicReadAudit(app(Router::class), $resources);
    }

    private function register(ParticleResourceRegistry $resources, ?string $publicRead = null): void
    {
        $resources->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            publicRead: $publicRead,
            frame: false,
        ));
    }

    public function test_a_guest_reachable_resource_without_a_public_read_warns(): void
    {
        $resources = new ParticleResourceRegistry;
        $this->register($resources, publicRead: null);

        Route::get('api/gadgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.index');

        $this->assertSame(['gadgets'], $this->audit($resources)->keys());

        $findings = $this->audit($resources)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('gadgets', $findings[0]->detail);
    }

    public function test_a_declared_public_read_removes_it_from_the_count(): void
    {
        $resources = new ParticleResourceRegistry;
        $this->register($resources, publicRead: GadgetData::class);

        Route::get('api/gadgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.index');

        $audit = $this->audit($resources);

        $this->assertSame([], $audit->keys());
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }

    public function test_an_authenticated_mount_is_not_counted_though_it_declares_none(): void
    {
        $resources = new ParticleResourceRegistry;
        $this->register($resources, publicRead: null);

        Route::middleware('auth:sanctum')->get('api/gadgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.index');

        $audit = $this->audit($resources);

        // It still DECLARES no Public Read (keys lists it), but a guest cannot reach it, so run() passes.
        $this->assertSame(['gadgets'], $audit->keys());
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }

    public function test_an_unauthenticated_generic_frame_socket_counts_the_public_read_less_resource(): void
    {
        $resources = new ParticleResourceRegistry;
        $this->register($resources, publicRead: null);

        Route::get('frame/resources/{resource}', [FrameResourceController::class, 'index'])
            ->name('frame.resources.index');

        $findings = $this->audit($resources)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('gadgets', $findings[0]->detail);
    }

    public function test_no_read_route_is_inconclusive(): void
    {
        $resources = new ParticleResourceRegistry;
        $this->register($resources, publicRead: null);

        // DoctorStatus has no "inconclusive" case: an inconclusive finding is a Pass with conclusive=false
        // (nothing was measured), the laravel-doctor convention.
        $finding = $this->audit($resources)->run()[0];
        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertFalse($finding->conclusive);
    }
}
