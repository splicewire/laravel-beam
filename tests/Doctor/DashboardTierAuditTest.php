<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Rushing\Doctor\DoctorStatus;
use Schemastud\Frame\Attributes\Overview;
use Schemastud\Frame\Attributes\Summary;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Doctor\DashboardTierAudit;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ScopedIndexQuery;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see DashboardTierAudit} — the triage instrument for a realm's dashboard cards (realm-dashboards 04).
 *
 * Built on a fresh registry and a fresh seat registry so the population is exactly the fixture. Every
 * tier has a row and every "not on the dashboard" rule has a resource that would be counted without it,
 * so an audit that counted everything seated, or everything registered, fails an exact-name assertion.
 */
class DashboardTierAuditTest extends TestCase
{
    private ParticleResourceRegistry $resources;

    private NavSectionRegistry $sections;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resources = new ParticleResourceRegistry;
        $this->sections = new NavSectionRegistry;

        $this->sections->register(
            new NavSection(key: 'platform', realm: 'operator', label: 'Platform', icon: 'Server', href: '/platform', order: 10, entitlement: null, permission: null),
            by: self::class,
        );
    }

    private function declare(string $key, string $backing, string $data = WidgetGateData::class, ?string $section = 'platform', ?string $provider = null, array $realms = ['operator']): void
    {
        $this->resources->register(new ParticleResource(
            key: $key,
            backing: $backing,
            data: $data,
            label: ucfirst($key),
            section: $section,
            readOnly: true,
            summaryProvider: $provider,
        ), $realms);
    }

    private function audit(): DashboardTierAudit
    {
        return new DashboardTierAudit(
            $this->resources,
            $this->app->make(RealmRegistry::class),
            $this->sections,
            new ScopedIndexQuery($this->resources),
            $this->app,
        );
    }

    public function test_no_resource_on_any_dashboard_is_inconclusive_rather_than_clean(): void
    {
        // Registered and countable, but unseated and undeclared — on no dashboard.
        $this->declare('loose', BeamSchema::class, section: null);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertSame(DashboardTierAudit::CHECK, $findings[0]->check);
    }

    public function test_a_seated_resource_with_no_binding_and_the_default_provider_warns_as_derived_by_name(): void
    {
        $this->declare('widgets', BeamSchema::class);
        // Same shape, NOT seated in this realm's registry — the seat is what puts it on the dashboard.
        $this->declare('unseated', BeamSchema::class, section: 'nowhere');
        // Same shape, seated, but opted out.
        $this->declare('hidden', BeamSchema::class, data: OptedOutTierData::class);
        // Same shape, in a realm nobody seated `platform` in.
        $this->declare('elsewhere', BeamSchema::class, realms: ['tenant']);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('DERIVED', $findings[0]->detail);
        $this->assertStringContainsString('[operator/widgets]', $findings[0]->detail);
        $this->assertStringNotContainsString('unseated', $findings[0]->detail);
        $this->assertStringNotContainsString('hidden', $findings[0]->detail);
        $this->assertStringNotContainsString('elsewhere', $findings[0]->detail);
    }

    public function test_a_custom_provider_or_a_declared_binding_passes_with_the_count(): void
    {
        $this->declare('custom', BeamSchema::class, provider: AnsweringTierProvider::class);
        $this->declare('bound', BeamSchema::class, data: SummaryBoundTierData::class);
        // Unseated but opted in by an `overview` declaration: on the dashboard, declared tier.
        $this->declare('optin', BeamSchema::class, data: OverviewBoundTierData::class, section: null);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
        $this->assertStringContainsString('3 dashboard cards', $findings[0]->detail);
    }

    public function test_a_resource_whose_provider_cannot_answer_warns_as_absent_by_name(): void
    {
        // Default provider over a backing that only streams: declines before it is ever called.
        $this->declare('feed', TierFeed::class);
        // A custom provider that declines when asked.
        $this->declare('shy', BeamSchema::class, provider: DecliningTierProvider::class);
        // A custom provider that throws: a warning line, not a doctor that stops.
        $this->declare('broken', BeamSchema::class, provider: ThrowingTierProvider::class);
        // And one healthy card beside them, so the pass path and the warn path both have a population.
        $this->declare('custom', BeamSchema::class, provider: AnsweringTierProvider::class);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('ABSENT', $findings[0]->detail);
        $this->assertStringContainsString('[operator/feed] (default provider over a backing that cannot count)', $findings[0]->detail);
        $this->assertStringContainsString('[operator/shy] (custom provider declines)', $findings[0]->detail);
        $this->assertStringContainsString('[operator/broken] (custom provider threw RuntimeException)', $findings[0]->detail);
        $this->assertStringNotContainsString('[operator/custom]', $findings[0]->detail);
    }

    /**
     * The reference host's shape: `users`/`teams` declare NO `section:` and reach the rail as a seat's
     * static children. "Nav-seated" means "a leaf of the rail resolves to it", so they are on the
     * dashboard — and audited — exactly as a `section:` child is.
     */
    public function test_a_resource_reached_through_a_seats_static_child_is_on_the_dashboard(): void
    {
        $this->sections->register(
            new NavSection(
                key: 'operate', realm: 'operator', label: 'Operate', icon: 'Cog', href: '/operate', order: 5,
                entitlement: null, permission: null,
                static: [
                    ['title' => 'Users', 'href' => '/operator/users', 'routeName' => 'users.index'],
                    // A static naming no route joins by href.
                    ['title' => 'Teams', 'href' => '/operator/teams'],
                ],
            ),
            by: self::class,
        );
        $this->declare('users', BeamSchema::class, section: null);
        // No route name, no section: only an href match could seat it, and beam derives none here.
        $this->declare('teams', BeamSchema::class, section: null);
        $this->declare('loose', BeamSchema::class, section: null);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('[operator/users]', $findings[0]->detail);
        $this->assertStringNotContainsString('teams', $findings[0]->detail);
        $this->assertStringNotContainsString('loose', $findings[0]->detail);
    }

    public function test_derived_and_absent_are_reported_as_two_findings(): void
    {
        $this->declare('widgets', BeamSchema::class);
        $this->declare('feed', TierFeed::class);

        $findings = $this->audit()->run();

        $this->assertCount(2, $findings);
        $this->assertSame([DoctorStatus::Warn, DoctorStatus::Warn], array_column($findings, 'status'));
        $this->assertStringContainsString('[operator/widgets]', $findings[0]->detail);
        $this->assertStringContainsString('[operator/feed]', $findings[1]->detail);
    }
}

#[Summary(false)]
class OptedOutTierData extends Data
{
    public function __construct(public int $id = 0) {}
}

#[Summary('stat-row')]
class SummaryBoundTierData extends Data
{
    public function __construct(public int $id = 0) {}
}

#[Overview('figure-card')]
class OverviewBoundTierData extends Data
{
    public function __construct(public int $id = 0) {}
}

class TierFeed implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class AnsweringTierProvider implements ResourceSummaryProvider
{
    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        return new SummaryResponseData(
            key: $resource->key,
            label: $resource->nav->label,
            icon: null,
            figures: [new SummaryFigureData(key: 'open', label: 'Open', value: 1)],
            overview: null,
        );
    }
}

class DecliningTierProvider implements ResourceSummaryProvider
{
    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        return null;
    }
}

class ThrowingTierProvider implements ResourceSummaryProvider
{
    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        throw new \RuntimeException('no table');
    }
}
