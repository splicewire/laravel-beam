<?php

namespace Splicewire\Beam\Tests\Nav;

use Splicewire\Beam\Dashboard\SectionOverview;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * Charter ruling 2026-11-09, A1: same-key seats merge with their overview flag. A package owns whether its section has an
 * overview, and an extension only adds rows, so the merged seat is flagged when any declaration flags it, whichever seat
 * supplies the header and whatever order they were registered in.
 */
class NavSectionOverviewMergeTest extends TestCase
{
    private function seat(int $order, bool $overview, string $row): NavSection
    {
        return new NavSection(
            key: 'reports', realm: 'operator', label: 'Reports '.$order, icon: 'ChartBar', href: '/operator/reports', order: $order,
            entitlement: null, permission: null, audience: NavAudience::Product, static: [['title' => $row, 'href' => '/operator/'.$row]],
            overview: $overview,
        );
    }

    private function merged(NavSection ...$seats): NavSection
    {
        $registry = new NavSectionRegistry;
        foreach ($seats as $i => $seat) {
            $registry->register($seat, by: 'declarer-'.$i);
        }

        $sections = $registry->for('operator');
        $this->assertCount(1, $sections, 'same-key seats with the same gates are one seat');

        return $sections[0];
    }

    public function test_a_flagged_package_seat_extended_by_an_unflagged_one_keeps_its_overview_in_either_registration_order(): void
    {
        $package = $this->seat(20, true, 'package-row');
        $extension = $this->seat(30, false, 'extension-row');

        foreach ([[$package, $extension], [$extension, $package]] as $order) {
            $seat = $this->merged(...$order);

            $this->assertTrue($seat->overview);
            $this->assertSame('Reports 20', $seat->label, 'the lower order still supplies the header');
            $this->assertSame(['package-row', 'extension-row'], array_column($seat->static, 'title'), 'the extension still adds its row');
        }
    }

    public function test_the_flag_survives_when_the_unflagged_seat_supplies_the_header(): void
    {
        $host = $this->seat(10, false, 'host-row');
        $package = $this->seat(20, true, 'package-row');

        foreach ([[$host, $package], [$package, $host]] as $order) {
            $seat = $this->merged(...$order);

            $this->assertTrue($seat->overview);
            $this->assertSame('Reports 10', $seat->label);
        }
    }

    public function test_both_declarations_flagged_stay_flagged(): void
    {
        $this->assertTrue($this->merged($this->seat(20, true, 'a'), $this->seat(30, true, 'b'))->overview);
    }

    public function test_no_declaration_flagged_stays_unflagged(): void
    {
        $this->assertFalse($this->merged($this->seat(20, false, 'a'), $this->seat(30, false, 'b'))->overview);
    }

    public function test_the_merged_seat_is_what_the_overview_grammar_reads_as_flagged(): void
    {
        $registry = (new NavSectionRegistry)
            ->register($this->seat(20, true, 'package-row'), by: 'acme/reports')
            ->register($this->seat(30, false, 'extension-row'), by: 'host');

        $this->assertSame(['reports'], array_map(fn (NavSection $s): string => $s->key, SectionOverview::flagged('operator', $registry)));
        $this->assertTrue(SectionOverview::isKey('operator-reports-overview', 'operator', $registry));
    }
}
