<?php

namespace Splicewire\Beam\Tests\Doctor;

use PHPUnit\Framework\Attributes\Test;
use Rushing\Doctor\Concerns\RunsDoctorFloor;
use Rushing\Doctor\DoctorFailed;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\UngatedOperationAudit;
use Splicewire\Beam\Doctor\UngatedWriteOperationAudit;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\BeamDoctorCommandTest;
use Splicewire\Beam\Tests\TestCase;

/**
 * **The gate that keeps particle-write-surface 02 closed.**
 *
 * That ticket took `kind: Write` operations declaring `ability: null` from 9 to 0 at the flagship and
 * left `{@see UngatedOperationAudit}` reporting the residue with nothing failing on it. This audit is
 * the thing that fails. The load-bearing test is
 * {@see test_a_planted_null_ability_write_operation_fails} — a guard nobody has seen go red is not a
 * guard — and its companion {@see test_the_audit_is_registered_as_a_gate} is what makes that Fail
 * reach an exit code instead of a report.
 */
class UngatedWriteOperationAuditTest extends TestCase
{
    private function registry(): ParticleOperationRegistry
    {
        return app(ParticleOperationRegistry::class);
    }

    private function register(string $name, OperationKind $kind, string|false|null $ability): void
    {
        $this->registry()->register(new ParticleOperation(
            resource: 'gate-probe',
            name: $name,
            kind: $kind,
            model: BeamParticle::class,
            handle: fn () => null,
            ability: $ability,
        ));
    }

    private function finding(): object
    {
        $findings = (new UngatedWriteOperationAudit($this->registry()))->run();

        $this->assertCount(1, $findings);

        return $findings[0];
    }

    /**
     * Control, asserted before any finding: with every write operation declaring something, the audit
     * passes. Without this, a green run below could mean the audit never looked.
     */
    #[Test]
    public function test_it_passes_when_every_write_declares_something(): void
    {
        $this->register('token', OperationKind::Write, 'update');
        $this->register('explicit-false', OperationKind::Write, false);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertSame(UngatedWriteOperationAudit::CHECK, $finding->check);
    }

    /**
     * **The demonstration.** One `kind: Write` operation with `ability: null` — the exact shape the
     * ticket closed nine of — and the audit answers Fail, naming the op and the permission name it
     * would take.
     */
    #[Test]
    public function test_a_planted_null_ability_write_operation_fails(): void
    {
        $this->register('token', OperationKind::Write, 'update');
        $this->register('planted', OperationKind::Write, null);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        // "1 of N", not "1 of 2": beam's own testbench registers real write operations of its own, and
        // the denominator is every write in the registry. Pinning N here would make this test a census
        // of beam's op set, which is a different question and one that changes for good reasons.
        $this->assertStringStartsWith('1 of ', $finding->detail);
        $this->assertStringContainsString('gate-probe.planted', $finding->detail);
        $this->assertStringContainsString("ability: 'gate-probe.planted'", $finding->detail);
        $this->assertStringNotContainsString('gate-probe.token', $finding->detail);
    }

    /**
     * Scoped to `Write`, and the scoping is asserted rather than assumed. `OperationKind`'s docblock
     * says a Read's gate IS its query scope, so a null-ability Read is not necessarily a defect — all
     * four survivors at the flagship on 2026-08-31 were Reads. A Task is a mutation and arguably
     * belongs; it is out because nothing has measured it, which is a decision this test pins so that
     * widening the rule has to delete an assertion.
     */
    #[Test]
    public function test_a_null_ability_read_task_or_stream_does_not_fail_this_gate(): void
    {
        $this->register('write', OperationKind::Write, 'update');
        $this->register('read', OperationKind::Read, null);
        $this->register('task', OperationKind::Task, null);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    /**
     * The sibling still counts them, so nothing became invisible when `Write` moved out — and it counts
     * them against a non-write denominator, not the whole registry.
     */
    #[Test]
    public function test_the_advisory_sibling_still_warns_on_the_non_write_residue(): void
    {
        $this->register('write', OperationKind::Write, 'update');
        $this->register('read', OperationKind::Read, null);

        $findings = (new UngatedOperationAudit($this->registry()))->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('gate-probe.read', $findings[0]->detail);
        $this->assertStringStartsWith('1 of ', $findings[0]->detail);
        // The denominator excludes writes — `gate-probe.write` declares an ability and would not be
        // listed either way, but the count it is absent from is the assertion.
        $this->assertStringNotContainsString('gate-probe.write', $findings[0]->detail);
    }

    /**
     * And a `kind: Write` null is NOT double-reported by the sibling — it belongs to the gate now.
     */
    #[Test]
    public function test_the_advisory_sibling_no_longer_reports_writes(): void
    {
        $this->register('read', OperationKind::Read, 'view');
        $this->register('planted', OperationKind::Write, null);

        $findings = (new UngatedOperationAudit($this->registry()))->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringNotContainsString('gate-probe.planted', $findings[0]->detail);
    }

    /**
     * ⚠️ The severity is only half the enforcement. `Finding::fail()` renders red either way; what
     * turns it into a non-zero exit from {@see BeamDoctorCommand} is the `gate: true` on the manifest
     * registration. Asserted here because the two live in different files and a revert of either one
     * alone would leave a check that looks exactly as loud and blocks nothing.
     */
    #[Test]
    public function test_the_audit_is_registered_as_a_gate(): void
    {
        $registrations = array_values(array_filter(
            app(BeamDoctorManifest::class)->registrations(),
            fn (object $registration): bool => $registration->audit === UngatedWriteOperationAudit::class,
        ));

        $this->assertCount(1, $registrations, 'UngatedWriteOperationAudit is not in the beam doctor manifest.');
        $this->assertTrue($registrations[0]->gate, 'UngatedWriteOperationAudit is registered advisory — a Fail would render red and block nothing.');
    }

    /**
     * **The chain, end to end: manifest registration → {@see DoctorRunner} → a thrown
     * {@see DoctorFailed}, which is what {@see RunsDoctorFloor::runAtFloor()} converts into
     * {@see BeamDoctorCommand}'s non-zero exit.**
     *
     * Driven through the runner over this audit's REAL manifest registration rather than through the
     * whole `splicewire:beam:doctor` command, and that is a deliberate narrowing with a stated cost.
     * Measured 2026-08-31: run on its OWN, `BeamDoctorCommandTest` already exits 1 for an unrelated
     * reason — `UndescribedRegistryAudit` and `RegistryConformanceAudit` both raise
     * `UnbakedRegistryIndex` without a `bootstrap/cache/popcorn-registries.php`, and a gate audit that
     * could not run correctly reports Fail. In a full-suite run some earlier test has written that
     * cache and the same file is green, so a command-level control here would be a demonstration whose
     * result depends on which files shared the process — the estate's known third spread mechanism,
     * used as an instrument. What the narrowing gives up is the wiring between
     * `DoctorFailed` and the command's exit code; that is
     * {@see BeamDoctorCommandTest}'s subject and it is pinned there already.
     */
    #[Test]
    public function test_a_planted_write_op_makes_the_doctor_runner_throw_through_the_real_registration(): void
    {
        $this->register('planted', OperationKind::Write, null);

        $registration = array_values(array_filter(
            app(BeamDoctorManifest::class)->registrations(),
            fn (object $r): bool => $r->audit === UngatedWriteOperationAudit::class,
        ))[0];

        // Control first: the same registration, run with nothing planted, does NOT throw. Without it a
        // caught DoctorFailed below could be any property of the runner rather than of this finding.
        $this->assertSame(
            DoctorStatus::Fail,
            app(DoctorRunner::class)->run([new DoctorRegistration('probe', UngatedWriteOperationAudit::class, gate: false)])->worst(),
            'The audit did not even report Fail, so the throw below would be measuring nothing.',
        );

        $this->expectException(DoctorFailed::class);

        app(DoctorRunner::class)->run([$registration]);
    }

    /**
     * The other half of the control above: with every write declaring an ability, the identical gating
     * registration runs clean and returns a report instead of throwing.
     */
    #[Test]
    public function test_the_same_gating_registration_does_not_throw_when_every_write_declares(): void
    {
        $this->register('declared', OperationKind::Write, 'update');

        $registration = array_values(array_filter(
            app(BeamDoctorManifest::class)->registrations(),
            fn (object $r): bool => $r->audit === UngatedWriteOperationAudit::class,
        ))[0];

        $this->assertSame(DoctorStatus::Pass, app(DoctorRunner::class)->run([$registration])->worst());
    }

    /**
     * The sibling stays advisory, deliberately: on a gating registration a `warn` fails the runner at a
     * lowered `--floor`, which would drag the legitimate `kind: Read` residue into a build break.
     */
    #[Test]
    public function test_the_advisory_sibling_is_not_registered_as_a_gate(): void
    {
        $registrations = array_values(array_filter(
            app(BeamDoctorManifest::class)->registrations(),
            fn (object $registration): bool => $registration->audit === UngatedOperationAudit::class,
        ));

        $this->assertCount(1, $registrations);
        $this->assertFalse($registrations[0]->gate);
    }
}
