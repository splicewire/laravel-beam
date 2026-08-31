<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Surgeon\RealmGateCoverageAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The audit that names a `beam.core.realm_gates` key no registered realm answers to.
 *
 * The defect it exists for produced ZERO instances at the moment it landed — all three starters shipped an
 * `os` gate for months against a realm `RealmRegistry` has never had, and the three lines were deleted by
 * hand hours earlier. So the burden on this file is heavier than usual: nothing in the estate reproduces
 * the finding, and these fixtures are the only place it is demonstrated at all.
 *
 * Three branches carry the whole audit and each has a test here — the POPULATION GATE (no gate map, which
 * is twelve of the sixteen beam-installing Herd roots including the flagship), the NEGATIVE CONTROL (a gate
 * whose realm IS registered, which is the other four), and the NAMING (an orphan gate, which is the `os`
 * line reconstructed).
 */
class RealmGateCoverageAuditTest extends TestCase
{
    private function audit(): RealmGateCoverageAudit
    {
        return new RealmGateCoverageAudit(new RealmRegistry);
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function of(array $findings, string $check): array
    {
        return array_values(array_filter($findings, fn (Finding $f) => $f->check === $check));
    }

    // ── the naming requirement ──────────────────────────────────────────────────────────────────────

    /**
     * The `os` gate, reconstructed exactly as the three starters shipped it. The assertion is on the KEY
     * appearing in the detail: a "N orphaned gates" count is what a cardinality-reporting audit would say,
     * and a reader cannot delete a config line from a number.
     */
    public function test_it_names_the_orphaned_gate_key_rather_than_counting(): void
    {
        config()->set('beam.core.realm_gates', [
            'operator' => ['entitlement' => 'operator.enter', 'mode' => 'hard'],
            'os' => ['entitlement' => 'os.enter', 'mode' => 'hard'],
        ]);

        $warnings = $this->of($this->audit()->run(), RealmGateCoverageAudit::CHECK_ORPHANED);

        $this->assertCount(1, $warnings);
        $this->assertSame(DoctorStatus::Warn, $warnings[0]->status);
        $this->assertStringContainsString('beam.core.realm_gates.os', $warnings[0]->detail);
        $this->assertStringContainsString('os.enter', $warnings[0]->detail);
        $this->assertStringNotContainsString(
            'beam.core.realm_gates.operator',
            $warnings[0]->detail,
            'a gate whose realm IS registered is not a finding.',
        );
    }

    /** Every orphan gets its own row — none is folded into a summary count. */
    public function test_every_orphaned_gate_gets_its_own_row(): void
    {
        config()->set('beam.core.realm_gates', [
            'os' => ['entitlement' => 'os.enter'],
            'workshop' => ['entitlement' => 'workshop.enter'],
        ]);

        $warnings = $this->of($this->audit()->run(), RealmGateCoverageAudit::CHECK_ORPHANED);

        $this->assertCount(2, $warnings);
        $details = implode("\n", array_map(fn (Finding $f) => $f->detail, $warnings));
        $this->assertStringContainsString('beam.core.realm_gates.os', $details);
        $this->assertStringContainsString('beam.core.realm_gates.workshop', $details);
    }

    /**
     * The finding has to say WHICH realms exist, because the repair is "delete this line or correct the
     * key" and correcting it requires knowing the candidates.
     */
    public function test_the_finding_names_the_realms_that_do_exist(): void
    {
        config()->set('beam.core.realm_gates', ['os' => ['entitlement' => 'os.enter']]);

        $warning = $this->of($this->audit()->run(), RealmGateCoverageAudit::CHECK_ORPHANED)[0];

        foreach (['operator', 'tenant', 'user', 'site'] as $realm) {
            $this->assertStringContainsString($realm, $warning->detail);
        }
    }

    /**
     * The severity claim is in the text, and it is the one thing about this audit that is easy to get
     * backwards: an orphan gate leaves nothing unguarded TODAY (there is no realm to guard) and turns
     * dangerous the day the realm is registered. A reader who takes it for an open door will go looking
     * for a hole that is not there.
     */
    public function test_it_characterises_the_orphan_as_dead_config_with_a_latent_cost(): void
    {
        config()->set('beam.core.realm_gates', ['os' => ['entitlement' => 'os.enter', 'mode' => 'hard']]);

        $warning = $this->of($this->audit()->run(), RealmGateCoverageAudit::CHECK_ORPHANED)[0];

        $this->assertStringContainsString('dead config', $warning->detail);
        $this->assertStringContainsString('Nothing is left unguarded by it today', $warning->detail);
        $this->assertStringContainsString('omitting the realm entirely', $warning->detail);
    }

    /** A soft gate is described as a soft gate — `mode` decides the latent consequence, so it is read. */
    public function test_a_soft_orphan_gate_describes_the_soft_consequence(): void
    {
        config()->set('beam.core.realm_gates', ['os' => ['entitlement' => 'os.enter', 'mode' => 'soft']]);

        $warning = $this->of($this->audit()->run(), RealmGateCoverageAudit::CHECK_ORPHANED)[0];

        $this->assertStringContainsString('soft gate fires', $warning->detail);
        $this->assertStringContainsString('locked with its upsell metadata', $warning->detail);
    }

    // ── the negative control ────────────────────────────────────────────────────────────────────────

    /**
     * The four Herd roots that DO gate. `~/Herd/audiostud` gates `operator` and `tenant`; the three
     * starters gate `operator`. None is a finding, and the audit must still emit its census.
     */
    public function test_a_gate_whose_realm_is_registered_is_not_a_finding(): void
    {
        config()->set('beam.core.realm_gates', [
            'operator' => ['entitlement' => 'operator.enter', 'mode' => 'hard'],
            'tenant' => ['entitlement' => 'tenant.enter', 'mode' => 'soft'],
        ]);

        $findings = $this->audit()->run();

        $this->assertSame([], $this->of($findings, RealmGateCoverageAudit::CHECK_ORPHANED));

        $census = $this->of($findings, RealmGateCoverageAudit::CHECK_CENSUS);
        $this->assertCount(1, $census);
        $this->assertSame(DoctorStatus::Pass, $census[0]->status);
        $this->assertStringContainsString('2 live, 0 orphaned', $census[0]->detail);
    }

    /**
     * A realm CONTRIBUTED by a capability package clears its own gate. This is why the audit reads
     * `RealmRegistry::all()` rather than the four base accessors: hard-coding them would report every
     * contributed realm's gate as an orphan.
     */
    public function test_a_contributed_realm_clears_its_gate(): void
    {
        config()->set('beam.core.realm_gates', ['workshop' => ['entitlement' => 'workshop.enter']]);

        $registry = new RealmRegistry;
        $registry->register(new RealmDefinition(key: 'workshop', routeBase: '/workshop', guard: null, central: false));

        $findings = (new RealmGateCoverageAudit($registry))->run();

        $this->assertSame([], $this->of($findings, RealmGateCoverageAudit::CHECK_ORPHANED));
    }

    // ── the population gate ─────────────────────────────────────────────────────────────────────────

    /**
     * Twelve of the sixteen beam-installing Herd roots, `~/Herd/splicewire-app` among them. A host that
     * gates nothing is not using the axis, so there is no entry that could be orphaned — and the audit
     * says so in words rather than reporting a silent clean bill.
     */
    public function test_a_host_with_no_gate_map_reports_that_nothing_was_measured(): void
    {
        config()->set('beam.core.realm_gates', []);
        config()->set('beam.realm_gates', []);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(RealmGateCoverageAudit::CHECK_CENSUS, $findings[0]->check);
        $this->assertStringContainsString('Nothing was measured', $findings[0]->detail);
        $this->assertStringContainsString('operator', $findings[0]->detail);
    }

    /**
     * The audit reproduces the projector's read INCLUDING its legacy `beam.realm_gates` fallback, and the
     * fallback is unreachable — `config/beam/core.php` ships `'realm_gates' => []`, so once beam's package
     * config is merged the primary key always EXISTS and Laravel never reaches the default argument. A
     * host that sets only the legacy spelling is therefore gateless to the projector, and this audit says
     * the same thing rather than reporting an orphan against a map nothing reads.
     *
     * Pinned because the tempting "fix" — preferring the legacy key when the primary is empty — would make
     * the audit disagree with the code it audits, which is the one thing it must never do.
     */
    public function test_it_agrees_with_the_projector_that_the_legacy_spelling_is_unreachable(): void
    {
        config()->set('beam.core.realm_gates', []);
        config()->set('beam.realm_gates', ['os' => ['entitlement' => 'os.enter']]);

        $projectorSees = (array) config('beam.core.realm_gates', config('beam.realm_gates', []));
        $this->assertSame([], $projectorSees, 'the fallback is dead once beam config is merged.');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Nothing was measured', $findings[0]->detail);
    }

    // ── computed on read ────────────────────────────────────────────────────────────────────────────

    /**
     * Nothing is stamped at construction. A realm registered after the audit is built — a consumer
     * package's provider, a host's `AppServiceProvider` — clears its own finding, rather than having boot
     * order recorded as truth about it. This is the shape that took `~/Herd/tower` off the air when a
     * sibling check got it wrong.
     */
    public function test_a_realm_registered_after_construction_clears_its_finding(): void
    {
        config()->set('beam.core.realm_gates', ['workshop' => ['entitlement' => 'workshop.enter']]);

        $registry = new RealmRegistry;
        $audit = new RealmGateCoverageAudit($registry);

        $this->assertCount(1, $this->of($audit->run(), RealmGateCoverageAudit::CHECK_ORPHANED));

        $registry->register(new RealmDefinition(key: 'workshop', routeBase: '/workshop', guard: null, central: false));

        $this->assertSame([], $this->of($audit->run(), RealmGateCoverageAudit::CHECK_ORPHANED));
    }
}
