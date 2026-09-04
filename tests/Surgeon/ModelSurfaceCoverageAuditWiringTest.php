<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Surgeon\ModelSurfaceCoverageAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The WIRING control for {@see ModelSurfaceCoverageAudit}, separate from
 * {@see ModelSurfaceCoverageAuditTest} because a unit test of an audit's logic cannot see any of what
 * is asserted here — and every failure below is one this estate has paid for at least once.
 *
 * Three claims:
 *
 *  1. **It is registered.** An audit nobody registers is a class that reports nothing, and its unit
 *     tests stay green forever.
 *  2. **It is CONTAINER-RESOLVABLE with no arguments.** The doctor runner resolves audits from the
 *     container, so an audit whose collaborators are not autowirable is a boot-time error in every
 *     host and is invisible to a test that hand-constructs it — which is exactly what the unit test
 *     does, for every one of its cases.
 *  3. **It is ADVISORY.** `gate: false` is the whole posture argument (see the registration comment in
 *     `BeamServiceProvider`), and a posture that lives only in prose is one edit from being reversed
 *     by someone who reads the audit as an invariant. A check whose answer is a fact about the host
 *     took `~/Herd/tower` off the air when this was got backwards.
 */
class ModelSurfaceCoverageAuditWiringTest extends TestCase
{
    private function registration(): ?DoctorRegistration
    {
        foreach ($this->app->make(BeamDoctorManifest::class)->registrations() as $registration) {
            if ($registration->audit === ModelSurfaceCoverageAudit::class) {
                return $registration;
            }
        }

        return null;
    }

    public function test_it_is_registered_in_beams_doctor_manifest(): void
    {
        $registration = $this->registration();

        $this->assertNotNull($registration, 'the audit is not registered, so it reports nothing anywhere');
        $this->assertSame('splicewire/laravel-beam', $registration->package);
    }

    public function test_it_is_advisory_and_not_a_gate(): void
    {
        $this->assertFalse(
            $this->registration()?->gate,
            'whether a model is surfaced HERE is a fact about the host, so this reports and never fails a build',
        );
    }

    /**
     * The failure this catches is a boot-time one that no logic test can reach: the unit suite passes
     * every collaborator in by hand, so an un-autowirable dependency is green there and fatal in a host.
     */
    public function test_it_resolves_from_the_container_with_no_arguments(): void
    {
        $audit = $this->app->make(ModelSurfaceCoverageAudit::class);

        $this->assertInstanceOf(DoctorAudit::class, $audit);
    }

    /**
     * And the resolved audit RUNS, over whatever a package testbench happens to compose.
     *
     * The assertion is deliberately not a finding count — that is a fact about this skeleton's vendor
     * tree and would churn. It is the one reading that would be a lie here in every arrangement: a
     * CONCLUSIVE all-clear. A testbench has no `app/`, so either nothing was scanned (inconclusive) or
     * family source was (warns) — and "every model in this estate is surfaced" is neither.
     */
    public function test_running_it_never_certifies_an_estate_it_did_not_look_at(): void
    {
        $findings = $this->app->make(ModelSurfaceCoverageAudit::class)->run();

        $this->assertNotSame([], $findings, 'an audit returning no findings at all says nothing either way');

        foreach ($findings as $finding) {
            $this->assertFalse(
                $finding->check === ModelSurfaceCoverageAudit::CHECK
                    && $finding->conclusive
                    && $finding->status === DoctorStatus::Pass,
                'a conclusive all-clear from a testbench is a claim about a population that is not here',
            );
        }
    }
}
