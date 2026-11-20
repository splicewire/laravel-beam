<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;
use Splicewire\Beam\Agreements\NullRequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\RequirementRefusalDeclaredAudit;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/** agreements 04: a host-attached Requirement on an op whose author declared a different 428 body fails the doctor. */
class RequirementRefusalDeclaredAuditTest extends TestCase
{
    public function test_a_port_declared_op_with_a_conflicting_428_class_fails(): void
    {
        $this->register('conflicting', [428 => ConflictingRefusalProbeData::class]);
        $this->register('implied', null);
        $this->register('declared', [428 => RequirementRefusalData::class]);

        $finding = $this->finding(['refusal-probe.conflicting', 'refusal-probe.implied', 'refusal-probe.declared']);

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('1 of 3', $finding->detail);
        $this->assertStringContainsString('refusal-probe.conflicting', $finding->detail);
        $this->assertStringNotContainsString('refusal-probe.implied', $finding->detail);
    }

    public function test_implied_and_correctly_declared_ops_pass(): void
    {
        $this->register('implied', null);
        $this->register('declared', [428 => RequirementRefusalData::class]);
        $this->register('unattached', [428 => ConflictingRefusalProbeData::class]);

        $finding = $this->finding(['refusal-probe.implied', 'refusal-probe.declared']);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_the_null_port_passes_even_with_a_conflicting_428(): void
    {
        $this->register('conflicting', [428 => ConflictingRefusalProbeData::class]);

        $findings = (new RequirementRefusalDeclaredAudit(app(ParticleOperationRegistry::class), new NullRequirementDeclarations))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_the_audit_is_registered_as_a_gate(): void
    {
        $registrations = array_values(array_filter(
            app(BeamDoctorManifest::class)->registrations(),
            fn (object $registration): bool => $registration->audit === RequirementRefusalDeclaredAudit::class,
        ));

        $this->assertCount(1, $registrations);
        $this->assertTrue($registrations[0]->gate);
    }

    /** @param  list<string>  $behaviors */
    private function finding(array $behaviors): object
    {
        $port = new class($behaviors) implements RequirementDeclarations
        {
            public function __construct(private array $behaviors) {}

            public function declares(string $behavior): bool
            {
                return in_array($behavior, $this->behaviors, true);
            }

            public function requirementsFor(string $behavior): array
            {
                return $this->declares($behavior) ? [new \Splicewire\Beam\Agreements\Requires('terms')] : [];
            }

            public function all(): array
            {
                return array_combine($this->behaviors, array_map($this->requirementsFor(...), $this->behaviors));
            }
        };
        $this->app->instance(RequirementDeclarations::class, $port);

        $findings = (new RequirementRefusalDeclaredAudit(app(ParticleOperationRegistry::class), $port))->run();
        $this->assertCount(1, $findings);
        $this->assertSame(RequirementRefusalDeclaredAudit::CHECK, $findings[0]->check);

        return $findings[0];
    }

    private function register(string $name, ?array $errors): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'refusal-probe',
            name: $name,
            kind: OperationKind::Write,
            handle: fn () => null,
            ability: false,
            errors: $errors,
        ));
    }
}

class ConflictingRefusalProbeData extends Data
{
    public function __construct(public string $code) {}
}
