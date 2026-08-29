<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\ParticleIdConstraintKeyTypeAudit;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Routing\IdConstraint;
use Splicewire\Beam\Tests\TestCase;

/**
 * The key-type audit (particle-operation-surface 14 gate 1).
 *
 * ⚠️ The case that matters is the first one, and it is a *warn* rather than a *fail* on purpose: a
 * model's key type is a fact about the HOST, and this declaration is written in a package that cannot
 * know which `User` a host will bind. The estate has already taken one boot-time throw for forgetting
 * that distinction.
 *
 * The finding this reproduces is the real one. `~/Herd/audiostud` declared `int` for
 * `operator-customers` against a `HasUuids` model, with a provider docblock asserting the integer key
 * in prose right above the declaration — wrong in code and in the comment defending it, for as long as
 * both existed, and invisible because {@see IdConstraint::Int} emits no route constraint. A defect
 * whose entire signature is that it has no signature is what an audit is for.
 */
class ParticleIdConstraintKeyTypeAuditTest extends TestCase
{
    private function audit(): ParticleIdConstraintKeyTypeAudit
    {
        return new ParticleIdConstraintKeyTypeAudit($this->app->make(ParticleOperationRegistry::class));
    }

    private function register(string $name, string $model, ?IdConstraint $idConstraint): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'audited',
            name: $name,
            kind: OperationKind::Task,
            model: $model,
            handle: fn () => null,
            idConstraint: $idConstraint,
        ));
    }

    public function test_a_declared_int_against_a_uuid_keyed_model_warns_and_names_both_sides(): void
    {
        $this->register('impersonate', FixtureUuidKeyed::class, IdConstraint::Int);

        $finding = $this->audit()->run()[0];

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('audited.impersonate', $finding->detail);
        $this->assertStringContainsString('declares int', $finding->detail);
        $this->assertStringContainsString('keys on uuid', $finding->detail);
    }

    public function test_an_agreeing_declaration_passes(): void
    {
        $this->register('inspect', FixtureUuidKeyed::class, IdConstraint::Uuid);
        $this->register('tally', FixtureIntKeyed::class, IdConstraint::Int);
        $this->register('sort', FixtureUlidKeyed::class, IdConstraint::Ulid);

        $this->assertSame(DoctorStatus::Pass, $this->audit()->run()[0]->status);
    }

    public function test_none_is_a_declaration_of_openness_and_never_contradicts_a_key_type(): void
    {
        $this->register('browse', FixtureUuidKeyed::class, IdConstraint::None);

        $this->assertSame(
            DoctorStatus::Pass,
            $this->audit()->run()[0]->status,
            '`None` asserts nothing about the key — it says the `{id}` segment is deliberately open, '
                .'which the flagship already spells by hand on its circuits rendering. Reporting it as '
                .'a mismatch would make the honest declaration the noisy one.',
        );
    }

    public function test_an_undeclared_id_constraint_is_not_a_finding(): void
    {
        $this->register('drift', FixtureUuidKeyed::class, null);

        $this->assertSame(DoctorStatus::Pass, $this->audit()->run()[0]->status);
    }

    public function test_a_model_this_audit_cannot_resolve_is_silent_rather_than_reported(): void
    {
        $this->register('ghost', 'App\\Models\\NotHere', IdConstraint::Int);

        $this->assertSame(
            DoctorStatus::Pass,
            $this->audit()->run()[0]->status,
            'An unresolvable model is this audit\'s blind spot, not the host\'s defect. Reporting it '
                .'would put a finding in front of someone with no way to act on it, which is how an '
                .'advisory check earns the habit of being ignored.',
        );
    }
}

class FixtureUuidKeyed extends Model
{
    use HasUuids;
}

class FixtureUlidKeyed extends Model
{
    use HasUlids;
}

class FixtureIntKeyed extends Model {}
