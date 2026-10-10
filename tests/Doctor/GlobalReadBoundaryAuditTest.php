<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\GlobalReadBoundaryAudit;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\TestCase;

class GlobalReadBoundaryAuditTest extends TestCase
{
    public function test_it_lists_only_resources_that_explicitly_declare_the_global_boundary(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register(new ParticleResource(key: 'scoped', backing: Gadget::class));
        $resources->register(new ParticleResource(key: 'global-b', backing: Gadget::class, readBoundary: 'global'));
        $resources->register(new ParticleResource(key: 'global-a', backing: Gadget::class, readBoundary: 'global'));

        $audit = new GlobalReadBoundaryAudit($resources);

        $this->assertSame(['global-a', 'global-b'], $audit->keys());
        $findings = $audit->run();
        $this->assertSame([DoctorStatus::Pass], array_map(fn ($finding) => $finding->status, $findings));
        $this->assertStringContainsString('global-a, global-b', $findings[0]->detail);
    }

    public function test_beam_currently_declares_no_global_resource(): void
    {
        $this->assertSame([], GlobalReadBoundaryAudit::forApp()->keys());
    }
}
