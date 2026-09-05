<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\UnseatedNavSectionAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The audit for the defect the {@see NavSectionRegistry} seam was built to close: a section a package
 * DECLARED on its resources and nothing SEATS at this host.
 *
 * ⚠️ **The negative controls carry this file, not the positive case.** The positive — "an unseated
 * package section is warned" — is satisfied by an audit that warns on everything, and an audit that
 * warns on everything gets switched off within a week. So the tests that matter are the four that must
 * stay silent: a seated section, a HOST-declared unseated section, an empty population, and an empty
 * registry. Two of those four are reported as INCONCLUSIVE rather than as a pass, because "nothing is
 * wrong" and "nothing was measured" produce the same empty finding list and this estate's signature
 * defect is spelling them the same way.
 */
class UnseatedNavSectionAuditTest extends TestCase
{
    private ParticleResourceRegistry $resources;

    private NavSectionRegistry $sections;

    protected function setUp(): void
    {
        parent::setUp();

        // Built by hand rather than resolved: the container's registries carry beam's own discovered
        // declarations (`schemas` → authoring, `git-repo` → ops, `hooks` → platform), and a test whose
        // population shifts when an unrelated declaration is added is not measuring this audit.
        $this->resources = new ParticleResourceRegistry;
        $this->sections = new NavSectionRegistry;
    }

    private function audit(): UnseatedNavSectionAudit
    {
        return new UnseatedNavSectionAudit($this->resources, $this->sections);
    }

    /** A resource whose Data class namespace reads as a family PACKAGE declaration. */
    private function packageResource(string $key, ?string $section): void
    {
        $this->resources->register(new ParticleResource(
            key: $key,
            backing: 'Splicewire\\Beam\\Tests\\Fixtures\\Widget',
            data: 'Splicewire\\Beam\\Tests\\Fixtures\\WidgetData',
            section: $section,
        ));
    }

    /** A resource whose Data class namespace reads as HOST app code. */
    private function hostResource(string $key, ?string $section): void
    {
        $this->resources->register(new ParticleResource(
            key: $key,
            backing: 'App\\Models\\Widget',
            data: 'App\\Data\\WidgetData',
            section: $section,
        ));
    }

    private function seat(string $key, string $realm = 'tenant'): void
    {
        $this->sections->register(new NavSection(
            key: $key, realm: $realm, label: ucfirst($key), icon: 'Square',
            href: '/'.$key, order: 10, entitlement: null, permission: null,
        ));
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function of(array $findings, string $check): array
    {
        return array_values(array_filter($findings, fn (Finding $f): bool => $f->check === $check));
    }

    // ── the defect ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_package_declared_section_nothing_seats_is_warned(): void
    {
        $this->packageResource('calendars', 'calendars');
        $this->packageResource('calendar-events', 'calendars');

        $warnings = $this->of($this->audit()->run(), UnseatedNavSectionAudit::CHECK);

        $this->assertCount(1, $warnings);
        $this->assertSame(DoctorStatus::Warn, $warnings[0]->status);
        $this->assertStringContainsString('`calendars`', $warnings[0]->detail);
        $this->assertStringContainsString('calendar-events', $warnings[0]->detail);
    }

    public function test_the_finding_names_all_three_ways_the_row_can_be_correct(): void
    {
        $this->packageResource('git-repo', 'ops');

        $warning = $this->of($this->audit()->run(), UnseatedNavSectionAudit::CHECK)[0];

        // Advisory means the reader must be able to close the row without changing anything, and the
        // text has to say how. UnindexedRegistryAudit's remedy was corrected once for prescribing a
        // regression; this one prescribes nothing it cannot see.
        $this->assertStringContainsString('ADVISORY', $warning->detail);
        $this->assertStringContainsString('HAND-AUTHORED', $warning->detail);
        $this->assertStringContainsString('no realm here', $warning->detail);
    }

    public function test_unseated_reports_the_sections_and_their_resource_keys(): void
    {
        $this->packageResource('a', 'ops');
        $this->packageResource('b', 'ops');
        $this->packageResource('c', 'authoring');
        $this->seat('authoring');

        $this->assertSame(['ops' => ['a', 'b']], $this->audit()->unseated());
    }

    // ── negative controls ───────────────────────────────────────────────────────────────────────────

    public function test_a_seated_section_is_not_warned(): void
    {
        $this->packageResource('calendars', 'calendars');
        $this->seat('calendars');

        $this->assertSame([], $this->of($this->audit()->run(), UnseatedNavSectionAudit::CHECK));
    }

    public function test_a_seat_in_any_realm_at_all_counts_as_seated(): void
    {
        // A package declares for every realm it plausibly owns because realm membership is the host's
        // list. Intersecting seat realm against resource realm would report the package that did the
        // right thing — declared for both — as unseated in whichever one came back empty.
        $this->packageResource('calendars', 'calendars');
        $this->seat('calendars', realm: 'operator');

        $this->assertSame([], $this->of($this->audit()->run(), UnseatedNavSectionAudit::CHECK));
    }

    public function test_a_host_declared_unseated_section_is_counted_and_never_warned(): void
    {
        // The normal arrangement: the host declares a section on its own resource and seats it in its
        // own hand-authored navigation, which this audit structurally cannot read. Warning on it would
        // file the flagship's entire nav every run.
        $this->hostResource('compositions', 'studio');

        $findings = $this->audit()->run();

        $this->assertSame([], $this->of($findings, UnseatedNavSectionAudit::CHECK));

        $census = $this->of($findings, UnseatedNavSectionAudit::CHECK_CENSUS)[0];
        $this->assertSame(DoctorStatus::Pass, $census->status);
        $this->assertStringContainsString('Unseated host sections: studio', $census->detail);
    }

    public function test_a_section_declared_by_both_a_package_and_the_host_is_warned(): void
    {
        // Provenance is per-RESOURCE, and the section is the unit of seating. One package resource
        // naming a section is enough for the seat to be missing for that package.
        $this->hostResource('host-thing', 'ops');
        $this->packageResource('package-thing', 'ops');

        $this->assertArrayHasKey('ops', $this->audit()->unseated());
    }

    // ── blindness, separated from health ────────────────────────────────────────────────────────────

    public function test_an_empty_registry_is_inconclusive_not_a_pass(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('No particle resource is registered', $findings[0]->detail);
    }

    public function test_resources_that_declare_no_section_at_all_are_inconclusive_not_a_pass(): void
    {
        // The population is empty, so the seating question was never asked. A pass here would be a
        // statement about a set with no members — and would read identically to a clean host.
        $this->packageResource('users', null);
        $this->packageResource('tokens', null);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('population this audit measures is EMPTY', $findings[0]->detail);
    }

    public function test_a_fully_seated_host_earns_a_conclusive_pass(): void
    {
        $this->packageResource('calendars', 'calendars');
        $this->seat('calendars');

        $census = $this->of($this->audit()->run(), UnseatedNavSectionAudit::CHECK_CENSUS)[0];

        $this->assertSame(DoctorStatus::Pass, $census->status);
        $this->assertTrue($census->conclusive);
        $this->assertStringContainsString('Unseated package sections: none', $census->detail);
    }

    /**
     * ⚠️ Added because a MUTATION came back green. Collapsing `census()` to an unconditional
     * `Finding::pass()` left all thirteen other tests passing: every one of them asserted the census
     * status only on the CLEAN arm, so the line that makes the census itself say "something is unseated
     * here" was carried by nothing. A summary that reads Pass while naming warned rows underneath it is
     * the exact shape of an instrument that lies.
     */
    public function test_the_census_itself_warns_when_a_package_section_is_unseated(): void
    {
        $this->packageResource('calendars', 'calendars');

        $census = $this->of($this->audit()->run(), UnseatedNavSectionAudit::CHECK_CENSUS)[0];

        $this->assertSame(DoctorStatus::Warn, $census->status);
        $this->assertStringContainsString('Unseated package sections: calendars', $census->detail);
    }

    // ── the census must state what it does not cover ────────────────────────────────────────────────

    public function test_the_census_states_the_hand_authored_blind_spot_and_the_provenance_heuristic(): void
    {
        $this->packageResource('calendars', 'calendars');

        $census = $this->of($this->audit()->run(), UnseatedNavSectionAudit::CHECK_CENSUS)[0];

        $this->assertStringContainsString('NOT covered', $census->detail);
        $this->assertStringContainsString('heuristic', $census->detail);
    }

    // ── wiring ──────────────────────────────────────────────────────────────────────────────────────

    public function test_it_is_registered_on_the_doctor_manifest_and_never_gates(): void
    {
        $registration = null;

        foreach ($this->app->make(BeamDoctorManifest::class)->registrations() as $row) {
            if ($row->audit === UnseatedNavSectionAudit::class) {
                $registration = $row;
            }
        }

        $this->assertNotNull($registration, 'UnseatedNavSectionAudit is not on the BeamDoctorManifest.');
        $this->assertFalse($registration->gate, 'A check whose answer depends on the host must not gate.');
        $this->assertSame('splicewire/laravel-beam', $registration->package);
    }

    public function test_the_container_can_build_it(): void
    {
        // Both constructor arguments are singletons bound by BeamServiceProvider, so the manifest's
        // resolution of the class by name has to work without a bespoke binding.
        $this->assertInstanceOf(
            UnseatedNavSectionAudit::class,
            $this->app->make(UnseatedNavSectionAudit::class),
        );
    }
}
