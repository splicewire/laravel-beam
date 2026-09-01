<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\SupersededDeclarationAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * Particle-manifest-repatriation ticket 01.
 *
 * ⚠️ **The discriminating test in this file is `test_the_same_data_class_registered_with_different_fields_is_divergent`,
 * and it is the reason the audit is written the way it is.** The obvious implementation compares the
 * Data class each declaration names and calls two registrations of the same class redundant. Measured at
 * the booted flagship on 2026-08-31, that comparison reports **18 redundant / 3 divergent**; comparing
 * FIELDS reports **7 redundant / 14 divergent** over the identical 21 keys. Eleven keys name the same
 * class and differ anyway — on `group`, `includes`, `input`, `showable`, or on carrying a closure the
 * loser did not. Ticket 05 deletes registrations on the strength of this verdict, so a class-name-only
 * audit would have authorised eleven deletions that drop live behaviour behind a green suite. That test
 * was verified RED against a class-name-only `divergentFields()` before this file landed.
 *
 * The registries are hand-built rather than driven through a provider graph, deliberately and unlike
 * {@see ListedResourceDisplacementAuditTest}: that audit's defect is PRODUCED by Laravel's boot order,
 * so it has to be measured through one. This audit's subject is registry STATE — two `register()` calls
 * at one key — and boot order is only one of several ways to reach it.
 */
class SupersededDeclarationAuditTest extends TestCase
{
    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function resource(string $key, array $overrides = []): ParticleResource
    {
        return new ParticleResource(...array_merge([
            'key' => $key,
            'backing' => 'Acme\\Widgets\\'.ucfirst($key),
            'data' => 'Acme\\Data\\'.ucfirst($key).'Data',
        ], $overrides));
    }

    private function operation(string $resource, string $name, array $overrides = []): ParticleOperation
    {
        return new ParticleOperation(...array_merge([
            'resource' => $resource,
            'name' => $name,
            'kind' => OperationKind::Task,
            'handle' => fn () => null,
        ], $overrides));
    }

    /**
     * @return list<Finding>
     */
    private function findings(?ParticleResourceRegistry $resources = null, ?ParticleOperationRegistry $operations = null): array
    {
        return (new SupersededDeclarationAudit(
            $resources ?? new ParticleResourceRegistry,
            $operations ?? new ParticleOperationRegistry,
        ))->run();
    }

    /** @param  list<Finding>  $findings */
    private function checks(array $findings): array
    {
        return array_map(fn (Finding $finding) => $finding->check, $findings);
    }

    /** @param  list<Finding>  $findings */
    private function detailFor(array $findings, string $check): string
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding->detail;
            }
        }

        $this->fail("No finding for check [{$check}]; got: ".implode(', ', $this->checks($findings)));
    }

    // ── the three verdicts ──────────────────────────────────────────────────────────────────────────

    public function test_a_sole_registration_says_nothing(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('papers'));

        $findings = $this->findings($resources);

        $this->assertNotContains('resource.superseded.divergent', $this->checks($findings));
        $this->assertNotContains('resource.superseded.redundant', $this->checks($findings));
        $this->assertStringContainsString('1 sole registration', $this->detailFor($findings, 'resource.superseded'));
    }

    public function test_an_identical_re_registration_is_redundant_and_does_not_warn(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('papers'));
        $resources->register($this->resource('papers'));

        $findings = $this->findings($resources);

        $this->assertNotContains('resource.superseded.divergent', $this->checks($findings));

        $redundant = $this->detailFor($findings, 'resource.superseded.redundant');
        $this->assertStringContainsString('papers', $redundant);
        $this->assertStringContainsString('can be deleted', $redundant);

        // Advisory, and specifically not a warning: a key re-registered by its own identical
        // declaration resolves exactly what the host asked for.
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Pass, $finding->status, "[{$finding->check}] should not warn");
        }
    }

    public function test_a_different_data_class_is_divergent_and_names_both_sides(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('tokens', ['data' => 'Acme\\Data\\PackageTokenData']));
        $resources->register($this->resource('tokens', ['data' => 'Acme\\Data\\HostTokenData']));

        $detail = $this->detailFor($this->findings($resources), 'resource.superseded.divergent');

        $this->assertStringContainsString('tokens', $detail);
        $this->assertStringContainsString('data (HostTokenData over PackageTokenData)', $detail);
    }

    /**
     * ⚠️ Measured at the flagship 2026-08-31: `tokens` contests two `PersonalAccessToken` classes from
     * different namespaces, and `members` two `MembershipSource`s. Shortening both sides produced
     * `backing (PersonalAccessToken over PersonalAccessToken)`, which reads as a bug in the audit and
     * hides the only fact the reader wants.
     */
    public function test_colliding_basenames_print_in_full_rather_than_naming_the_same_class_twice(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('tokens', ['backing' => 'Laravel\\Sanctum\\PersonalAccessToken']));
        $resources->register($this->resource('tokens', ['backing' => 'Splicewire\\Tower\\Models\\PersonalAccessToken']));

        $detail = $this->detailFor($this->findings($resources), 'resource.superseded.divergent');

        $this->assertStringNotContainsString('PersonalAccessToken over PersonalAccessToken', $detail);
        $this->assertStringContainsString('Splicewire\\Tower\\Models\\PersonalAccessToken', $detail);
        $this->assertStringContainsString('Laravel\\Sanctum\\PersonalAccessToken', $detail);
    }

    /**
     * ⚠️ The discriminating case — see the class docblock. A class-name-only comparison calls this
     * redundant, and ticket 05 would then delete a registration that carries the resource's nav group,
     * its eager-load set and its write input.
     */
    public function test_the_same_data_class_registered_with_different_fields_is_divergent(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('agents', ['group' => 'tower', 'includes' => ['owner'], 'input' => 'Acme\\Data\\AgentInputData']));
        $resources->register($this->resource('agents'));

        $findings = $this->findings($resources);
        $detail = $this->detailFor($findings, 'resource.superseded.divergent');

        $this->assertStringContainsString('agents', $detail);
        $this->assertStringContainsString('includes', $detail);
        $this->assertStringContainsString('group', $detail);
        $this->assertStringContainsString('input (', $detail);

        $this->assertNotContains('resource.superseded.redundant', $this->checks($findings));
    }

    /**
     * A closure is compared by PRESENCE, which is the only comparison PHP allows and is also the
     * question that decides a deletion: eleven of the flagship's fourteen field-divergent keys are
     * divergent partly because the losing entry carried no `project`/`prepare`/`afterWrite`.
     */
    public function test_a_lost_closure_is_a_divergence(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('circuits', ['project' => fn ($model) => $model]));
        $resources->register($this->resource('circuits'));

        $detail = $this->detailFor($this->findings($resources), 'resource.superseded.divergent');

        $this->assertStringContainsString('project', $detail);
    }

    public function test_two_closures_are_indistinguishable_and_the_audit_says_so_by_reporting_redundant(): void
    {
        // The stated blind spot, pinned so it stays a KNOWN limit rather than an assumed capability.
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('threads', ['project' => fn ($model) => $model]));
        $resources->register($this->resource('threads', ['project' => fn ($model) => null]));

        $findings = $this->findings($resources);

        $this->assertNotContains('resource.superseded.divergent', $this->checks($findings));
        $this->assertStringContainsString('threads', $this->detailFor($findings, 'resource.superseded.redundant'));
    }

    public function test_the_finding_names_who_registered_the_losing_entry(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register('papers', $this->resource('papers', ['group' => 'first']), by: 'splicewire/laravel-beam-accounts');
        $resources->register($this->resource('papers', ['group' => 'second']));

        $this->assertStringContainsString(
            'splicewire/laravel-beam-accounts',
            $this->detailFor($this->findings($resources), 'resource.superseded.divergent'),
        );
    }

    // ── the census ──────────────────────────────────────────────────────────────────────────────────

    public function test_an_empty_registry_is_inconclusive_rather_than_clean(): void
    {
        $findings = $this->findings();

        foreach ($findings as $finding) {
            $this->assertFalse($finding->conclusive, "[{$finding->check}] measured nothing and must say so");
            $this->assertSame(DoctorStatus::Pass, $finding->status);
        }

        $this->assertSame(['resource.superseded', 'operation.superseded'], $this->checks($findings));
    }

    public function test_the_census_counts_all_three_classes(): void
    {
        $resources = new ParticleResourceRegistry;
        $resources->register($this->resource('sole'));
        $resources->register($this->resource('papers'));
        $resources->register($this->resource('papers'));
        $resources->register($this->resource('tokens', ['group' => 'a']));
        $resources->register($this->resource('tokens', ['group' => 'b']));

        $detail = $this->detailFor($this->findings($resources), 'resource.superseded');

        $this->assertStringContainsString('3 particle resource keys', $detail);
        $this->assertStringContainsString('1 sole registration', $detail);
        $this->assertStringContainsString('1 redundantly re-registered', $detail);
        $this->assertStringContainsString('1 divergent', $detail);
    }

    // ── operations are the same sweep ───────────────────────────────────────────────────────────────

    public function test_operations_are_audited_by_the_same_sweep(): void
    {
        $operations = new ParticleOperationRegistry;
        $operations->register($this->operation('hooks', 'reset', ['ability' => 'hooks.reset']));
        $operations->register($this->operation('hooks', 'reset'));
        $operations->register($this->operation('hooks', 'replay'));
        $operations->register($this->operation('hooks', 'replay'));

        $findings = $this->findings(null, $operations);

        $this->assertStringContainsString('hooks.reset', $this->detailFor($findings, 'operation.superseded.divergent'));
        $this->assertStringContainsString('ability', $this->detailFor($findings, 'operation.superseded.divergent'));
        $this->assertStringContainsString('hooks.replay', $this->detailFor($findings, 'operation.superseded.redundant'));
    }

    public function test_a_registry_with_no_displacement_at_all_still_reports_a_census(): void
    {
        $operations = new ParticleOperationRegistry;
        $operations->register($this->operation('hooks', 'reset'));

        $findings = $this->findings(null, $operations);

        $this->assertNotContains('operation.superseded.divergent', $this->checks($findings));
        $this->assertNotContains('operation.superseded.redundant', $this->checks($findings));
        $this->assertTrue(
            $this->findingFor($findings, 'operation.superseded')->conclusive,
            'a registry with entries but no displacement HAS measured its subject',
        );
    }

    /** @param  list<Finding>  $findings */
    private function findingFor(array $findings, string $check): Finding
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        $this->fail("No finding for check [{$check}].");
    }
}
