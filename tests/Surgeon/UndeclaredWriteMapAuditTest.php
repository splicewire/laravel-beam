<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\UndeclaredWriteMapAudit;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * The write-map burn-down meter (particle-write-surface 03). Its population is two live registries, so
 * these build real declarations rather than fixtures on disk — the slot it audits is a REGISTERED value,
 * not a syntactic one.
 *
 * The negative assertions carry the weight: a duck-typed class must NOT be reported (the fallback is
 * still supported, and reporting it would make the meter unreadable), and a read/task/stream op's input
 * must not be reported at all (its input is a query or a command, not a row).
 */
class UndeclaredWriteMapAuditTest extends TestCase
{
    private function audit(array $resources = [], array $operations = []): UndeclaredWriteMapAudit
    {
        $resourceRegistry = new ParticleResourceRegistry;
        foreach ($resources as $resource) {
            $resourceRegistry->register($resource);
        }

        $operationRegistry = new ParticleOperationRegistry;
        foreach ($operations as $operation) {
            $operationRegistry->register($operation);
        }

        return new UndeclaredWriteMapAudit($resourceRegistry, $operationRegistry);
    }

    private function resource(string $key, ?string $input = null, ?string $editData = null): ParticleResource
    {
        return new ParticleResource(
            key: $key,
            backing: WriteMapAuditModel::class,
            input: $input,
            editData: $editData,
        );
    }

    private function operation(string $name, OperationKind $kind, ?string $input): ParticleOperation
    {
        return new ParticleOperation(
            resource: 'papers',
            name: $name,
            kind: $kind,
            model: WriteMapAuditModel::class,
            handle: fn () => null,
            input: $input,
        );
    }

    public function test_a_declared_input_is_clean(): void
    {
        $audit = $this->audit([$this->resource('papers', input: WriteMapAuditDeclaredInput::class)]);

        $this->assertSame([], $audit->undeclared());
        $this->assertSame([WriteMapAuditDeclaredInput::class], $audit->declared());
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }

    public function test_a_duck_typed_input_is_not_reported_but_is_not_counted_as_declared_either(): void
    {
        $audit = $this->audit([$this->resource('papers', input: WriteMapAuditDuckTypedInput::class)]);

        $this->assertSame([], $audit->undeclared(), 'the duck type is still a supported path');
        $this->assertSame([], $audit->declared(), 'but it is not progress — declared() is the burn-down half');
    }

    public function test_an_input_with_no_map_at_all_is_reported(): void
    {
        $audit = $this->audit([$this->resource('papers', input: WriteMapAuditBareInput::class)]);

        $rows = $audit->undeclared();

        $this->assertCount(1, $rows);
        $this->assertSame(WriteMapAuditBareInput::class, $rows[0]['class']);
        $this->assertSame('resource-input', $rows[0]['kind']);
        $this->assertSame(['resource [papers] input:'], $rows[0]['slots']);

        $findings = $audit->run();
        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status, 'advisory — the fallback is supported');
    }

    public function test_edit_data_is_audited_and_a_class_named_twice_is_one_row(): void
    {
        $audit = $this->audit([
            $this->resource('papers', input: WriteMapAuditBareInput::class, editData: WriteMapAuditBareInput::class),
        ]);

        $rows = $audit->undeclared();

        $this->assertCount(1, $rows, 'one class is one piece of work, however many slots name it');
        $this->assertSame(
            ['resource [papers] input:', 'resource [papers] editData:'],
            $rows[0]['slots'],
        );
    }

    public function test_only_write_ops_are_audited(): void
    {
        $audit = $this->audit([], [
            $this->operation('reorder', OperationKind::Write, WriteMapAuditBareInput::class),
            $this->operation('search', OperationKind::Read, WriteMapAuditBareInput::class),
            $this->operation('rebuild', OperationKind::Task, WriteMapAuditBareInput::class),
        ]);

        $slots = array_column($audit->slots(), 1);

        $this->assertSame(['write op [papers.reorder] input:'], $slots);
    }

    public function test_input_false_and_input_null_declare_no_slot(): void
    {
        $audit = $this->audit(
            [$this->resource('papers')],
            [$this->operation('reorder', OperationKind::Write, null)],
        );

        $this->assertSame([], $audit->slots());
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }
}

class WriteMapAuditModel extends Model {}

class WriteMapAuditDeclaredInput implements MapsToModelAttributes
{
    public function toModelAttributes(): array
    {
        return [];
    }
}

class WriteMapAuditDuckTypedInput
{
    public function toModelAttributes(): array
    {
        return [];
    }
}

class WriteMapAuditBareInput {}
