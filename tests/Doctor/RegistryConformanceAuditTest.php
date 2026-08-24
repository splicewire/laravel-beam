<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Tests\Doctor\Fixtures\ConformingFixtureRegistry;
use Splicewire\Beam\Tests\Doctor\Fixtures\DeclaredOnlyFixtureRegistry;
use Splicewire\Beam\Tests\Doctor\Fixtures\FirstContestedRootFixtureRegistry;
use Splicewire\Beam\Tests\Doctor\Fixtures\IllegalRootFixtureRegistry;
use Splicewire\Beam\Tests\Doctor\Fixtures\InheritingFixtureRegistry;
use Splicewire\Beam\Tests\Doctor\Fixtures\SecondContestedRootFixtureRegistry;
use Splicewire\Beam\Tests\Doctor\Fixtures\SilentDuplicateFixtureRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * registry-kernel ticket 35 §1 — the gate over the population that opted in by declaring `#[IsRegistry]`.
 *
 * Two properties are proven here that a happy-path test would not prove:
 *
 *  1. **The gate is scoped to a COMPOSITION, not to the fixtures file.** Every case binds its own fixtures
 *     into the live container and then asserts on `declarations()`, because the population IS the binding
 *     table (14 D2). A test that reflected over a class list would pass against an audit that reads a
 *     different set than the one it ships reading.
 *  2. **`implements Registry` is measured but not gated.** Two separate assertions, deliberately:
 *     `declarations()` must SEE the failure and `run()` must not EMIT it. Asserting only the second would
 *     pass equally against an audit that had simply deleted the check, which is the outcome ticket 21's
 *     input was warning against.
 */
class RegistryConformanceAuditTest extends TestCase
{
    /**
     * @param  list<class-string>  $bind
     */
    private function audit(array $bind): RegistryConformanceAudit
    {
        foreach ($bind as $fqcn) {
            $this->app->singleton($fqcn);
        }

        return new RegistryConformanceAudit($this->app, $this->app->make(RegistryIndex::class));
    }

    /**
     * @return list<string>
     */
    private function failuresFor(RegistryConformanceAudit $audit, string $fqcn): array
    {
        foreach ($audit->declarations() as $row) {
            if ($row['registry'] === $fqcn) {
                return $row['failures'];
            }
        }

        $this->fail("{$fqcn} is not in the audit's population at all — the binding-table read is broken, and "
            .'every other assertion in this file would pass vacuously.');
    }

    public function test_a_complete_declaration_fails_nothing(): void
    {
        $audit = $this->audit([ConformingFixtureRegistry::class]);

        $this->assertSame([], $this->failuresFor($audit, ConformingFixtureRegistry::class));
    }

    public function test_an_unwritten_on_duplicate_is_a_finding(): void
    {
        $audit = $this->audit([SilentDuplicateFixtureRegistry::class]);

        $this->assertSame(
            [RegistryConformanceAudit::CHECK_ON_DUPLICATE],
            $this->failuresFor($audit, SilentDuplicateFixtureRegistry::class),
            'Inheriting Supersede silently must be a finding: the estate ships all three policies with '
            .'argued docblocks, so an unwritten one is a guess that reads as a decision.',
        );
    }

    public function test_an_unparseable_root_is_a_finding(): void
    {
        $audit = $this->audit([IllegalRootFixtureRegistry::class]);

        $this->assertContains(
            RegistryConformanceAudit::CHECK_ROOT,
            $this->failuresFor($audit, IllegalRootFixtureRegistry::class),
        );
    }

    public function test_two_registries_on_one_root_both_collide(): void
    {
        $audit = $this->audit([
            FirstContestedRootFixtureRegistry::class,
            SecondContestedRootFixtureRegistry::class,
        ]);

        // BOTH sides, not just the second: which one is "the duplicate" depends on binding order, and a
        // finding that names only one of two identical claims tells the reader to fix an arbitrary half.
        $this->assertContains(
            RegistryConformanceAudit::CHECK_ROOT_COLLISION,
            $this->failuresFor($audit, FirstContestedRootFixtureRegistry::class),
        );
        $this->assertContains(
            RegistryConformanceAudit::CHECK_ROOT_COLLISION,
            $this->failuresFor($audit, SecondContestedRootFixtureRegistry::class),
        );
    }

    public function test_a_legal_root_never_reports_a_collision_against_itself(): void
    {
        $audit = $this->audit([ConformingFixtureRegistry::class]);

        $this->assertNotContains(
            RegistryConformanceAudit::CHECK_ROOT_COLLISION,
            $this->failuresFor($audit, ConformingFixtureRegistry::class),
        );
    }

    public function test_the_contract_check_is_measured(): void
    {
        $audit = $this->audit([DeclaredOnlyFixtureRegistry::class]);

        $this->assertSame(
            [RegistryConformanceAudit::CHECK_CONTRACT],
            $this->failuresFor($audit, DeclaredOnlyFixtureRegistry::class),
            'A class that declares #[IsRegistry] without implementing Registry must be SEEN to fail, '
            .'whether or not the failure currently gates.',
        );
    }

    public function test_the_contract_check_does_not_gate_yet(): void
    {
        if (RegistryConformanceAudit::CONTRACT_GATES) {
            $this->markTestSkipped('registry-kernel 37/38 have landed and the flag is up; the sibling test '
                .'below is the live one.');
        }

        $audit = $this->audit([DeclaredOnlyFixtureRegistry::class]);
        $statuses = array_map(fn ($finding) => $finding->status, $audit->run());

        $this->assertNotContains(
            DoctorStatus::Fail,
            $statuses,
            'This audit is registered gate: true, so a non-gating check cannot be a warn() here either — a '
            .'warn on a gating audit still fails the runner at a lowered --floor. Until 37/38 land, the '
            .'contract failure is reported through the artifact and the command, not through the doctor.',
        );
    }

    public function test_a_gating_check_reaches_the_doctor_as_a_fail(): void
    {
        $audit = $this->audit([SilentDuplicateFixtureRegistry::class]);
        $statuses = array_map(fn ($finding) => $finding->status, $audit->run());

        $this->assertContains(DoctorStatus::Fail, $statuses);
    }

    public function test_the_pass_message_names_what_it_is_not_gating_on(): void
    {
        if (RegistryConformanceAudit::CONTRACT_GATES) {
            $this->markTestSkipped('The caveat is gone because the check now gates.');
        }

        $audit = $this->audit([DeclaredOnlyFixtureRegistry::class]);
        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        // A green gate reading as a clean estate is the misreading UndescribedRegistryAudit's docblock had
        // to be rewritten to prevent. The PASS must carry its own caveat or it reproduces it.
        $this->assertStringContainsString(RegistryConformanceAudit::CHECK_CONTRACT, $findings[0]->detail);
    }

    /**
     * registry-kernel ticket 42, landing 41 D11. The three cases below are one decision seen from three
     * sides: a bound subclass is IN the population, it is scored against the declaration it actually runs
     * under, and it does not collide with the class it inherits from.
     */
    public function test_a_bound_subclass_of_a_declared_registry_is_in_the_population(): void
    {
        $audit = $this->audit([InheritingFixtureRegistry::class]);

        $this->assertContains(
            InheritingFixtureRegistry::class,
            $audit->population(),
            'Beam-core ships subclassing as its extension mechanism, so a host binding a subclass is the '
            .'designed case. Filtering it out before any check runs means the gate cannot fail it.',
        );
    }

    public function test_an_inherited_declaration_is_scored_on_the_parents_written_arguments(): void
    {
        $audit = $this->audit([InheritingFixtureRegistry::class]);

        $this->assertSame(
            [],
            $this->failuresFor($audit, InheritingFixtureRegistry::class),
            'The subclass writes nothing at its own site, but it RUNS under a complete declaration. '
            .'Scoring its empty site instead would fail root/arity/onDuplicate on a class whose only '
            .'remedy is to stop extending.',
        );
    }

    public function test_a_base_and_its_bound_subclass_are_one_registry_not_a_collision(): void
    {
        $audit = $this->audit([
            ConformingFixtureRegistry::class,
            InheritingFixtureRegistry::class,
        ]);

        // Both now report root `fixture.conforming`. They are one logical registry with two seeding
        // sites — only one of them is ever the bound branch owner, so OnDuplicate::Reject never fires at
        // describe() time and there is nothing here to catch statically either.
        $this->assertNotContains(
            RegistryConformanceAudit::CHECK_ROOT_COLLISION,
            $this->failuresFor($audit, ConformingFixtureRegistry::class),
        );
        $this->assertNotContains(
            RegistryConformanceAudit::CHECK_ROOT_COLLISION,
            $this->failuresFor($audit, InheritingFixtureRegistry::class),
        );
    }

    public function test_two_unrelated_registries_on_one_root_still_collide_after_the_walk(): void
    {
        // The regression the inheritance exemption above could silently buy: if `sharesInheritance()`
        // were widened to anything looser than a parent/child relationship, this case goes quiet and the
        // collision check stops existing without a single test turning red.
        $audit = $this->audit([
            FirstContestedRootFixtureRegistry::class,
            SecondContestedRootFixtureRegistry::class,
        ]);

        $this->assertContains(
            RegistryConformanceAudit::CHECK_ROOT_COLLISION,
            $this->failuresFor($audit, FirstContestedRootFixtureRegistry::class),
        );
    }

    public function test_an_undeclared_binding_is_not_in_the_population(): void
    {
        $audit = $this->audit([]);

        $this->assertNotContains(
            self::class,
            $audit->population(),
            'The gate governs declarations only. Anything else is the advisory audit\'s subject, which is '
            .'the whole reason this one carries no suppression list.',
        );
    }
}
