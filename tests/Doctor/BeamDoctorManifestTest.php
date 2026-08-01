<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Tests\TestCase;

/**
 * The doctor-side aggregation manifest (beam-ux-prototype-extract ticket 08): consumers register a
 * {@see DoctorAudit} DOWN into it, and `splicewire:beam:doctor` iterates the manifest after its own
 * hardcoded audits — a gate registration's Fail joins the exit code, an advisory one only renders.
 */
class BeamDoctorManifestTest extends TestCase
{
    private string $fixtureBase;

    protected function tearDown(): void
    {
        if (isset($this->fixtureBase) && is_dir($this->fixtureBase)) {
            @unlink($this->fixtureBase.'/composer.json');
            @unlink($this->fixtureBase.'/composer.lock');
            @unlink($this->fixtureBase.'/BEAM.md');
            @rmdir($this->fixtureBase);
        }

        parent::tearDown();
    }

    // ---- Manifest unit (ordering + idempotency) -------------------------------------

    public function test_registrations_are_ordered_core_first(): void
    {
        $manifest = new BeamDoctorManifest;
        $manifest->register('vendor/late', PassingStubAudit::class, order: 200);
        $manifest->register('vendor/early', FailingStubAudit::class, order: 50);

        $orders = array_map(static fn ($r) => $r->order, $manifest->registrations());

        $this->assertSame([50, 200], $orders);
    }

    public function test_registering_the_same_audit_twice_replaces_rather_than_doubles(): void
    {
        $manifest = new BeamDoctorManifest;
        $manifest->register('vendor/x', PassingStubAudit::class, gate: false, order: 100);
        $manifest->register('vendor/x', PassingStubAudit::class, gate: true, order: 100);

        $registrations = $manifest->registrations();

        $this->assertCount(1, $registrations);
        $this->assertTrue($registrations[0]->gate, 'the second registration should win');
    }

    // ---- Command aggregation (the consumer tail renders + gates) ---------------------

    public function test_a_registered_gate_audit_fail_turns_the_run_red(): void
    {
        $this->pointAtCleanContract();
        $this->app->make(BeamDoctorManifest::class)
            ->register('vendor/probe', FailingStubAudit::class, gate: true);

        $this->artisan('splicewire:beam:doctor')
            ->expectsOutputToContain('stub-check')
            ->assertExitCode(BeamDoctorCommand::FAILURE);
    }

    public function test_a_registered_advisory_audit_fail_does_not_turn_the_run_red(): void
    {
        $this->pointAtCleanContract();
        $this->app->make(BeamDoctorManifest::class)
            ->register('vendor/probe', FailingStubAudit::class, gate: false);

        // Same failing audit, but registered advisory — it renders yet the exit stays green
        // (the clean contract keeps every hardcoded gate passing).
        $this->artisan('splicewire:beam:doctor')
            ->expectsOutputToContain('stub-check')
            ->assertExitCode(BeamDoctorCommand::SUCCESS);
    }

    public function test_a_passing_registered_audit_leaves_a_clean_run_green(): void
    {
        $this->pointAtCleanContract();
        $this->app->make(BeamDoctorManifest::class)
            ->register('vendor/probe', PassingStubAudit::class, gate: true);

        $this->artisan('splicewire:beam:doctor')->assertExitCode(BeamDoctorCommand::SUCCESS);
    }

    /**
     * Point the app base path at a temp dir holding a clean, git-resolved contract + a valid BEAM.md,
     * so every hardcoded gate passes and only the manifest tail decides the outcome.
     */
    private function pointAtCleanContract(): void
    {
        $this->fixtureBase = sys_get_temp_dir().'/beam-doctor-manifest-'.uniqid();
        @mkdir($this->fixtureBase, 0777, true);

        file_put_contents(
            $this->fixtureBase.'/composer.json',
            json_encode(['minimum-stability' => 'dev', 'prefer-stable' => true]),
        );
        file_put_contents(
            $this->fixtureBase.'/composer.lock',
            json_encode(['packages' => [['name' => 'vendor/pkg', 'dist' => ['type' => 'zip']]], 'packages-dev' => []]),
        );
        file_put_contents(
            $this->fixtureBase.'/BEAM.md',
            "---\nsatellite: fixture\nvariant: inertia-react\n---\n# Fixture\n",
        );

        $this->app->setBasePath($this->fixtureBase);
    }
}

/**
 * @return list<Finding>
 */
class PassingStubAudit implements DoctorAudit
{
    public function run(): array
    {
        return [Finding::pass('stub-check', 'stub audit is happy')];
    }
}

class FailingStubAudit implements DoctorAudit
{
    public function run(): array
    {
        return [Finding::fail('stub-check', 'stub audit is unhappy')];
    }
}
