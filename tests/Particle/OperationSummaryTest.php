<?php

namespace Splicewire\Beam\Tests\Particle;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\UndeclaredOperationSummaryAudit;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class OperationSummaryTest extends TestCase
{
    public function test_summary_and_description_forward_from_attribute_to_runtime(): void
    {
        $ops = app(ParticleOperationRegistry::class);
        (new AttributedParticleDiscovery(app(ParticleResourceRegistry::class), $ops))->registerClass(SummaryFixtureOperation::class);
        $op = $ops->find('summary-fixtures', 'report');
        $this->assertSame('Read the fixture report', $op->summary);
        $this->assertSame('The authored report description.', $op->description);
    }

    public function test_audit_counts_missing_and_blank_summaries_for_all_kinds_without_gating(): void
    {
        $ops = new ParticleOperationRegistry;
        $ops->register(new ParticleOperation(resource: 'summaries', name: 'missing', kind: OperationKind::Read, handle: fn () => null));
        $ops->register(new ParticleOperation(resource: 'summaries', name: 'blank', kind: OperationKind::Write, handle: fn () => null, summary: '  '));
        $ops->register(new ParticleOperation(resource: 'summaries', name: 'declared', kind: OperationKind::Read, handle: fn () => null, summary: 'A report'));
        $finding = (new UndeclaredOperationSummaryAudit($ops))->run()[0];
        $this->assertSame('particle.operation-summary', $finding->check);
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('2 of 3', $finding->detail);
        $this->assertStringContainsString('summaries.missing', $finding->detail);
        $this->assertStringContainsString('summaries.blank', $finding->detail);
        $this->assertStringNotContainsString('summaries.declared', $finding->detail);
    }

    public function test_audit_reports_empty_population_as_inconclusive_and_complete_as_pass(): void
    {
        $ops = new ParticleOperationRegistry;
        $this->assertFalse((new UndeclaredOperationSummaryAudit($ops))->run()[0]->conclusive);
        $ops->register(new ParticleOperation(resource: 'summaries', name: 'declared', kind: OperationKind::Read, handle: fn () => null, summary: 'A report'));
        $this->assertSame(DoctorStatus::Pass, (new UndeclaredOperationSummaryAudit($ops))->run()[0]->status);
    }

    public function test_every_operation_generator_stub_declares_the_two_documentation_slots(): void
    {
        $stubs = glob(__DIR__.'/../../stubs/particle-op*.stub');
        $this->assertCount(3, $stubs);
        foreach ($stubs as $stub) {
            $source = file_get_contents($stub);
            $this->assertStringContainsString('summary: null,', $source);
            $this->assertStringContainsString('description: null,', $source);
        }
    }
}

#[ParticleOp(resource: 'summary-fixtures', name: 'report', kind: OperationKind::Read, summary: 'Read the fixture report', description: 'The authored report description.')]
class SummaryFixtureOperation
{
    public static function handle(): mixed
    {
        return null;
    }
}
