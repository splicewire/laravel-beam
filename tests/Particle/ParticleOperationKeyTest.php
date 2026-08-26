<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorStatus;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Doctor\UngatedOperationAudit;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-operation-surface ticket 03 — **the operation's key IS its registry key and its permission
 * name**, and `ability:` gains the third state that tells an omission apart from a decision.
 *
 * What this file pins, in the order the ticket argues it:
 *
 *   - the entry SELF-KEYS and never spells the root, so the root is a one-line attribute change;
 *   - the stored address is `beam.particle.operations.<resource>.<name>` — TWO segments under the root,
 *     which is what makes a resource's operations enumerable and a relation scope nestable;
 *   - every historical read signature still answers, because the map's standing preference is that the
 *     existing population must not have to change;
 *   - `permissionName()` is the key minus the root, exactly — the alignment is arithmetic;
 *   - `ability: false` is a DECLARATION of ungated-ness and drops out of the residue count, while
 *     `ability: null` stays in it.
 */
class ParticleOperationKeyTest extends TestCase
{
    // ── The entry self-keys, and the registry stamps the root ───────────────────────────────────────

    public function test_an_operation_keys_itself_and_never_spells_the_root(): void
    {
        $key = $this->op('tenants', 'suspend')->registryKey();

        $this->assertInstanceOf(RegistryKey::class, $key);
        $this->assertSame(['tenants', 'suspend'], $key->segments());
        $this->assertStringNotContainsString(
            'beam.particle.operations',
            (string) $key,
            'The entry must not spell the root — that is what makes a rekey a one-line attribute change.',
        );
    }

    public function test_the_registry_stamps_its_declared_root_onto_the_stored_key(): void
    {
        $registry = $this->registry();
        $registry->register($this->op('tenants', 'suspend'));

        $this->assertSame(
            ['beam.particle.operations.tenants.suspend'],
            array_map(strval(...), $registry->keys()),
        );
    }

    public function test_the_key_is_two_segments_under_the_root_not_one(): void
    {
        // The whole point of moving `:` → `.`. A colon KEY parses fine (registry-kernel ticket 30
        // widened the charset), so the migration was never about legality — it was about the flat
        // one-segment shape a colon produces.
        $this->assertNotNull(Key::tryParse('tenants:suspend'), 'A colon key still parses; it is legal.');
        $this->assertSame(['tenants:suspend'], Key::parse('tenants:suspend')->segments());

        $this->assertSame(['tenants', 'suspend'], Key::parse('tenants.suspend')->segments());
    }

    public function test_a_resource_s_operations_are_enumerable_from_the_key_alone(): void
    {
        $registry = $this->registry();
        $registry->register($this->op('tenants', 'suspend'));
        $registry->register($this->op('tenants', 'unsuspend'));
        $registry->register($this->op('users', 'login-as'));

        $this->assertSame(
            ['suspend', 'unsuspend'],
            array_map(fn (ParticleOperation $op): string => $op->name, $registry->forResource('tenants')),
        );

        // And the same read is available through the kernel contract, segment-wise: `tenant` is not a
        // prefix of `tenants` to a registry key however much the strings suggest otherwise.
        $this->assertCount(2, $registry->matches('beam.particle.operations.tenants'));
        $this->assertCount(0, $registry->matches('beam.particle.operations.tenant'));
    }

    public function test_a_relation_scoped_key_nests_without_a_disambiguating_field(): void
    {
        $registry = $this->registry();
        $registry->register($this->op('compositions.cells', 'approve'));

        $this->assertSame(
            ['beam.particle.operations.compositions.cells.approve'],
            array_map(strval(...), $registry->keys()),
        );
        $this->assertCount(1, $registry->matches('beam.particle.operations.compositions'));
    }

    // ── The existing population does not have to change ─────────────────────────────────────────────

    public function test_every_historical_read_signature_still_answers(): void
    {
        $registry = $this->registry();
        $registry->register($this->op('tenants', 'suspend'));

        $this->assertTrue($registry->has('tenants', 'suspend'));
        $this->assertFalse($registry->has('tenants', 'unsuspend'));
        $this->assertSame('suspend', $registry->get('tenants', 'suspend')->name);
        $this->assertSame('suspend', $registry->find('tenants', 'suspend')?->name);
        $this->assertNull($registry->find('tenants', 'unsuspend'));
        $this->assertCount(1, $registry->all());
    }

    public function test_a_lookup_on_an_illegal_address_answers_absent_rather_than_throwing(): void
    {
        // A caller asking about something that is not here must get "not here", not a 500. Registration
        // still throws — a declaration that cannot be addressed is a defect and must be loud.
        $this->assertFalse($this->registry()->has('Not A Resource', 'suspend'));
        $this->assertNull($this->registry()->find('Not A Resource', 'suspend'));
    }

    public function test_registering_over_a_key_supersedes_and_records_it(): void
    {
        // This is the mechanism ticket 01 needs for "a package overrides an operation it does not own",
        // and `superseded()` is what makes the override auditable rather than an inference from boot
        // order.
        $registry = $this->registry();
        $registry->register($this->op('tenants', 'suspend'), by: 'beam-tenancy');
        $registry->register($this->op('tenants', 'suspend', ability: 'tenant.suspend'), by: 'tower');

        $this->assertSame('tenant.suspend', $registry->get('tenants', 'suspend')->ability);
        $this->assertCount(1, $registry->superseded('beam.particle.operations.tenants.suspend'));
        $this->assertCount(1, $registry->all(), 'A superseded entry is history, never a live entry.');
    }

    // ── One key, three jobs ─────────────────────────────────────────────────────────────────────────

    public function test_the_permission_name_is_the_key_minus_the_root_exactly(): void
    {
        $op = $this->op('market-products', 'approve');

        $this->assertSame('market-products.approve', $op->key());
        $this->assertSame('market-products.approve', $op->permissionName());
        $this->assertSame($op->key(), $op->permissionName(), 'The alignment is arithmetic, not a table.');
    }

    // ── `ability:` is three-state ───────────────────────────────────────────────────────────────────

    public function test_false_is_a_declaration_and_null_is_the_residue(): void
    {
        $this->assertTrue($this->op('users', 'a')->gateUndeclared());
        $this->assertFalse($this->op('users', 'b', ability: false)->gateUndeclared());
        $this->assertFalse($this->op('users', 'c', ability: 'update')->gateUndeclared());
    }

    public function test_the_audit_counts_the_residue_and_names_the_line_that_closes_it(): void
    {
        $registry = $this->registry();
        $registry->register($this->op('media', 'download'));
        $registry->register($this->op('users', 'stop-impersonating', ability: false));
        $registry->register($this->op('open-api-specs', 'inventory', ability: 'view'));

        $findings = (new UngatedOperationAudit($registry))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('1 of 3', $findings[0]->detail);
        $this->assertStringContainsString('media.download', $findings[0]->detail);
        $this->assertStringContainsString("ability: 'media.download'", $findings[0]->detail);
        $this->assertStringNotContainsString(
            'stop-impersonating',
            $findings[0]->detail,
            'A DECLARED `false` is not residue — that is the whole reason the third state exists.',
        );
    }

    public function test_the_audit_passes_once_the_residue_is_empty(): void
    {
        $registry = $this->registry();
        $registry->register($this->op('users', 'stop-impersonating', ability: false));
        $registry->register($this->op('open-api-specs', 'inventory', ability: 'view'));

        $findings = (new UngatedOperationAudit($registry))->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('2/2', $findings[0]->detail);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function registry(): ParticleOperationRegistry
    {
        return new ParticleOperationRegistry;
    }

    private function op(
        string $resource,
        string $name,
        string|false|null $ability = null,
        string|false|null $abilityModel = null,
    ): ParticleOperation {
        return new ParticleOperation(
            resource: $resource,
            name: $name,
            kind: OperationKind::Write,
            model: KeyedWidget::class,
            handle: fn () => ['ran' => true],
            ability: $ability,
            abilityModel: $abilityModel,
        );
    }
}

class KeyedWidget extends Model
{
    protected $table = 'keyed_widgets';

    public $timestamps = false;

    protected $guarded = [];
}
