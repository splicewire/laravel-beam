<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Doctor\UndeclaredOutputAudit;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The `output:` twin's count (api-surface-coherence 127).
 *
 * The load-bearing assertion is
 * {@see test_a_read_declaring_output_without_a_projector_is_not_a_finding}: the inverse direction is
 * TASK-scoped, and at the flagship 18 of the 20 `output:`-without-`respond:` operations are
 * Read/Write/Stream and correct. A universal inverse check would libel every one of them on its first
 * run, which is why this is two checks with two different kind scopes rather than one.
 */
class UndeclaredOutputAuditTest extends TestCase
{
    private function audit(): UndeclaredOutputAudit
    {
        return new UndeclaredOutputAudit(app(ParticleOperationRegistry::class));
    }

    private function operation(
        string $resource,
        string $name,
        OperationKind $kind,
        ?callable $respond,
        string|array|null $output,
    ): void {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: $resource,
            name: $name,
            kind: $kind,
            model: BeamParticle::class,
            handle: fn () => null,
            respond: $respond === null ? null : \Closure::fromCallable($respond),
            output: $output,
        ));
    }

    private function finding(array $findings, string $check): object
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        $this->fail("No finding for check {$check}.");
    }

    public function test_it_reports_both_directions_as_separate_checks(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(2, $findings);
        $this->assertSame(
            [UndeclaredOutputAudit::CHECK_PROJECTED, UndeclaredOutputAudit::CHECK_UNPROJECTED],
            array_map(fn ($finding) => $finding->check, $findings),
        );
    }

    /**
     * An empty population is INCONCLUSIVE, not a pass — the distinction api-surface-coherence 124 shipped
     * on `Finding::inconclusive()`. A host registering no projector has not been found clean; it has not
     * been measured.
     */
    public function test_an_unpopulated_host_reads_inconclusive_on_both_checks(): void
    {
        foreach ($this->audit()->run() as $finding) {
            $this->assertSame(DoctorStatus::Pass, $finding->status);
            $this->assertFalse($finding->conclusive, "{$finding->check} should be inconclusive.");
        }
    }

    public function test_a_projector_with_no_output_is_reported(): void
    {
        $this->operation('widgets', 'publish', OperationKind::Task, fn () => null, null);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_PROJECTED);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertTrue($finding->conclusive);
        $this->assertStringContainsString('widgets.publish', $finding->detail);
    }

    /** The pairing rule is UNIVERSAL in this direction — `finish()` consults `respond` on every kind. */
    public function test_a_read_projector_with_no_output_is_reported_too(): void
    {
        $this->operation('widgets', 'peek', OperationKind::Read, fn () => null, null);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_PROJECTED);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('widgets.peek', $finding->detail);
    }

    public function test_a_projector_declaring_a_data_output_passes(): void
    {
        $this->operation('widgets', 'publish', OperationKind::Task, fn () => null, OutputAuditPayload::class);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_PROJECTED);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertTrue($finding->conclusive);
    }

    /**
     * The resolution mirrors `ParticleResponseStrategy`, which bails unless the class-string is a `Data`
     * subclass — so a declaration the spec leg cannot describe must not read as declared here either.
     */
    public function test_a_non_data_output_does_not_count_as_declared(): void
    {
        $this->operation('widgets', 'publish', OperationKind::Task, fn () => null, BeamParticle::class);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_PROJECTED);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('widgets.publish', $finding->detail);
    }

    public function test_a_stream_event_map_counts_as_declared(): void
    {
        $this->operation('widgets', 'watch', OperationKind::Stream, fn () => null, [
            'tick' => [OutputAuditPayload::class],
        ]);

        $this->assertSame(
            DoctorStatus::Pass,
            $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_PROJECTED)->status,
        );
    }

    /**
     * ⚠️ The load-bearing one. Read/Write/Stream `handle` returns the payload itself, so `output:` with no
     * `respond:` is the NORMAL shape — 18 of the flagship's 20 are exactly this.
     */
    public function test_a_read_declaring_output_without_a_projector_is_not_a_finding(): void
    {
        $this->operation('widgets', 'peek', OperationKind::Read, null, OutputAuditPayload::class);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_UNPROJECTED);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertFalse($finding->conclusive, 'No Task is registered, so the inverse check measured nothing.');
    }

    /** A Task is the one kind whose framework default (`{ queued: … }`) contradicts the declaration. */
    public function test_a_task_declaring_output_without_a_projector_is_reported(): void
    {
        $this->operation('widgets', 'publish', OperationKind::Task, null, OutputAuditPayload::class);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_UNPROJECTED);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('widgets.publish', $finding->detail);
    }

    public function test_a_task_pairing_both_slots_passes_the_inverse_check(): void
    {
        $this->operation('widgets', 'publish', OperationKind::Task, fn () => null, OutputAuditPayload::class);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_UNPROJECTED);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertTrue($finding->conclusive);
    }

    /** An acknowledged key is reported under its own heading, never silently subtracted. */
    public function test_an_acknowledged_key_is_not_outstanding_but_is_named(): void
    {
        $key = array_key_first(UndeclaredOutputAudit::ACKNOWLEDGED);
        [$resource, $name] = explode('.', $key, 2);

        $this->operation($resource, $name, OperationKind::Task, fn () => null, null);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_PROJECTED);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString($key, $finding->detail);
    }

    /** The carve-out cannot outlive its reason. */
    public function test_an_acknowledged_key_that_now_declares_is_reported_as_stale(): void
    {
        $key = array_key_first(UndeclaredOutputAudit::ACKNOWLEDGED);
        [$resource, $name] = explode('.', $key, 2);

        $this->operation($resource, $name, OperationKind::Task, fn () => null, OutputAuditPayload::class);

        $finding = $this->finding($this->audit()->run(), UndeclaredOutputAudit::CHECK_PROJECTED);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('STALE', $finding->detail);
    }
}

class OutputAuditPayload extends Data
{
    public function __construct(public string $id) {}
}
