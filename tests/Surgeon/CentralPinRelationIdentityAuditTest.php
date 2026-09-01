<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\CentralPinRelationIdentityAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The relation-identity audit: does a central pin change WHICH pg_class relation is touched?
 *
 * The census is stubbed rather than parsed. {@see CentralPinJustificationAuditTest} already owns the
 * question "does the parser find this pin", in all three forms; re-deriving it here would couple this file
 * to a grammar it does not test and would additionally have to fight the census's own `*Test` and
 * excluded-namespace filters to plant a fixture. What THIS file has to pin is the other half — the
 * verdict rule, and the five gate paths that decide whether a verdict is emitted at all.
 *
 * The load-bearing assertions are the `unknown` ones. A comparison run at a host with no tenant schemas,
 * or against an isolated-database tenant, would report every pin as `convergent` — the most confidently
 * wrong output this audit could produce, and the one an operator is most likely to act on. So each gate is
 * tested for the ABSENCE of a convergent verdict, not merely for the presence of an inconclusive one.
 */
class CentralPinRelationIdentityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.central', ['driver' => 'pgsql', 'search_path' => 'public']);
        config()->set('tenancy.database.managers.pgsql', 'Splicewire\\Beam\\Tenancy\\HybridPostgresTenantDatabaseManager');
        config()->set('tenancy.database.prefix', 'tenant_');
    }

    /**
     * @param  list<array<string, mixed>>  $pins
     */
    private function audit(
        array $pins,
        array $schemas = ['tenant_one'],
        array $oids = [],
        ?int $isolated = 0,
    ): CentralPinRelationIdentityAudit {
        return new CentralPinRelationIdentityAudit(
            new StubPinCensus($pins),
            fn () => $schemas,
            // The probe seam: `[searchPath => [relation => oid]]`, missing entries meaning "does not
            // resolve", which is exactly what `to_regclass()` returns as NULL.
            fn (string $frame, array $relations) => array_combine(
                $relations,
                array_map(fn (string $relation) => $oids[$frame][$relation] ?? null, $relations),
            ),
            fn () => $isolated,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pin(string $class, string $form = 'property', array $targets = []): array
    {
        return [
            'class' => $class,
            'file' => '/pkg/src/'.class_basename($class).'.php',
            'line' => 20,
            'form' => $form,
            'targets' => $targets,
            'citation' => null,
            'justified' => false,
        ];
    }

    private function details(array $findings): string
    {
        return implode("\n", array_map(fn ($f) => $f->check.' :: '.$f->detail, $findings));
    }

    public function test_it_reports_convergent_when_both_frames_resolve_the_same_oid(): void
    {
        $findings = $this->audit(
            [$this->pin(FixtureCentralLead::class)],
            oids: [
                'public' => ['leads' => 634194833],
                'tenant_one,public' => ['leads' => 634194833],
            ],
        )->run();

        $convergent = $this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_CONVERGENT);

        $this->assertNotNull($convergent);
        $this->assertSame(DoctorStatus::Warn, $convergent->status);
        $this->assertStringContainsString('leads', $convergent->detail);
        $this->assertStringContainsString('634194833', $convergent->detail);
    }

    public function test_it_reports_divergent_and_does_not_warn_about_it(): void
    {
        $findings = $this->audit(
            [$this->pin(FixtureCentralRole::class)],
            oids: [
                'public' => ['roles' => 634194467],
                'tenant_one,public' => ['roles' => 634226777],
            ],
        )->run();

        // Divergence is the pin doing its visible job. It belongs in the census, not in a backlog.
        $this->assertSame(
            [CentralPinRelationIdentityAudit::CHECK_CENSUS],
            array_values(array_unique(array_map(fn ($f) => $f->check, $findings))),
        );
        $this->assertStringContainsString('divergent — FixtureCentralRole -> roles', $this->details($findings));
    }

    public function test_it_reports_unresolvable_when_the_pinned_frame_cannot_see_the_relation(): void
    {
        $findings = $this->audit(
            [$this->pin(FixtureCentralAgent::class)],
            oids: [
                // Present in the tenant schema only — a query through the pin raises `does not exist`.
                'tenant_one,public' => ['agents' => 634227579],
            ],
        )->run();

        $finding = $this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_UNRESOLVABLE);

        $this->assertNotNull($finding);
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('does not exist', $finding->detail);
        $this->assertStringContainsString('634227579', $finding->detail);
    }

    /**
     * A relation absent from BOTH frames is `unresolvable`, never `convergent`. Convergence on nothing is
     * the row-count proxy's mistake wearing OIDs — two empty answers are not agreement.
     */
    public function test_a_relation_absent_everywhere_is_unresolvable_rather_than_convergent(): void
    {
        $findings = $this->audit([$this->pin(FixtureCentralAgent::class)])->run();

        $this->assertNotNull($this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_UNRESOLVABLE));
        $this->assertNull($this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_CONVERGENT));
    }

    /**
     * ⚠️ The regression for the bug the estate run found and the unit tests could not.
     *
     * The first cut compared the tenant frames with EACH OTHER and called disagreement `mixed`. Run at
     * `~/Herd/splicewire-app` on 2026-09-01 it returned `mixed` for all seven genuinely divergent relations,
     * because every tenant schema holds its own physical `roles` with its own OID — eighteen distinct OIDs
     * is what divergence looks like. The comparison is per-tenant against CENTRAL, and the answer being
     * compared across tenants is a boolean, not an OID. A single-tenant fixture cannot express this, which
     * is why it took a host to find it.
     */
    public function test_distinct_per_tenant_oids_are_divergent_not_mixed(): void
    {
        $findings = $this->audit(
            [$this->pin(FixtureCentralRole::class)],
            schemas: ['tenant_one', 'tenant_two', 'tenant_three'],
            oids: [
                'public' => ['roles' => 100],
                'tenant_one,public' => ['roles' => 201],
                'tenant_two,public' => ['roles' => 202],
                'tenant_three,public' => ['roles' => 203],
            ],
        )->run();

        $this->assertNull($this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_MIXED));
        $this->assertStringContainsString('divergent — FixtureCentralRole -> roles', $this->details($findings));
    }

    /**
     * Attack 4. Zero live instances on this estate as of 2026-09-01 — which is the argument FOR the branch,
     * not against it: the state is reachable the moment a tenant migration is part-way through a rollout,
     * and a single-tenant sample would report one of these groups as the whole answer.
     */
    public function test_partial_replication_is_mixed_and_names_every_disagreeing_schema(): void
    {
        $findings = $this->audit(
            [$this->pin(FixtureCentralRole::class)],
            schemas: ['tenant_one', 'tenant_two', 'tenant_three'],
            oids: [
                'public' => ['roles' => 100],
                'tenant_one,public' => ['roles' => 201],
                'tenant_two,public' => ['roles' => 100],
                'tenant_three,public' => ['roles' => 100],
            ],
        )->run();

        $finding = $this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_MIXED);

        $this->assertNotNull($finding);
        $this->assertStringContainsString('tenant_one', $finding->detail);
        $this->assertStringContainsString('tenant_two', $finding->detail);
        $this->assertStringContainsString('tenant_three', $finding->detail);
        $this->assertNull($this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_CONVERGENT));
    }

    /**
     * ⚠️ Attack 5, and the specific failure this map already committed once. `~/Herd/tower` has exactly one
     * schema, `public`. A comparison run there resolves both frames to the same OID for every relation and
     * would report the whole census convergent — confidently, and wrongly.
     */
    public function test_a_host_with_no_tenant_schemas_reports_unknown_and_never_convergent(): void
    {
        $findings = $this->audit([$this->pin(FixtureCentralLead::class)], schemas: [])->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('UNKNOWN', $findings[0]->detail);
        $this->assertNull($this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_CONVERGENT));
        // The gate says the quiet part out loud: a comparison run anyway WOULD have said convergent here.
        $this->assertStringContainsString('NOT a report that the pins are convergent', $findings[0]->detail);
        // The gate still NAMES what it declined to measure.
        $this->assertStringContainsString('FixtureCentralLead', $findings[0]->detail);
    }

    /**
     * Attack 2. ⚠️ Zero tenants on this estate carry the marker, so this branch is written conservatively
     * rather than verified against a real isolated tenant. pg_class OIDs are per-catalog: the probe cannot
     * see the other database even to fail honestly.
     */
    public function test_an_isolated_database_tenant_gates_the_audit_out(): void
    {
        $findings = $this->audit([$this->pin(FixtureCentralLead::class)], isolated: 1)->run();

        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('isolated_database_destination', $findings[0]->detail);
        $this->assertNull($this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_CONVERGENT));
    }

    /** An undeterminable isolation count is treated as blocking, never as zero. */
    public function test_an_undeterminable_isolation_count_is_unknown_not_zero(): void
    {
        $findings = $this->audit([$this->pin(FixtureCentralLead::class)], isolated: null)->run();

        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('could not be determined', $findings[0]->detail);
    }

    public function test_a_non_pgsql_central_connection_is_unknown(): void
    {
        config()->set('database.connections.central', ['driver' => 'sqlite']);

        $findings = $this->audit([$this->pin(FixtureCentralLead::class)])->run();

        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('PostgreSQL-specific', $findings[0]->detail);
    }

    public function test_a_host_with_no_central_connection_is_unknown(): void
    {
        config()->set('database.connections.central', null);

        $findings = $this->audit([$this->pin(FixtureCentralLead::class)])->run();

        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('CentralPinResolvabilityAudit', $findings[0]->detail);
    }

    public function test_a_host_with_no_tenant_manager_is_unknown(): void
    {
        config()->set('tenancy.database.managers.pgsql', null);

        $findings = $this->audit([$this->pin(FixtureCentralLead::class)])->run();

        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('tenant database manager', $findings[0]->detail);
    }

    public function test_an_empty_census_is_inconclusive(): void
    {
        $findings = $this->audit([])->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
    }

    /**
     * A pin through `DB::connection('central')` has no model and therefore no relation. It is NAMED as
     * `no-relation` rather than filtered out: a population that quietly shrinks is exactly the defect
     * `FrameManifestAudit` embodies, and four of the flagship's twenty-six pins are in this class.
     */
    public function test_a_pin_with_no_model_is_named_rather_than_dropped(): void
    {
        $findings = $this->audit([
            $this->pin('Database\\Seeders\\DemoSeeder', form: 'call', targets: ['Illuminate\\Support\\Facades\\DB']),
        ])->run();

        $this->assertStringContainsString('no-relation — DemoSeeder (no model)', $this->details($findings));
    }

    /** A constant-form pin reaches its models through `targets`, and one pin can carry two relations. */
    public function test_a_constant_form_pin_resolves_every_target_model(): void
    {
        $findings = $this->audit(
            [$this->pin(
                'Splicewire\\Beam\\Ux\\Lifecycle\\EntryPromoter',
                form: 'constant',
                targets: [FixtureCentralRole::class, FixtureCentralLead::class],
            )],
            oids: [
                'public' => ['roles' => 100, 'leads' => 300],
                'tenant_one,public' => ['roles' => 200, 'leads' => 300],
            ],
        )->run();

        $detail = $this->details($findings);
        $this->assertStringContainsString('divergent — EntryPromoter -> roles', $detail);
        $this->assertStringContainsString('convergent — EntryPromoter -> leads', $detail);
    }

    /**
     * The `FrameManifestAudit` guard. That audit resolves a registry and prints a cardinality, and its N
     * was right while 16 of 36 framed resources reached no realm. Every finding here must carry the pin's
     * name and its relation, so the same defect cannot recur in this instrument.
     */
    public function test_it_names_pins_and_relations_rather_than_counting_them(): void
    {
        $findings = $this->audit(
            [$this->pin(FixtureCentralLead::class), $this->pin(FixtureCentralRole::class)],
            oids: [
                'public' => ['leads' => 1, 'roles' => 2],
                'tenant_one,public' => ['leads' => 1, 'roles' => 3],
            ],
        )->run();

        $detail = $this->details($findings);

        foreach (['FixtureCentralLead', 'leads', 'FixtureCentralRole', 'roles', 'tenant_one'] as $token) {
            $this->assertStringContainsString($token, $detail);
        }
    }

    /**
     * The three sentences this audit is built to be incapable of saying. `convergent` is the verdict an
     * operator is most likely to misread as a licence to delete a pin, so the disclaimer is asserted rather
     * than merely written: `central` is a separate PDO handle, and the verdict is a snapshot.
     */
    public function test_a_convergent_verdict_refuses_to_license_removing_the_pin(): void
    {
        $findings = $this->audit(
            [$this->pin(FixtureCentralLead::class)],
            oids: ['public' => ['leads' => 1], 'tenant_one,public' => ['leads' => 1]],
        )->run();

        $detail = $this->findingFor($findings, CentralPinRelationIdentityAudit::CHECK_CONVERGENT)->detail;

        $this->assertStringContainsString('NOT a finding that the pin is unnecessary', $detail);
        $this->assertStringContainsString('SEPARATE PDO handle', $detail);
        $this->assertStringContainsString('snapshot', $detail);
    }

    /** Nothing above `warn` is emitted at any verdict — the audit is advisory by construction, not by wiring. */
    public function test_it_never_emits_a_fail_at_any_verdict(): void
    {
        $findings = $this->audit(
            [
                $this->pin(FixtureCentralLead::class),
                $this->pin(FixtureCentralRole::class),
                $this->pin(FixtureCentralAgent::class),
            ],
            schemas: ['tenant_one', 'tenant_two'],
            oids: [
                'public' => ['leads' => 1, 'roles' => 2],
                'tenant_one,public' => ['leads' => 1, 'roles' => 3, 'agents' => 9],
                'tenant_two,public' => ['leads' => 1, 'roles' => 4, 'agents' => 9],
            ],
        )->run();

        foreach ($findings as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status, $finding->check);
        }
    }

    private function findingFor(array $findings, string $check): ?object
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        return null;
    }
}

/**
 * A census whose rows are supplied rather than parsed. See the class docblock for why the parser is not
 * exercised here.
 */
class StubPinCensus extends CentralPinJustificationAudit
{
    public function __construct(private array $rows)
    {
        parent::__construct([]);
    }

    public function pins(): array
    {
        return $this->rows;
    }
}

class FixtureCentralLead extends Model
{
    protected $table = 'leads';
}

class FixtureCentralRole extends Model
{
    protected $table = 'roles';
}

class FixtureCentralAgent extends Model
{
    protected $table = 'agents';
}
