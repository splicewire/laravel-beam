<?php

namespace Splicewire\Beam\Tests\Dashboard;

use Splicewire\Beam\Dashboard\SectionOverview;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Tests\TestCase;

class SectionOverviewTest extends TestCase
{
    private function seat(string $key, string $realm = 'operator', bool $overview = false, string $href = '/operator/reports'): NavSection
    {
        return new NavSection(
            key: $key, realm: $realm, label: ucfirst($key), icon: 'Box', href: $href, order: 10,
            entitlement: null, permission: null, audience: NavAudience::Product, overview: $overview,
        );
    }

    public function test_a_seat_has_no_overview_unless_it_asks(): void
    {
        $this->assertFalse($this->seat('reports')->overview);
        $this->assertTrue($this->seat('reports', overview: true)->overview);
    }

    public function test_the_grammar_carries_the_realm_and_the_section(): void
    {
        $this->assertSame('operator-reports-overview', SectionOverview::keyFor('operator', 'reports'));
        $this->assertSame('operator-reports-overview.index', SectionOverview::routeNameFor('operator', 'reports'));
        $this->assertSame('section-overview.view.operator.reports', SectionOverview::abilityFor('operator', 'reports'));
        $this->assertNotSame(SectionOverview::keyFor('operator', 'reports'), SectionOverview::keyFor('tenant', 'reports'));
    }

    public function test_only_a_seat_flagged_in_that_realm_makes_the_key_an_overview(): void
    {
        $sections = (new NavSectionRegistry)
            ->register($this->seat('reports', overview: true))
            ->register($this->seat('people'))
            ->register($this->seat('tenant-only', realm: 'tenant', overview: true));

        $this->assertTrue(SectionOverview::isKey('operator-reports-overview', 'operator', $sections));
        $this->assertFalse(SectionOverview::isKey('operator-people-overview', 'operator', $sections));
        $this->assertFalse(SectionOverview::isKey('operator-tenant-only-overview', 'operator', $sections));
        $this->assertSame(['reports'], array_map(fn (NavSection $s): string => $s->key, SectionOverview::flagged('operator', $sections)));
    }

    public function test_the_path_is_the_seats_href_relative_to_the_realm(): void
    {
        $this->assertSame('reports', SectionOverview::pathFor($this->seat('reports'), '/operator'));
        $this->assertSame('reports/all', SectionOverview::pathFor($this->seat('reports', href: '/operator/reports/all'), 'operator'));
        $this->assertSame('calendars', SectionOverview::pathFor($this->seat('calendars', href: '/calendars'), '/operator'));
        $this->assertSame('operators', SectionOverview::pathFor($this->seat('x', href: '/operators'), '/operator'), 'a prefix is matched by segment');
        $this->assertSame('reports', SectionOverview::pathFor($this->seat('reports', href: '/reports'), '/'));
    }
}
