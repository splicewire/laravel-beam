<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\ParticleCapabilityDisagreementAudit;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Splicewire\Beam\Tests\Particle\ReportStreamOnlyBacking;
use Splicewire\Beam\Tests\TestCase;

/**
 * The standing half of the registry reading surface. Advisory permanently — which resources a host
 * registered is a fact about the host, and refusing to boot over an affordance nobody calls trades a
 * work-list line for a dead deployment (AGENTS.md, and `EventCatalogPrefixAudit` is the instance that
 * learned it by taking `~/Herd/tower` off the air).
 */
class ParticleCapabilityDisagreementAuditTest extends TestCase
{
    public function test_an_empty_registry_passes_rather_than_reporting_nothing(): void
    {
        $findings = $this->audit(new ParticleResourceRegistry)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(ParticleCapabilityDisagreementAudit::CHECK, $findings[0]->check);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_declarations_within_their_backings_capability_pass(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));

        $findings = $this->audit($registry)->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 registered particle resource', $findings[0]->detail);
    }

    /**
     * A warning, never a failure — and it names the resource and the claim, because a count with no
     * subject is a number nobody can act on.
     */
    public function test_a_disagreement_warns_and_names_the_resource_and_the_claim(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));
        $registry->register(new ParticleResource(
            key: 'feed',
            backing: ReportStreamOnlyBacking::class,
            readOnly: true,
            showable: false,
        ));

        $findings = $this->audit($registry)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('1 of 2 registered particle resources', $findings[0]->detail);
        $this->assertStringContainsString('[feed] filterable but backing has no QueriesRecords', $findings[0]->detail);
        $this->assertStringNotContainsString('widgets', $findings[0]->detail);
    }

    public function test_it_is_registered_into_the_doctor_manifest_so_the_surgeon_sweep_discovers_it(): void
    {
        $registered = array_map(
            fn ($registration) => $registration->audit,
            $this->app->make(BeamDoctorManifest::class)->registrations(),
        );

        $this->assertContains(ParticleCapabilityDisagreementAudit::class, $registered);
    }

    private function audit(ParticleResourceRegistry $registry): ParticleCapabilityDisagreementAudit
    {
        return new ParticleCapabilityDisagreementAudit(new ResourceRegistryReport($registry));
    }
}
