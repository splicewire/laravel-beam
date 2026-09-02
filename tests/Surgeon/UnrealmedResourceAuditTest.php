<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\UnrealmedResourceAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * realm-and-floor-reconciliation — the audit that names framed resources belonging to no realm.
 *
 * The registry is hand-built here rather than driven through a provider graph, because unlike
 * {@see ListedResourceDisplacementAuditTest} this audit's defect is not produced by boot ORDER: it reads
 * two declared values (`realmsFor()` and the `frame.realms` map) and compares them. What the test has to
 * pin instead is the pair of branches that decide whether it says anything at all — the SKIP when a host
 * uses no realms (19 of the 20 bootable `~/Herd` roots, measured 2026-08-30) and the NAMING when it does.
 *
 * The load-bearing assertion in this file is {@see test_it_names_the_offending_key_rather_than_counting()}.
 * `Splicewire\Beam\Doctor\FrameManifestAudit` already reports a cardinality over this exact registry and
 * is structurally unable to see the defect; an audit that reported "N resources are unrealmed" would be
 * the same failure one level up, so the key must appear in the detail string.
 */
class UnrealmedResourceAuditTest extends TestCase
{
    private function registry(): ParticleResourceRegistry
    {
        return new ParticleResourceRegistry;
    }

    private function framed(string $key): ParticleResource
    {
        return new ParticleResource(key: $key, backing: 'Acme\\Widgets\\'.ucfirst($key), label: ucfirst($key));
    }

    private function restOnly(string $key): ParticleResource
    {
        return new ParticleResource(key: $key, backing: 'Acme\\Widgets\\'.ucfirst($key));
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
     * The whole point of the unit. A count over this population is what already exists and what already
     * missed it, so the assertion is on the KEY appearing in the detail, not on the number of findings.
     */
    public function test_it_names_the_offending_key_rather_than_counting(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        $registry->register($this->framed('songs'));
        $registry->register($this->framed('steering-profiles'));

        $warnings = $this->of((new UnrealmedResourceAudit($registry))->run(), UnrealmedResourceAudit::CHECK_UNREALMED);

        $this->assertCount(1, $warnings);
        $this->assertSame(DoctorStatus::Warn, $warnings[0]->status);
        $this->assertStringContainsString('[steering-profiles]', $warnings[0]->detail);
        $this->assertStringNotContainsString('[songs]', $warnings[0]->detail, 'a realmed resource is not a finding.');
    }

    /** Every unrealmed key gets its own row — none is folded into a summary count. */
    public function test_every_unrealmed_key_gets_its_own_row(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        foreach (['songs', 'teams', 'users', 'open-api-specs'] as $key) {
            $registry->register($this->framed($key));
        }

        $details = array_map(
            fn (Finding $f) => $f->detail,
            $this->of((new UnrealmedResourceAudit($registry))->run(), UnrealmedResourceAudit::CHECK_UNREALMED),
        );

        $this->assertCount(3, $details);
        foreach (['teams', 'users', 'open-api-specs'] as $key) {
            $this->assertNotEmpty(
                array_filter($details, fn (string $d) => str_contains($d, '['.$key.']')),
                "[{$key}] must be named in its own finding."
            );
        }
    }

    /** The address a reader needs to go fix it: the Data class the key projects from. */
    public function test_it_names_the_data_class_and_the_realms_that_exist_to_be_joined(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs'], 'operator' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs'], 'operator' => ['songs']]);
        $registry->register($this->framed('songs'));
        $registry->register(new ParticleResource(
            key: 'teams',
            backing: 'Acme\\Widgets\\Team',
            data: 'Acme\\Data\\TeamData',
            label: 'Teams',
        ));

        $warnings = $this->of((new UnrealmedResourceAudit($registry))->run(), UnrealmedResourceAudit::CHECK_UNREALMED);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Acme\\Data\\TeamData', $warnings[0]->detail);
        $this->assertStringContainsString('operator, tenant', $warnings[0]->detail);
    }

    // ── the skip branch, which is what keeps this out of 19 of 20 hosts ─────────────────────────────

    /**
     * ⚠️ The measured reason this branch exists. `~/Herd/tower` registers 33 framed resources and
     * declares no realms; without the gate this audit reports 33 warnings there about a mechanism the
     * host does not use. A host that declares no membership on either rung has nothing to be outside of.
     */
    public function test_a_host_declaring_no_realms_is_a_pass_that_names_its_empty_population(): void
    {
        config()->set('frame.realms', []);

        $registry = $this->registry();
        foreach (['songs', 'teams', 'users'] as $key) {
            $registry->register($this->framed($key));
        }

        $findings = $this->of((new UnrealmedResourceAudit($registry))->run(), UnrealmedResourceAudit::CHECK_CENSUS);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('no realm membership on either rung', $findings[0]->detail);
        $this->assertStringContainsString('3 framed resources', $findings[0]->detail);
    }

    /**
     * The gate reads the declared AUTHORITY, not `config('frame.realms')` — a host that realms its
     * resources at their own `register()` call ships no map, and must still be measured. The `explicit`
     * rung has no call site in the estate today, which is precisely why nothing else would catch this.
     */
    public function test_the_population_gate_sees_the_explicit_rung_and_not_only_the_config_map(): void
    {
        config()->set('frame.realms', []);

        $registry = $this->registry();
        $registry->register($this->framed('songs'), ['tenant']);
        $registry->register($this->framed('teams'));

        $warnings = $this->of((new UnrealmedResourceAudit($registry))->run(), UnrealmedResourceAudit::CHECK_UNREALMED);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('[teams]', $warnings[0]->detail);
    }

    public function test_a_host_with_no_framed_resources_names_that_empty_population_too(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        $registry->register($this->restOnly('songs'));

        $findings = $this->of((new UnrealmedResourceAudit($registry))->run(), UnrealmedResourceAudit::CHECK_CENSUS);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('No framed particle resources', $findings[0]->detail);
    }

    /**
     * A REST-only resource has no manifest projection to be filtered out of, so it is out of population —
     * otherwise this audit would double its row count with a different consequence attached.
     */
    public function test_a_rest_only_resource_is_not_reported(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        $registry->register($this->framed('songs'));
        $registry->register($this->restOnly('audit-events'));

        $findings = (new UnrealmedResourceAudit($registry))->run();

        $this->assertSame([], $this->of($findings, UnrealmedResourceAudit::CHECK_UNREALMED));
    }

    // ── computed on read ────────────────────────────────────────────────────────────────────────────

    /**
     * Nothing is stamped at registration. A resource registered after beam's own boot — a consumer
     * package's provider, a host's AppServiceProvider — must be able to clear its own finding, rather
     * than have load order recorded as truth about it. Same rule the event-catalog outage taught.
     */
    public function test_a_realm_map_loaded_after_registration_clears_the_finding(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        $registry->register($this->framed('songs'));
        $registry->register($this->framed('teams'));

        $audit = new UnrealmedResourceAudit($registry);
        $this->assertCount(1, $this->of($audit->run(), UnrealmedResourceAudit::CHECK_UNREALMED));

        $registry->loadRealmMap(['tenant' => ['songs', 'teams']]);

        $this->assertSame([], $this->of($audit->run(), UnrealmedResourceAudit::CHECK_UNREALMED));
    }

    // ── the inverse: a mapped key nothing registered ────────────────────────────────────────────────

    /**
     * ⚠️ The negative control for a defect with ZERO live instances estate-wide (measured 2026-08-30:
     * the flagship is the only host with a map, and all 24 of its mapped keys resolve). That is the
     * argument for an instrument rather than a memory — `keysForRealm()` returns the INTERSECTION with
     * what is registered, so a typo is dropped in complete silence and no green estate can prove the
     * check works.
     */
    public function test_a_mapped_key_that_nothing_registered_is_reported_as_a_distinct_check(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs', 'sngs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs', 'sngs']]);
        $registry->register($this->framed('songs'));

        $findings = (new UnrealmedResourceAudit($registry))->run();
        $phantom = $this->of($findings, UnrealmedResourceAudit::CHECK_UNREGISTERED);

        $this->assertCount(1, $phantom);
        $this->assertSame(DoctorStatus::Warn, $phantom[0]->status);
        $this->assertStringContainsString('[sngs]', $phantom[0]->detail);
        // The realm map now has more than one seed (particle-manifest-repatriation 02), so the finding
        // names the realm and the key rather than one config path that may not be where it came from.
        $this->assertStringContainsString('realm [tenant]', $phantom[0]->detail);
        $this->assertSame([], $this->of($findings, UnrealmedResourceAudit::CHECK_UNREALMED));
    }

    /**
     * A REST-only resource is a legitimate realm member — {@see ParticleResourceRegistry::keysForRealm()}
     * has no `isFramed()` filter on purpose — so the phantom check reads every registered key, framed or
     * not. Narrowing it to the framed set would report every REST-only member as a typo.
     */
    public function test_a_rest_only_member_of_a_realm_is_not_a_phantom(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs', 'audit-events']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs', 'audit-events']]);
        $registry->register($this->framed('songs'));
        $registry->register($this->restOnly('audit-events'));

        $findings = (new UnrealmedResourceAudit($registry))->run();

        $this->assertSame([], $this->of($findings, UnrealmedResourceAudit::CHECK_UNREGISTERED));
        $this->assertSame([], $this->of($findings, UnrealmedResourceAudit::CHECK_UNREALMED));
    }

    // ── advisory, permanently ───────────────────────────────────────────────────────────────────────

    /**
     * Whether a resource is realmed here is a fact about the HOST. Nothing this audit emits may be a
     * Fail, and the registration carries no gate — the estate bought that rule with an outage that left
     * `~/Herd/tower` unable to boot.
     */
    public function test_it_never_fails_and_is_registered_advisory(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs', 'sngs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs', 'sngs']]);
        $registry->register($this->framed('songs'));
        $registry->register($this->framed('teams'));

        foreach ((new UnrealmedResourceAudit($registry))->run() as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status, $finding->check.' must never Fail.');
        }

        $registration = array_values(array_filter(
            $this->app->make(BeamDoctorManifest::class)->registrations(),
            fn ($r) => $r->audit === UnrealmedResourceAudit::class,
        ));

        $this->assertCount(1, $registration, 'the audit must be registered in the beam doctor manifest.');
        $this->assertFalse($registration[0]->gate, 'advisory, permanently.');
    }

    /** The census line renders whether or not anything warned, and reports both sides of the split. */
    public function test_the_census_line_reports_realmed_and_unrealmed(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        $registry->register($this->framed('songs'));
        $registry->register($this->framed('teams'));

        $census = $this->of((new UnrealmedResourceAudit($registry))->run(), UnrealmedResourceAudit::CHECK_CENSUS);

        $this->assertCount(1, $census);
        $this->assertSame(DoctorStatus::Pass, $census[0]->status);
        $this->assertStringContainsString('1 realmed, 1 reachable through no realm', $census[0]->detail);
    }
    // ── backing applicability: a registration whose table exists nowhere ────────────────────────────

    /**
     * A probe standing in for the database: `table => exists`, with a key that is absent from the map
     * answering null — "this host could not look".
     *
     * @param  array<string, bool>  $tables
     */
    private function probe(array $tables): \Closure
    {
        return fn (?string $connection, string $table): ?bool => $tables[$table] ?? null;
    }

    /**
     * api-surface-coherence 139. Three registrations at `~/Herd/splicewire-app` — `teams`, `access-grants`,
     * `view-requests` — declare a model whose table exists on NO connection or schema at that host. Their
     * realm membership is beside the point: a route or realm serving them answers with a query error. A
     * "deliberately API-less" flag would have let someone stamp them intentional and close over the
     * defect, so the honest instrument is a fact about the backing, and the key must be named.
     */
    public function test_a_model_backed_resource_whose_table_exists_nowhere_is_a_dead_registration(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        $registry->register(new ParticleResource(key: 'songs', backing: UnrealmedFixtureSong::class, label: 'Songs'));
        $registry->register(new ParticleResource(key: 'teams', backing: UnrealmedFixtureTeam::class, label: 'Teams'));

        $audit = new UnrealmedResourceAudit($registry, tableExists: $this->probe(['songs' => true, 'beam_teams' => false]));
        $absent = $this->of($audit->run(), UnrealmedResourceAudit::CHECK_UNBACKED);

        $this->assertCount(1, $absent);
        $this->assertSame(DoctorStatus::Warn, $absent[0]->status);
        $this->assertStringContainsString('[teams]', $absent[0]->detail);
        $this->assertStringContainsString('beam_teams', $absent[0]->detail);
        $this->assertStringContainsString(UnrealmedFixtureTeam::class, $absent[0]->detail);
        $this->assertStringNotContainsString('[songs]', $absent[0]->detail, 'a backed resource is not a finding.');
    }

    /**
     * Two of the three live instances (`access-grants`, `view-requests`) are REST-only, and the realm
     * checks above deliberately exclude the unframed set. Backing is a different fact with a different
     * population: every registration that names a model, framed or not.
     */
    public function test_the_backing_check_reads_rest_only_resources_too(): void
    {
        config()->set('frame.realms', ['tenant' => ['songs']]);

        $registry = $this->registry()->loadRealmMap(['tenant' => ['songs']]);
        $registry->register(new ParticleResource(key: 'songs', backing: UnrealmedFixtureSong::class, label: 'Songs'));
        $registry->register(new ParticleResource(key: 'access-grants', backing: UnrealmedFixtureTeam::class));

        $audit = new UnrealmedResourceAudit($registry, tableExists: $this->probe(['songs' => true, 'beam_teams' => false]));
        $findings = $audit->run();

        $absent = $this->of($findings, UnrealmedResourceAudit::CHECK_UNBACKED);
        $this->assertCount(1, $absent);
        $this->assertStringContainsString('[access-grants]', $absent[0]->detail);
        $this->assertSame([], $this->of($findings, UnrealmedResourceAudit::CHECK_UNREALMED), 'REST-only stays out of the realm check.');
    }

    /**
     * Independent of the realm population gate: `~/Herd/tower` declares no realms and must still hear
     * about a table that is missing. The gate is about the realm axis, not about the database.
     */
    public function test_the_backing_check_runs_at_a_host_that_declares_no_realms(): void
    {
        config()->set('frame.realms', []);

        $registry = $this->registry();
        $registry->register(new ParticleResource(key: 'teams', backing: UnrealmedFixtureTeam::class, label: 'Teams'));

        $audit = new UnrealmedResourceAudit($registry, tableExists: $this->probe(['beam_teams' => false]));
        $findings = $audit->run();

        $this->assertCount(1, $this->of($findings, UnrealmedResourceAudit::CHECK_UNBACKED));
        $this->assertStringContainsString('no realm membership', $this->of($findings, UnrealmedResourceAudit::CHECK_CENSUS)[0]->detail);
    }

    /**
     * A connection this host cannot open, a driver whose catalog cannot be read, a model that will not
     * construct: the answer is "did not look", which is not "absent". The estate's signature defect is
     * an instrument that cannot tell the two apart, so the unknown set is named on its own line and
     * never warned.
     */
    public function test_a_backing_the_host_cannot_probe_is_inconclusive_not_absent(): void
    {
        config()->set('frame.realms', []);

        $registry = $this->registry();
        $registry->register(new ParticleResource(key: 'songs', backing: UnrealmedFixtureSong::class));
        $registry->register(new ParticleResource(key: 'remote', backing: UnrealmedFixtureRemote::class));

        $audit = new UnrealmedResourceAudit($registry, tableExists: $this->probe(['songs' => true]));
        $findings = $audit->run();

        $this->assertSame([], $this->of($findings, UnrealmedResourceAudit::CHECK_UNBACKED));

        $backing = $this->of($findings, UnrealmedResourceAudit::CHECK_BACKING);
        $this->assertCount(1, $backing);
        $this->assertSame(DoctorStatus::Pass, $backing[0]->status);
        $this->assertFalse($backing[0]->conclusive, 'did not look, so it cannot claim to have measured.');
        $this->assertStringContainsString('[remote]', $backing[0]->detail);
        $this->assertStringContainsString('1 backed', $backing[0]->detail);
    }

    /** The backing census reports the split and the source-backed resources it had nothing to probe for. */
    public function test_the_backing_census_reports_backed_absent_and_source_backed(): void
    {
        config()->set('frame.realms', []);

        $registry = $this->registry();
        $registry->register(new ParticleResource(key: 'songs', backing: UnrealmedFixtureSong::class));
        $registry->register(new ParticleResource(key: 'teams', backing: UnrealmedFixtureTeam::class));
        $registry->register(new ParticleResource(key: 'members', backing: UnrealmedFixtureSource::class, readOnly: true));

        $audit = new UnrealmedResourceAudit($registry, tableExists: $this->probe(['songs' => true, 'beam_teams' => false]));
        $backing = $this->of($audit->run(), UnrealmedResourceAudit::CHECK_BACKING);

        $this->assertCount(1, $backing);
        $this->assertSame(DoctorStatus::Pass, $backing[0]->status);
        $this->assertStringContainsString('1 backed, 1 absent', $backing[0]->detail);
        $this->assertStringContainsString('1 source-backed', $backing[0]->detail);
    }

    /** With no database handed in and no probe, the default answer is "did not look" — never a warning. */
    public function test_without_a_database_the_backing_check_says_it_did_not_look(): void
    {
        config()->set('frame.realms', []);

        $registry = $this->registry();
        $registry->register(new ParticleResource(key: 'teams', backing: UnrealmedFixtureTeam::class));

        $findings = (new UnrealmedResourceAudit($registry))->run();

        $this->assertSame([], $this->of($findings, UnrealmedResourceAudit::CHECK_UNBACKED));
        $this->assertFalse($this->of($findings, UnrealmedResourceAudit::CHECK_BACKING)[0]->conclusive);
    }
}

// ── fixtures: the smallest models that carry a table name ───────────────────────────────────────────

class UnrealmedFixtureSong extends Model
{
    protected $table = 'songs';
}

class UnrealmedFixtureTeam extends Model
{
    protected $table = 'beam_teams';
}

class UnrealmedFixtureRemote extends Model
{
    protected $table = 'remote_rows';

    protected $connection = 'remote';
}

/** A source-backed resource declares no model at all (`members`, `review-queue` at the flagship). */
class UnrealmedFixtureSource implements ResourceBacking {}
