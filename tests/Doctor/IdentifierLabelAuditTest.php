<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Doctor\IdentifierLabelAudit;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Schema\IdentifierLabels;
use Splicewire\Beam\Tests\TestCase;

/**
 * app-walkthrough APP-09 (APP-21): `schema.identifier-label` counts the classes reachable from a particle's
 * `input:`/`output:` (and a resource's `data:`/`input:`) whose rendered label would be an identifier: a Data class
 * with no #[Title], or an enum with no ProvidesEnumLabel (its options would show backing values).
 */
class IdentifierLabelAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'fixture-labels',
            name: 'run',
            kind: OperationKind::Write,
            model: BeamParticle::class,
            handle: fn () => null,
            input: IdentifierLabelInputData::class,
            output: IdentifierLabelOutputData::class,
        ));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'fixture-labels',
            backing: BeamParticle::class,
            data: IdentifierLabelResourceData::class,
            frame: false,
        ));
    }

    public function test_it_names_each_reachable_class_that_would_render_its_identifier(): void
    {
        $found = app(IdentifierLabels::class)->find();

        foreach ([IdentifierLabelInputData::class, IdentifierLabelNestedData::class, IdentifierLabelItemData::class, IdentifierLabelRawEnum::class, IdentifierLabelResourceData::class] as $class) {
            $this->assertArrayHasKey($class, $found, $class);
        }
        foreach ([IdentifierLabelOutputData::class, IdentifierLabelLabelledEnum::class] as $class) {
            $this->assertArrayNotHasKey($class, $found, $class);
        }
        $this->assertSame('enum', $found[IdentifierLabelRawEnum::class]);
        $this->assertSame('object', $found[IdentifierLabelInputData::class]);
    }

    public function test_the_doctor_warns_with_the_count_and_the_classes(): void
    {
        $finding = app(IdentifierLabelAudit::class)->run()[0];

        $this->assertSame(IdentifierLabelAudit::CHECK, $finding->check);
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString(IdentifierLabelRawEnum::class, $finding->detail);
    }
}

enum IdentifierLabelRawEnum: string
{
    case PastDue = 'past_due';
}

enum IdentifierLabelLabelledEnum: string implements ProvidesEnumLabel
{
    case Daily = 'daily';

    public function label(): string
    {
        return 'Every day';
    }
}

class IdentifierLabelItemData extends Data
{
    public function __construct(public string $name = '') {}
}

class IdentifierLabelNestedData extends Data
{
    public function __construct(public ?IdentifierLabelRawEnum $state = null) {}
}

class IdentifierLabelInputData extends Data
{
    public function __construct(
        public ?IdentifierLabelNestedData $nested = null,
        public ?IdentifierLabelLabelledEnum $frequency = null,
        /** @var array<IdentifierLabelItemData> */
        #[DataCollectionOf(IdentifierLabelItemData::class)]
        public array $items = [],
    ) {}
}

#[Title('Run result')]
class IdentifierLabelOutputData extends Data
{
    public function __construct(public ?IdentifierLabelLabelledEnum $frequency = null) {}
}

class IdentifierLabelResourceData extends Data
{
    public function __construct(public string $id = '') {}
}
