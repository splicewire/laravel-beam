<?php

namespace Splicewire\Beam\Tests\Particle;

use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Tests\TestCase;

class ParticleOperationRecordsAssentTest extends TestCase
{
    public function test_write_operation_can_declare_that_its_handler_records_assent(): void
    {
        $operation = new ParticleOperation(
            resource: 'agreements',
            name: 'submit',
            kind: OperationKind::Write,
            handle: static fn () => null,
            recordsAssent: true,
        );

        $this->assertTrue($operation->recordsAssent);
    }

    public function test_operations_default_to_not_recording_assent(): void
    {
        $operation = new ParticleOperation(
            resource: 'agreements',
            name: 'read',
            kind: OperationKind::Read,
            handle: static fn () => null,
        );

        $this->assertFalse($operation->recordsAssent);
    }
}
